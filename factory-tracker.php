<?php
// Force error display
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
@error_reporting(E_ALL);

/*
Plugin Name: Factory Pollution Tracker
Description: Track and display nearby polluting factories and golf courses
Version: 1.0
Author: Reno Web Designer
Author URI: https://renowebdesigner.com/
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// AJAX handler registration and function at the very top to ensure always loaded
add_action('wp_ajax_cra_cities', 'cra_ajax_search_cities');
add_action('wp_ajax_nopriv_cra_cities', 'cra_ajax_search_cities');

function cra_ajax_search_cities() {
    header('Content-Type: text/plain');
    echo 'HANDLER CALLED';
    exit;
}

// Register Custom Post Type
function factory_tracker_register_cpt() {
    register_post_type('facility', array(
        'public' => true,
        'label'  => 'Facilities',
        'supports' => array('title', 'editor', 'thumbnail', 'comments'),
        'menu_icon' => 'dashicons-location',
        'has_archive' => true,
        'publicly_queryable' => true,
        'show_in_rest' => true,
        'rewrite' => array('slug' => 'facilities', 'with_front' => false)
    ));
}

// Plugin Activation
function factory_tracker_activate() {
    factory_tracker_register_cpt();
    factory_tracker_update_db();
    flush_rewrite_rules();
}

// Database Updates
function factory_tracker_update_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Create facility meta table with correct structure
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}facility_meta (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        facility_id bigint(20) NOT NULL,
        is_flagged tinyint(1) DEFAULT 0,
        admin_note text,
        PRIMARY KEY  (id),
        KEY facility_id (facility_id)
    ) $charset_collate;";

    // Create reviews table with correct structure
    $reviews_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}facility_reviews (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        facility_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        rating int NOT NULL,
        review_text text NOT NULL,
        review_date datetime DEFAULT CURRENT_TIMESTAMP,
        status varchar(20) DEFAULT 'approved',
        helpful_votes int DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY facility_user (facility_id, user_id),
        KEY facility_id (facility_id),
        KEY user_id (user_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Create or update tables
    dbDelta($sql);
    dbDelta($reviews_table);
}

// Plugin Deactivation
function factory_tracker_deactivate() {
    flush_rewrite_rules();
}

// Initialize Plugin
function factory_tracker_init() {
    factory_tracker_register_cpt();
}

// Enqueue Scripts
function factory_tracker_enqueue_scripts() {
    if (!is_admin()) {
        // Enqueue custom purged Tailwind CSS (replace CDN)
        wp_enqueue_style(
            'tailwind-custom',
            plugins_url('css/tailwind-build.css', __FILE__),
            array(),
            '1.0'
        );

        // Enqueue jQuery
        wp_enqueue_script('jquery');
        
        // Enqueue Leaflet CSS and JS
        wp_enqueue_style(
            'leaflet-css',
            'https://unpkg.com/leaflet@1.9.3/dist/leaflet.css',
            [],
            '1.9.3'
        );
        wp_enqueue_script(
            'leaflet-js',
            'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js',
            [],
            '1.9.3',
            true
        );

        // Enqueue our custom scripts
        if (is_singular('facility')) {
            // Enqueue Swiper for facility page
            wp_enqueue_style(
                'swiper-css',
                'https://unpkg.com/swiper/swiper-bundle.min.css',
                [],
                '8.4.7'
            );
            wp_enqueue_script(
                'swiper-js',
                'https://unpkg.com/swiper/swiper-bundle.min.js',
                [],
                '8.4.7',
                true
            );

            // Enqueue facility page script
            wp_enqueue_script(
                'facility-page',
                plugins_url('js/facility-page.js', __FILE__),
                ['jquery', 'swiper-js', 'leaflet-js'],
                '1.0',
                true
            );

            // Localize script with facility data
            wp_localize_script('facility-page', 'facilityData', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('facility_update')
            ]);
        }

        // Enqueue scripts for search results page
        if (is_page('facility-results') || is_page('search-facilities')) {
            wp_enqueue_script(
                'facility-search',
                plugins_url('js/facility-search.js', __FILE__),
                ['jquery', 'leaflet-js'],
                '1.0',
                true
            );

            // Get current page number for pagination
            $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
            
            // Localize script with search data
            wp_localize_script('facility-search', 'facilitySearchData', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('facility_search'),
                'posts_per_page' => 12,
                'current_page' => $paged,
                'search_lat' => isset($_GET['lat']) ? floatval($_GET['lat']) : null,
                'search_lng' => isset($_GET['lng']) ? floatval($_GET['lng']) : null,
                'search_radius' => isset($_GET['radius']) ? intval($_GET['radius']) : 25
            ]);
        }
    }
}

// Remove any duplicate enqueue_scripts actions
remove_action('wp_enqueue_scripts', 'factory_tracker_enqueue_scripts');
add_action('wp_enqueue_scripts', 'factory_tracker_enqueue_scripts');

// Search Form Shortcode
function factory_tracker_search_form() {
    // 1) Retrieve & sanitize query vars
    $location = isset( $_GET['location'] ) ? sanitize_text_field( $_GET['location'] ) : '';
    $radius   = isset( $_GET['radius']   ) ? floatval( $_GET['radius'] )           : 25;
    $lat      = isset( $_GET['lat'] ) ? floatval( $_GET['lat'] ) : '';
    $lng      = isset( $_GET['lng'] ) ? floatval( $_GET['lng'] ) : '';

    // 2) Build the form action URL
    $action_url = esc_url( home_url( '/facility-results/' ) );

    // 3) Assemble the HTML
    $html  = '<div class="max-w-xl mx-auto p-4">';
    $html .=   '<form method="get" action="' . $action_url . '" style="display:flex;align-items:center;gap:1em;">';
    $html .=     '<label for="location" style="color:#ccc;">Location:</label>';
    $html .=     '<input '
            . 'type="text" '
            . 'name="location" '
            . 'id="location" '
            . 'placeholder="e.g. New York" '
            . 'value="' . esc_attr( $location ) . '" '
            . 'required style="padding:6px 10px; border-radius:4px; border:1px solid #ccc;"';
    $html .=     '>';
    $html .=     '<input type="hidden" name="lat" id="lat" value="' . esc_attr($lat) . '">';
    $html .=     '<input type="hidden" name="lng" id="lng" value="' . esc_attr($lng) . '">';
    $html .=     '<label for="radius" style="color:#ccc;">Radius (miles):</label>';
    $html .=     '<input '
            . 'type="number" '
            . 'name="radius" '
            . 'id="radius" '
            . 'min="1" max="500" step="1" '
            . 'value="' . esc_attr( $radius ) . '" style="width:60px;padding:6px 10px; border-radius:4px; border:1px solid #ccc;"';
    $html .=     '>';
    $html .=     '<button type="submit" style="padding:7px 18px; background:#2563eb; color:#fff; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">Search</button>';
    $html .=   '</form>';
    $html .= '</div>';

    return $html;
}
add_shortcode( 'facility_search_form', 'factory_tracker_search_form' );

add_shortcode('factory_results', function() {
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 15; // Show 15 per page

    if (empty($_GET['location']) && (empty($_GET['lat']) || empty($_GET['lng']))) {
        return '<p class="text-red-500">Please enter a location to search.</p>';
    }

    $lat = !empty($_GET['lat']) ? floatval($_GET['lat']) : null;
    $lng = !empty($_GET['lng']) ? floatval($_GET['lng']) : null;
    $location = sanitize_text_field($_GET['location']);
    $radius = isset($_GET['radius']) ? intval($_GET['radius']) : 30;

    // If we don't have coordinates, geocode the location
    if (!$lat || !$lng) {
        $geo_url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $location,
            'format' => 'json',
            'limit' => 1
        ]);

        $geo_response = wp_remote_get($geo_url);
        if (is_wp_error($geo_response)) {
            return '<p class="text-red-500">Error processing location. Please try again.</p>';
        }

        $geo_data = json_decode(wp_remote_retrieve_body($geo_response));
        if (!$geo_data || count($geo_data) === 0) {
            return '<p class="text-red-500">Could not find the specified location. Please try again.</p>';
        }

        $lat = $geo_data[0]->lat;
        $lng = $geo_data[0]->lon;
    }

    // Search for facilities with pagination
    $result = search_facilities($lat, $lng, $radius, $per_page, $paged);
    $facilities = $result['facilities']; // Only paginated results
    $total_facilities = $result['total'];

    if (function_exists('facility_save_search_results')) {
        facility_save_search_results($facilities);
    }

    // Generate output
    ob_start();
    ?>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Health Warning</h3>
                    <p class="mt-2 text-sm text-red-700">
                        The facilities shown below may release toxic pollutants linked to serious health conditions including cancer, 
                        dementia, lung disease, and kidney disease. This information is provided for awareness - please consult health 
                        professionals for medical advice.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                Environmental Hazards Near <?php echo esc_html($location); ?>
            </h1>
            <p class="text-gray-600">
                Found <?php echo $total_facilities; ?> facilities within <?php echo $radius; ?> miles
            </p>
        </div>

        <div id="facility-results-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($facilities as $facility): ?>
            <div class="facility-card bg-white rounded-lg shadow-md overflow-hidden" 
                 data-lat="<?php echo esc_attr($facility['lat']); ?>"
                 data-lng="<?php echo esc_attr($facility['lng']); ?>"
                 data-name="<?php echo esc_attr($facility['name']); ?>"
                 data-address="<?php echo esc_attr($facility['address']); ?>"
                 data-type="<?php echo esc_attr($facility['type']); ?>"
                 data-place-id="<?php echo esc_attr($facility['place_id']); ?>">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-900"><?php echo esc_html($facility['name']); ?></h3>
                        <span class="px-3 py-1 rounded-full text-sm font-medium <?php echo $facility['type'] === 'golf' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php echo $facility['type'] === 'golf' ? 'Golf Course' : 'Industrial Facility'; ?>
                        </span>
                    </div>
                    <?php if (!empty($facility['pollutants'])): ?>
                    <div class="mb-4">
                        <p class="text-gray-700 font-medium">Top Pollutants:</p>
                        <ul class="list-disc list-inside text-sm text-gray-600">
                            <?php foreach (array_slice($facility['pollutants'], 0, 3) as $pollutant): ?>
                                <li><?php echo esc_html($pollutant['name']); ?>: <?php echo esc_html($pollutant['amount']); ?> lbs/year</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(get_facility_profile_url($facility['place_id'])); ?>" class="block w-full text-center py-2 px-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        View Full Details
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_facilities > $per_page * $paged): ?>
        <div class="text-center mt-8">
            <button id="load-more-facilities" 
                    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                    data-current-page="<?php echo esc_attr($paged); ?>"
                    data-per-page="<?php echo esc_attr($per_page); ?>"
                    data-total-facilities="<?php echo esc_attr($total_facilities); ?>">
                Load More Facilities (<?php echo esc_html($total_facilities - ($per_page * $paged)); ?> remaining)
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php
    $output = ob_get_clean();

    // Pass data to JavaScript
    $search_data = array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('facility_search_nonce'),
        'searchLat' => $lat,
        'searchLng' => $lng,
        'searchRadius' => $radius,
        'currentPage' => $paged,
        'perPage' => $per_page,
        'totalFacilities' => $total_facilities,
        'currentUrl' => home_url(add_query_arg(null, null)),
        'facilityMarkers' => array_map(function($f) {
            return [
                'lat' => floatval($f['lat']),
                'lng' => floatval($f['lng']),
                'name' => $f['name'],
                'address' => $f['address'],
                'type' => $f['type'],
                'place_id' => $f['place_id']
            ];
        }, $facilities)
    );
    wp_localize_script('factory-tracker-search', 'facilitySearchData', $search_data);

    return $output;
});

// Update the load_facilities_ajax function to return the correct HTML and remaining count
add_action('wp_ajax_load_facilities_ajax', 'load_facilities_ajax');
add_action('wp_ajax_nopriv_load_facilities_ajax', 'load_facilities_ajax');

function load_facilities_ajax() {
    check_ajax_referer('facility_search_nonce', 'nonce');

    $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
    $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : null;
    $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : null;
    $radius = isset($_POST['radius']) ? intval($_POST['radius']) : 30;

    if (!$lat || !$lng) {
        wp_send_json_error(['message' => 'Latitude and longitude are required for facility search. Please select a city from the dropdown.']);
    }

    $per_page = ($paged === 1) ? 15 : 1000;
    if ($per_page > 10000) $per_page = 10000; // Enforce max limit

    $cache_key = 'facilities_' . md5(json_encode([$lat, $lng, $radius, $paged, $per_page]));
    if (false !== ($cached = get_transient($cache_key))) {
        wp_send_json_success($cached);
    }

    $result = search_facilities($lat, $lng, $radius, $per_page, $paged);
    set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
    wp_send_json_success($result);
}

// Register Hooks
register_activation_hook(__FILE__, 'factory_tracker_activate');
register_deactivation_hook(__FILE__, 'factory_tracker_deactivate');
add_action('init', 'factory_tracker_init');

// Enqueue Leaflet CSS and JS on search results page
function factory_tracker_enqueue_leaflet() {
    if (is_page('facility-results') || is_page('search-facilities')) {
        wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.3/dist/leaflet.css', [], '1.9.3');
        wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js', [], '1.9.3', true);
        
        // Enqueue Leaflet Geocoder CSS and JS
        wp_enqueue_style('leaflet-geocoder-css', 'https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css', [], '1.13.0');
        wp_enqueue_script('leaflet-geocoder-js', 'https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js', ['leaflet-js'], '1.13.0', true);
    }
}
add_action('wp_enqueue_scripts', 'factory_tracker_enqueue_leaflet');
add_action('wp_enqueue_scripts', 'factory_tracker_enqueue_scripts');
add_shortcode('factory_search', 'factory_tracker_search_form');
add_shortcode('factory_results', 'factory_tracker_results');

// Add these constants at the top of the file after the plugin header
define('CLEARBIT_API_KEY', 'YOUR_CLEARBIT_API_KEY'); // Replace with actual key
define('OPENCORPORATES_API_KEY', 'YOUR_OPENCORPORATES_API_KEY'); // Replace with actual key

// Badge System Constants
define('BADGE_CLEAN_ACTION', 'clean_action_verified');
define('BADGE_REFORMING', 'reforming_in_progress');
define('BADGE_ADOPTED', 'clean_practices_adopted');

// Register activation hook to create badge tables
register_activation_hook(__FILE__, function() {
    global $wpdb;
    
    // Create badges table
    $badges_table = $wpdb->prefix . 'facility_badges';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $badges_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        facility_id bigint(20) NOT NULL,
        badge_type varchar(50) NOT NULL,
        verification_date datetime DEFAULT CURRENT_TIMESTAMP,
        verification_notes text,
        evidence_url text,
        last_reviewed datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY facility_id (facility_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Create reviews table
    $reviews_table = $wpdb->prefix . 'facility_reviews';
    $sql = "CREATE TABLE IF NOT EXISTS $reviews_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        facility_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        rating int NOT NULL,
        review_text text NOT NULL,
        review_date datetime DEFAULT CURRENT_TIMESTAMP,
        status varchar(20) DEFAULT 'pending',
        helpful_votes int DEFAULT 0,
        PRIMARY KEY  (id),
        KEY facility_id (facility_id),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    dbDelta($sql);
});

// Helper function to get badge information
function get_badge_info($badge_type) {
    $current_year = date('Y');
    $badges = [
        BADGE_CLEAN_ACTION => [
            'name' => 'Clean Action Verified',
            'emoji' => '🟢',
            'description' => "Verified emissions reduction and transparent environmental practices. Certified {$current_year}.",
            'color' => 'green',
            'requirements' => [
                'Documented 50%+ emissions reduction',
                'Regular environmental audits',
                'Public pollution reporting',
                'No EPA violations in 12+ months'
            ],
            'icon' => 'leaf-shield',
            'tier' => 'gold'
        ],
        BADGE_REFORMING => [
            'name' => 'Reforming in Progress',
            'emoji' => '🟡',
            'description' => "Actively implementing pollution reduction measures. Progress tracked since {$current_year}.",
            'color' => 'yellow',
            'requirements' => [
                'Bioremediation plan in place',
                'Emissions reduction targets set',
                'Monthly progress reports',
                'Working with environmental consultants'
            ],
            'icon' => 'gear-plant',
            'tier' => 'silver'
        ],
        BADGE_ADOPTED => [
            'name' => 'Clean Practices Adopted',
            'emoji' => '🟠',
            'description' => "Initial steps taken toward environmental responsibility. Started {$current_year}.",
            'color' => 'orange',
            'requirements' => [
                'Environmental impact assessment',
                'Staff training on clean practices',
                'Basic pollution controls installed',
                'Commitment to improvement plan'
            ],
            'icon' => 'seedling-gear',
            'tier' => 'bronze'
        ]
    ];
    
    return $badges[$badge_type] ?? null;
}

// Add badge directory page shortcode
add_shortcode('badge_directory', function() {
    ob_start();
    ?>
    <div class="max-w-7xl mx-auto px-4 py-12">
        <div class="text-center mb-16">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Clean Recovery Act Badge Program</h1>
            <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                Recognizing and incentivizing real environmental progress through verified actions and transparent reporting.
            </p>
        </div>

        <!-- Badge Program Overview -->
        <div class="bg-white rounded-2xl shadow-xl p-8 mb-16">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Why The Badge Matters</h2>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="h-6 w-6 text-green-500 mt-1 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                <h3 class="font-semibold text-gray-900">Reputation Rehabilitation</h3>
                                <p class="text-gray-600">A verified path for companies to demonstrate real environmental progress.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="h-6 w-6 text-green-500 mt-1 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            <div>
                                <h3 class="font-semibold text-gray-900">Action Incentives</h3>
                                <p class="text-gray-600">Clear steps and rewards for implementing pollution reduction measures.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <svg class="h-6 w-6 text-green-500 mt-1 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            <div>
                                <h3 class="font-semibold text-gray-900">Verified Progress</h3>
                                <p class="text-gray-600">Documented and traceable environmental improvements, not just PR claims.</p>
                            </div>
                        </li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">How to Qualify</h2>
                    <div class="prose text-gray-600">
                        <p>Companies can earn badges through verified environmental actions:</p>
                        <ul>
                            <li>Documented emissions reduction</li>
                            <li>Implementation of clean technologies</li>
                            <li>Regular environmental audits</li>
                            <li>Public pollution reporting</li>
                            <li>Staff environmental training</li>
                            <li>Community engagement</li>
                        </ul>
                        <p class="mt-4 text-sm">
                            <strong>Note:</strong> All claims must be verified by independent environmental auditors.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Badge Tiers -->
        <h2 class="text-3xl font-bold text-gray-900 text-center mb-8">Badge Tiers & Requirements</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-16">
            <?php
            $badge_types = [BADGE_CLEAN_ACTION, BADGE_REFORMING, BADGE_ADOPTED];
            foreach ($badge_types as $type):
                $badge = get_badge_info($type);
            ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-<?php echo $badge['color']; ?>-50 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-4xl"><?php echo $badge['emoji']; ?></span>
                        <span class="px-3 py-1 rounded-full text-sm font-medium bg-<?php echo $badge['color']; ?>-100 text-<?php echo $badge['color']; ?>-800">
                            <?php echo ucfirst($badge['tier']); ?> Tier
                        </span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2"><?php echo $badge['name']; ?></h3>
                    <p class="text-gray-600"><?php echo $badge['description']; ?></p>
                </div>
                <div class="p-6">
                    <h4 class="font-semibold text-gray-900 mb-4">Requirements:</h4>
                    <ul class="space-y-3">
                        <?php foreach ($badge['requirements'] as $req): ?>
                        <li class="flex items-start">
                            <svg class="h-5 w-5 text-<?php echo $badge['color']; ?>-500 mt-1 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="text-gray-600"><?php echo $req; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Verified Companies -->
        <div class="bg-white rounded-xl shadow-lg p-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Verified Companies</h2>
            <?php
            global $wpdb;
            $badges_table = $wpdb->prefix . 'facility_badges';
            $facilities = $wpdb->get_results("
                SELECT f.*, b.badge_type, b.verification_date, b.verification_notes, b.evidence_url
                FROM {$wpdb->posts} f
                JOIN $badges_table b ON f.ID = b.facility_id
                WHERE f.post_type = 'facility'
                AND f.post_status = 'publish'
                ORDER BY b.verification_date DESC
            ");

            if ($facilities): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($facilities as $facility):
                    $facility_meta = get_post_meta($facility->ID, 'facility_data', true);
                    $badge = get_badge_info($facility->badge_type);
                ?>
                <div class="border rounded-lg overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-2xl"><?php echo $badge['emoji']; ?></span>
                            <span class="px-3 py-1 rounded-full text-sm font-medium bg-<?php echo $badge['color']; ?>-100 text-<?php echo $badge['color']; ?>-800">
                                <?php echo $badge['name']; ?>
                            </span>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2"><?php echo get_the_title($facility->ID); ?></h3>
                        <p class="text-gray-600 mb-4"><?php echo $facility_meta['address']; ?></p>
                        <?php if ($facility->verification_notes): ?>
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700">Verification Notes:</h4>
                            <p class="text-sm text-gray-600"><?php echo nl2br(esc_html($facility->verification_notes)); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($facility->evidence_url): ?>
                        <a href="<?php echo esc_url($facility->evidence_url); ?>" 
                           target="_blank"
                           class="text-blue-600 hover:text-blue-800 text-sm">
                            View Evidence Documentation →
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo get_permalink($facility->ID); ?>" 
                           class="mt-4 block w-full text-center py-2 px-4 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">
                            View Full Profile
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12 bg-gray-50 rounded-lg">
                <p class="text-gray-600">No verified facilities yet. Check back soon!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

// Helper function to calculate distance between coordinates
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    return $miles;
}

// Register Custom Post Type for Facilities
add_action('init', function() {
    register_post_type('facility', [
        'labels' => [
            'name' => 'Facilities',
            'singular_name' => 'Facility',
            'edit_item' => 'Edit Facility',
            'view_item' => 'View Facility',
            'search_items' => 'Search Facilities',
            'not_found' => 'No facilities found',
            'not_found_in_trash' => 'No facilities found in trash',
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-building',
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'rewrite' => [
            'slug' => 'facilities',
            'with_front' => false
        ],
        'show_in_rest' => true,
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ]);

    // Register custom taxonomy for facility types
    register_taxonomy('facility_type', 'facility', [
        'labels' => [
            'name' => 'Facility Types',
            'singular_name' => 'Facility Type',
        ],
        'hierarchical' => true,
        'public' => true,
        'show_admin_column' => true,
    ]);
});

// Update the search form shortcode
add_shortcode('factory_search_form', function() {
    ob_start();
    ?>
    <div class="relative min-h-[600px] bg-gradient-to-br from-blue-900 to-gray-900 flex items-center justify-center p-4">
        <!-- Background pattern -->
        <div class="absolute inset-0 bg-grid-white/[0.05] bg-[size:40px_40px]"></div>
        
        <!-- Content -->
        <div class="relative max-w-4xl w-full mx-auto px-4 py-8 sm:px-6 lg:px-8">
            <div class="text-center mb-8">
                <h1 class="text-3xl sm:text-5xl font-bold text-white mb-4">
                    Find Polluters Near You
                </h1>
                <p class="text-lg sm:text-xl text-gray-300 mb-4">
                    Discover environmental impacts in your area
                </p>
                <div class="max-w-2xl mx-auto">
                    <p class="text-sm text-red-300 mb-8">
                        Warning: Many industrial pollutants are linked to serious health conditions including cancer, 
                        dementia, lung disease, kidney disease, and other chronic illnesses. Stay informed about environmental 
                        hazards in your community.
                    </p>
                </div>
            </div>

            <div class="bg-white/10 backdrop-blur-lg rounded-2xl p-4 sm:p-8 shadow-2xl">
              <form method="GET" action="<?php echo esc_url( home_url( '/facility-results/' ) ); ?>" class="space-y-5">
    
    <!-- Location Field -->
    <div class="relative" style="position: relative;" bis_skin_checked="1">
      <input type="text" name="location" id="location" placeholder="Enter your address, city, or region" value="" required="" class="w-full px-5 py-3.5 rounded-xl bg-white text-gray-800 placeholder-gray-500 border border-gray-200 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" style="
    padding: 10px;
    border: 1px solid;
">
      <div class="absolute inset-y-0 right-4 flex items-center text-gray-400" bis_skin_checked="1">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
        </svg>
      </div>
    <div style="position: absolute; top: 100%; left: 0px; right: 0px; background: rgb(255, 255, 255); border: 1px solid rgb(204, 204, 204); max-height: 200px; overflow-y: auto; z-index: 1000; display: none;" bis_skin_checked="1"></div></div>

    <!-- Radius & Button Row -->
    <div class="flex flex-col sm:flex-row sm:items-end gap-4" bis_skin_checked="1">
      <!-- Radius -->
      <div class="flex-1" bis_skin_checked="1">
        <label for="radius" class="block text-sm font-medium text-white mb-1">Search Radius</label>
        <select name="radius" id="radius" class="w-full px-4 py-3.5 rounded-xl bg-white text-gray-800 border border-gray-200 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="10">10 miles</option>
          <option value="25">25 miles</option>
          <option value="50">50 miles</option>
          <option value="100">100 miles</option>
        </select>
      </div>

      <!-- Button -->
      <div class="flex-none sm:mb-[2px]" bis_skin_checked="1">
        <button type="submit" class="w-full sm:w-auto px-6 py-3.5 bg-gradient-to-r from-blue-600 to-blue-500 text-white font-semibold rounded-xl shadow-md hover:from-blue-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-400 transition-all">
          Find Polluters Near Me
        </button>
      </div>
    </div>

  <input type="hidden" name="lat" id="lat" value=""><input type="hidden" name="lng" id="lng" value=""></form>

            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

// Results processing
add_shortcode('factory_results', function() {
    if (empty($_GET['location']) && (empty($_GET['lat']) || empty($_GET['lng']))) {
        return '<p class="text-red-500">Please enter a location to search.</p>';
    }

    $lat = !empty($_GET['lat']) ? floatval($_GET['lat']) : null;
    $lng = !empty($_GET['lng']) ? floatval($_GET['lng']) : null;
    $location = sanitize_text_field($_GET['location']);
    $radius = isset($_GET['radius']) ? intval($_GET['radius']) : 30;

    // If we don't have coordinates, geocode the location
    if (!$lat || !$lng) {
        $geo_url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $location,
            'key' => 'AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c'
        ]);

        $geo_response = wp_remote_get($geo_url);
        if (is_wp_error($geo_response)) {
            return '<p class="text-red-500">Error processing location. Please try again.</p>';
        }

        $geo_data = json_decode(wp_remote_retrieve_body($geo_response));
        if (!$geo_data || $geo_data->status !== 'OK') {
            return '<p class="text-red-500">Could not find the specified location. Please try again.</p>';
        }

        $lat = $geo_data->results[0]->geometry->location->lat;
        $lng = $geo_data->results[0]->geometry->location->lng;
    }

    // Search for facilities
    $facilities = search_facilities($lat, $lng, $radius);
    if (function_exists('facility_save_search_results')) {
        facility_save_search_results($facilities);
    }

    // Generate output
    ob_start();
    ?>
    <div class="max-w-7xl mx-auto px-4 py-8">
       

        <div class="bg-gray-50 border-l-4 border-red-500 p-4 mb-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Health Warning</h3>
                    <p class="mt-2 text-sm text-red-700">
                        The facilities shown below may release toxic pollutants linked to serious health conditions including cancer, 
                        dementia, lung disease, and kidney disease. This information is provided for awareness - please consult health 
                        professionals for medical advice.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                Environmental Hazards Near <?php echo esc_html($location); ?>
            </h1>
            <p class="text-gray-600">
                Found <?php echo count($facilities); ?> facilities within <?php echo $radius; ?> miles
            </p>
        </div>

        <div id="facility-results-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($facilities as $facility): ?>
            <div class="facility-card bg-white rounded-lg shadow-md overflow-hidden" 
                 data-lat="<?php echo esc_attr($facility['lat']); ?>"
                 data-lng="<?php echo esc_attr($facility['lng']); ?>"
                 data-name="<?php echo esc_attr($facility['name']); ?>"
                 data-address="<?php echo esc_attr($facility['address']); ?>"
                 data-type="<?php echo esc_attr($facility['type']); ?>"
                 data-place-id="<?php echo esc_attr($facility['place_id']); ?>">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-900"><?php echo esc_html($facility['name']); ?></h3>
                        <span class="px-3 py-1 rounded-full text-sm font-medium <?php echo $facility['type'] === 'golf' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php echo $facility['type'] === 'golf' ? 'Golf Course' : 'Industrial Facility'; ?>
                        </span>
                    </div>

                    <p class="text-gray-600 mb-4"><?php echo esc_html($facility['address']); ?></p>
                    
                    <div class="mb-4">
                        <p class="text-sm font-medium text-gray-500">Distance</p>
                        <p class="text-lg font-semibold text-gray-900">
                            <?php echo number_format($facility['distance'], 1); ?> miles away
                        </p>
                    </div>

                    <div class="border-t border-gray-200 pt-4">
                        <h4 class="text-lg font-semibold text-red-600 mb-2">Known Pollutants</h4>
                        <div class="space-y-3">
                            <?php foreach ($facility['pollutants'] as $pollutant): ?>
                            <div class="bg-red-50 rounded p-3">
                                <p class="font-medium text-red-800"><?php echo esc_html($pollutant['name']); ?></p>
                                <p class="text-sm text-red-600">Amount: <?php echo esc_html($pollutant['amount']); ?></p>
                                <p class="text-sm text-red-700 mt-1">
                                    Health Effects: <?php echo esc_html($pollutant['health_effects']); ?>
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-4">
                        <a href="<?php echo esc_url(get_facility_profile_url($facility['place_id'])); ?>" 
                           class="block w-full text-center py-2 px-4 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">
                            View Full Details
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($facilities)): ?>
        <div class="text-center py-12 bg-gray-50 rounded-lg">
            <h2 class="text-2xl font-semibold text-gray-900 mb-2">No Results Found</h2>
            <p class="text-gray-600">Try adjusting your search radius or try a different location.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php if (count($facilities) > 12): ?>
    <div class="text-center mt-8">
        <button id="load-more-facilities" 
                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                data-current-page="1"
                data-per-page="12"
                data-total-facilities="<?php echo esc_attr(count($facilities)); ?>">
            Load More Facilities (<?php echo esc_html(count($facilities) - 12); ?> remaining)
        </button>
    </div>
    <?php endif; ?>

    <script>
    // Initialize the global facilityMarkers array
    window.facilityMarkers = <?php echo json_encode(array_map(function($facility) {
        return [
            'lat' => floatval($facility['lat']),
            'lng' => floatval($facility['lng']),
            'name' => $facility['name'],
            'address' => $facility['address'],
            'type' => $facility['type'],
            'place_id' => $facility['place_id']
        ];
    }, $facilities)); ?>;

   // Initialize the map when the page loads
   function initMap() {
       const mapElement = document.getElementById('map');
       if (!mapElement) return;

       const map = new google.maps.Map(mapElement, {
           center: { lat: <?php echo $lat; ?>, lng: <?php echo $lng; ?> },
           zoom: 10,
           styles: [
               {
                   featureType: "poi",
                   elementType: "labels",
                   stylers: [{ visibility: "off" }]
               }
           ]
       });

       const bounds = new google.maps.LatLngBounds();
       const markers = [];
       let currentInfoWindow = null;

       window.facilityMarkers.forEach(facility => {
           const position = { 
               lat: parseFloat(facility.lat), 
               lng: parseFloat(facility.lng) 
           };
           
           const marker = new google.maps.Marker({
               position,
               map,
               title: facility.name,
               animation: google.maps.Animation.DROP,
               icon: {
                   url: facility.type === 'golf'
                       ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
                       : 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
               }
           });

           // Create InfoWindow content
           const infoWindow = new google.maps.InfoWindow({
               content: `
                   <div class="p-4">
                       <h3 class="font-bold text-lg mb-2">${facility.name}</h3>
                       <p class="mb-3">${facility.address}</p>
                       <a href="/facility-profile/${facility.place_id}/" 
                          class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                           View Full Details
                       </a>
                   </div>
               `
           });
           
           // Add click listener to marker
           marker.addListener('click', () => {
               if (currentInfoWindow) {
                   currentInfoWindow.close();
               }
               infoWindow.open(map, marker);
               currentInfoWindow = infoWindow;
           });

           bounds.extend(position);
           markers.push(marker);
       });

       if (markers.length > 0) {
           map.fitBounds(bounds);
           if (markers.length === 1) {
               map.setZoom(14);
           }
       }
   }
   </script>

    <?php
    return ob_get_clean();
});

// function calculate_distance($lat1, $lon1, $lat2, $lon2) {
//     $theta = $lon1 - $lon2;
//     $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
//     $dist = acos($dist);
//     $dist = rad2deg($dist);
//     $miles = $dist * 60 * 1.1515;
//     return $miles;
// }

// Function to create or update facility profile
function create_facility_profile($place_id, $type) {
    $facility_data = get_enhanced_facility_data($place_id);
    if (empty($facility_data)) {
        return false;
    }

    // Check if facility already exists
    $existing = get_posts([
        'post_type' => 'facility',
        'meta_key' => 'place_id',
        'meta_value' => $place_id,
        'posts_per_page' => 1
    ]);

    if (!empty($existing)) {
        $post_id = $existing[0]->ID;
        // Update existing facility
        wp_update_post([
            'ID' => $post_id,
            'post_title' => $facility_data['name'],
            'post_content' => generate_facility_content($facility_data),
            'post_status' => 'publish'
        ]);
    } else {
        // Create new facility
        $post_id = wp_insert_post([
            'post_title' => $facility_data['name'],
            'post_type' => 'facility',
            'post_status' => 'publish',
            'post_content' => generate_facility_content($facility_data)
        ]);
    }

    if ($post_id) {
        update_post_meta($post_id, 'place_id', $place_id);
        update_post_meta($post_id, 'facility_data', $facility_data);
        update_post_meta($post_id, 'facility_type', $type);
        
        // Return the proper permalink
        return get_permalink($post_id);
    }

    return false;
}

// Function to generate facility content
function generate_facility_content($facility_data) {
    ob_start();
    ?>
    <div class="facility-content">
        <div class="facility-info">
            <h2>About <?php echo esc_html($facility_data['name']); ?></h2>
            <p><?php echo esc_html($facility_data['address']); ?></p>
            
            <?php if ($facility_data['type'] === 'golf'): ?>
                <h3>Environmental Impact</h3>
                <ul>
                    <li>Water Usage: <?php echo esc_html($facility_data['water_usage']); ?></li>
                    <li>Pesticide Use: <?php echo esc_html($facility_data['pesticide_use']); ?></li>
                </ul>
            <?php else: ?>
                <?php if (!empty($facility_data['environmental_impact'])): ?>
                <h3>Environmental Impact</h3>
                <ul>
                    <?php foreach ($facility_data['environmental_impact'] as $impact): ?>
                    <li><?php echo esc_html($impact); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($facility_data['schools_nearby'])): ?>
            <h3>Nearby Schools</h3>
            <ul>
                <?php foreach ($facility_data['schools_nearby'] as $school): ?>
                <li>
                    <?php echo esc_html($school['name']); ?> - 
                    <?php echo esc_html($school['distance']); ?> miles away
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Update the single template filter
add_filter('single_template', function($template) {
    global $post;
    
    if ($post && $post->post_type === 'facility') {
        $new_template = plugin_dir_path(__FILE__) . 'templates/single-facility.php';
        if (file_exists($new_template)) {
            return $new_template;
        }
    }
    
    return $template;
});

// Add shortcode for facility profiles
add_shortcode('facility_profile', function($atts) {
    global $wpdb;
    
    // Get facility ID from URL
    $facility_id = isset($_GET['facility_id']) ? intval($_GET['facility_id']) : 0;
    
    // If no facility ID provided, try to get it from attributes
    if (!$facility_id && isset($atts['id'])) {
        $facility_id = intval($atts['id']);
    }
    
    // Get facility type from attributes
    $type = isset($atts['type']) ? sanitize_text_field($atts['type']) : 'factory';
    
    // Query to find the post with matching facility data
    $query = $wpdb->prepare(
        "SELECT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = 'facility_data' 
        AND meta_value LIKE %s",
        '%"type":"' . $type . '"%'
    );
    
    if ($facility_id) {
        $query = $wpdb->prepare(
            "SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = 'facility_data' 
            AND post_id = %d",
            $facility_id
        );
    }
    
    $post_id = $wpdb->get_var($query);
    
    if (!$post_id) {
        return '<div class="max-w-7xl mx-auto px-4 py-8">
            <div class="bg-white rounded-xl shadow-lg p-8 text-center">
                <h2 class="text-2xl font-bold text-gray-800 mb-4">Facility Not Found</h2>
                <p class="text-gray-600">The requested facility could not be found. Please try searching again.</p>
                <a href="' . home_url('/') . '" class="inline-block mt-6 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Return to Search
                </a>
            </div>
        </div>';
    }

    $facility_data = get_post_meta($post_id, 'facility_data', true);
    if (!$facility_data) {
        return '<p>Facility data not found.</p>';
    }

    // Add the facility data to the page for the map
    wp_localize_script('maps-init', 'facilityMarkers', [
        [
            'lat' => $facility_data['lat'],
            'lng' => $facility_data['lng'],
            'name' => $facility_data['name'],
            'type' => $facility_data['type'],
            'address' => $facility_data['address'],
            'rating' => $facility_data['rating'] ?? null
        ]
    ]);

    ob_start();
    ?>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <?php if (!empty($facility_data['photo_url'])): ?>
            <div class="h-96 w-full relative">
                <img src="<?php echo esc_url($facility_data['photo_url']); ?>" 
                     alt="<?php echo esc_attr($facility_data['name']); ?>"
                     class="w-full h-full object-cover">
                <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-6">
                    <h1 class="text-4xl font-bold text-white"><?php echo esc_html($facility_data['name']); ?></h1>
                    <p class="text-gray-200 mt-2"><?php echo esc_html($facility_data['address']); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="p-6 bg-gray-50">
                <h1 class="text-4xl font-bold text-gray-900"><?php echo esc_html($facility_data['name']); ?></h1>
                <p class="text-gray-600 mt-2"><?php echo esc_html($facility_data['address']); ?></p>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 p-8">
                <div class="lg:col-span-2 space-y-8">
                    <!-- Overview Section -->
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Facility Overview</h2>
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Type</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    <?php echo $facility_data['type'] === 'golf' ? 'Golf Course' : 'Industrial Facility'; ?>
                                </p>
                            </div>
                            <?php if (!empty($facility_data['rating'])): ?>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Rating</h3>
                                <p class="mt-1 text-lg text-gray-900">
                                    <?php echo esc_html($facility_data['rating']); ?>/5
                                    (<?php echo esc_html($facility_data['reviews']); ?> reviews)
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Environmental Impact Section -->
                    <?php if ($facility_data['type'] === 'golf'): ?>
                    <div class="bg-green-50 rounded-lg p-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Environmental Impact</h2>
                        <div class="space-y-6">
                            <?php if (!empty($facility_data['water_usage']) && !empty($facility_data['pesticide_use'])): ?>
                            <div class="relative pt-1">
                                <label class="text-sm font-medium text-gray-500">Environmental Impact Score</label>
                                <?php
                                $water_usage = intval(str_replace(['gallons', ',', '/year', '(EPA estimate)'], '', $facility_data['water_usage']));
                                $pesticide_use = intval(str_replace(['lbs', ',', '/year', '(EPA estimate)'], '', $facility_data['pesticide_use']));
                                $water_score = min(50, $water_usage / 1000000);
                                $pesticide_score = min(50, $pesticide_use / 100);
                                $total_score = $water_score + $pesticide_score;
                                $score_color = $total_score < 30 ? 'bg-green-500' : ($total_score < 70 ? 'bg-yellow-500' : 'bg-red-500');
                                ?>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="text-3xl font-bold text-gray-700"><?php echo round($total_score); ?></span>
                                        <span class="text-sm text-gray-500">/100</span>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php
                                        if ($total_score < 30) echo 'Low Impact';
                                        else if ($total_score < 70) echo 'Moderate Impact';
                                        else echo 'High Impact';
                                        ?>
                                    </div>
                                </div>
                                <div class="overflow-hidden h-2 mt-2 text-xs flex rounded bg-gray-200">
                                    <div style="width:<?php echo $total_score; ?>%" 
                                         class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center <?php echo $score_color; ?>">
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="grid grid-cols-2 gap-6">
                                <?php if (!empty($facility_data['acres'])): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Course Size</h3>
                                    <p class="mt-1 text-gray-900"><?php echo esc_html($facility_data['acres']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($facility_data['holes'])): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Number of Holes</h3>
                                    <p class="mt-1 text-gray-900"><?php echo esc_html($facility_data['holes']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($facility_data['water_usage'])): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Annual Water Usage</h3>
                                    <p class="mt-1 text-gray-900"><?php echo esc_html($facility_data['water_usage']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($facility_data['pesticide_use'])): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Annual Pesticide Use</h3>
                                    <p class="mt-1 text-gray-900"><?php echo esc_html($facility_data['pesticide_use']); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-red-50 rounded-lg p-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Environmental Impact</h2>
                        <div class="space-y-6">
                            <?php if (!empty($facility_data['environmental_impact'])): ?>
                            <?php foreach ($facility_data['environmental_impact'] as $impact): ?>
                            <div class="border-l-4 border-red-400 pl-4">
                                <h3 class="text-lg font-semibold mb-2"><?php echo esc_html($impact['type']); ?></h3>
                                <p class="text-gray-700 mb-2"><?php echo nl2br(esc_html($impact['description'])); ?></p>
                                <p class="<?php echo match($impact['severity']) {
                                    'Critical' => 'text-red-700',
                                    'High' => 'text-red-600',
                                    'Moderate to High' => 'text-orange-600',
                                    default => 'text-yellow-600'
                                }; ?> font-medium">Impact Level: <?php echo esc_html($impact['severity']); ?></p>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($facility_data['regulation_type'])): ?>
                            <div class="mt-6">
                                <h3 class="text-lg font-semibold mb-2">Regulatory Framework</h3>
                                <p class="text-gray-700"><?php echo nl2br(esc_html($facility_data['regulation_type'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-8">
                    <!-- Map Section -->
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div id="map" class="h-64 w-full" 
                             data-lat="<?php echo esc_attr($facility_data['lat']); ?>" 
                             data-lng="<?php echo esc_attr($facility_data['lng']); ?>">
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <?php if (!empty($facility_data['phone']) || !empty($facility_data['website'])): ?>
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">Contact Information</h2>
                        <?php if (!empty($facility_data['phone'])): ?>
                        <div class="mb-4">
                            <h3 class="text-sm font-medium text-gray-500">Phone</h3>
                            <p class="mt-1 text-lg text-gray-900"><?php echo esc_html($facility_data['phone']); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($facility_data['website'])): ?>
                        <a href="<?php echo esc_url($facility_data['website']); ?>" 
                           target="_blank"
                           class="block w-full text-center py-3 px-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            Visit Website
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Community Discussion -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">Community Discussion</h2>
                        <?php comments_template(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

// Create maps-init.js file on plugin activation
register_activation_hook(__FILE__, function() {
    $js_dir = plugin_dir_path(__FILE__) . 'js';
    if (!file_exists($js_dir)) {
        mkdir($js_dir, 0755, true);
    }
    
    $js_content = <<<'EOT'
let map;
let autocomplete;
let markers = [];

// Main initialization function that will be called by Google Maps
function initializeMap() {
    initializeAutocomplete();
    initializeMapElement();
}

function initializeAutocomplete() {
    const input = document.getElementById('location-input');
    if (input) {
        autocomplete = new google.maps.places.Autocomplete(input);

        // Set options after creation to avoid the error
        autocomplete.setOptions({
            types: ['(cities)'],
            componentRestrictions: { country: 'us' }
        });

        autocomplete.addListener('place_changed', function() {
            const place = autocomplete.getPlace();
            if (place.geometry) {
                const lat = place.geometry.location.lat();
                const lng = place.geometry.location.lng();
                
                // Update hidden fields
                document.getElementById('lat').value = lat;
                document.getElementById('lng').value = lng;
                
                // If we have a map, update it
                if (map) {
                    map.setCenter({ lat, lng });
                    map.setZoom(12);
                }
            }
        });
    }
}

function initializeMapElement() {
    const mapElement = document.getElementById('map');
    if (mapElement) {
        // Get coordinates from data attributes
        const lat = parseFloat(mapElement.dataset.lat) || 0;
        const lng = parseFloat(mapElement.dataset.lng) || 0;
        
        // Initialize the map
        map = new google.maps.Map(mapElement, {
            center: { lat: lat, lng: lng },
            zoom: 12
        });

        // Add markers if they exist in the global scope
        if (window.facilityMarkers && window.facilityMarkers.length > 0) {
            const bounds = new google.maps.LatLngBounds();
            
            window.facilityMarkers.forEach(facility => {
                const position = {
                    lat: parseFloat(facility.lat),
                    lng: parseFloat(facility.lng)
                };
                
                const marker = new google.maps.Marker({
                    position,
                    map,
                    title: facility.name,
                    icon: {
                        url: facility.type === 'golf' 
                            ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
                            : 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
                    }
                });
                
                bounds.extend(position);
                markers.push(marker);
            });
            
            // Only fit bounds if we have markers
            if (markers.length > 0) {
                map.fitBounds(bounds);
                
                // If we only have one marker, zoom out a bit
                if (markers.length === 1) {
                    map.setZoom(14);
                }
            }
        }
    }
}

// Function to get current location
function getCurrentLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Update hidden fields
                document.getElementById('lat').value = lat;
                document.getElementById('lng').value = lng;
                
                // Use Geocoder to get address
                const geocoder = new google.maps.Geocoder();
                geocoder.geocode(
                    { location: { lat: lat, lng: lng } },
                    function(results, status) {
                        if (status === 'OK' && results[0]) {
                            document.getElementById('location-input').value = results[0].formatted_address;
                        }
                    }
                );
            },
            function(error) {
                console.error('Error getting location:', error);
                alert('Unable to get your location. Please enter it manually.');
            }
        );
    } else {
        alert('Geolocation is not supported by your browser. Please enter your location manually.');
    }
}

// Make getCurrentLocation available globally
window.getCurrentLocation = getCurrentLocation;
EOT;

    // Write maps-init.js into the plugin's /js folder
    file_put_contents($js_dir . '/maps-init.js', $js_content);
});

// Add rewrite rules for facility profiles with company name
add_action('init', function() {
    add_rewrite_rule(
        'facilities/([^/]+)/?$',
        'index.php?facility_slug=$matches[1]',
        'top'
    );
    add_rewrite_tag('%facility_slug%', '([^&]+)');
});

// Modify facility post type registration to use company name in URL
add_action('init', function() {
    register_post_type('facility', [
        'labels' => [
            'name' => 'Facilities',
            'singular_name' => 'Facility',
            'edit_item' => 'Edit Facility',
            'view_item' => 'View Facility',
            'search_items' => 'Search Facilities',
            'not_found' => 'No facilities found',
            'not_found_in_trash' => 'No facilities found in trash',
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-building',
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'rewrite' => [
            'slug' => 'facilities',
            'with_front' => false
        ],
        'show_in_rest' => true,
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ]);
});

// Update facility links in the results page
function get_facility_profile_url($place_id) {
    global $wpdb;
    
    // Try to find existing facility
    $post = get_posts([
        'post_type' => 'facility',
        'meta_key' => 'place_id',
        'meta_value' => $place_id,
        'posts_per_page' => 1
    ]);

    if (!empty($post)) {
        return get_permalink($post[0]->ID);
    }

    // If facility doesn't exist yet, return the search page with the place_id parameter
    return add_query_arg([
        'place_id' => $place_id,
        'action' => 'view_facility'
    ], home_url('/facilities/'));
}

// Handle facility profile requests
add_action('template_redirect', function() {
    // Check if we're on the facilities page with a place_id parameter
    if (isset($_GET['place_id']) && isset($_GET['action']) && $_GET['action'] === 'view_facility') {
        $place_id = sanitize_text_field($_GET['place_id']);
        
        // Try to find existing facility
        $existing = get_posts([
            'post_type' => 'facility',
            'meta_key' => 'place_id',
            'meta_value' => $place_id,
            'posts_per_page' => 1
        ]);

        if (!empty($existing)) {
            wp_redirect(get_permalink($existing[0]->ID));
            exit;
        }

        // If facility doesn't exist, create it
        $facility_data = get_facility_data_from_places_api($place_id);
        
        if ($facility_data) {
            // Create post content
            $post_content = sprintf(
                'Information about %s located at %s. Contact: %s',
                esc_html($facility_data['name']),
                esc_html($facility_data['address']),
                esc_html($facility_data['phone'] ?? 'Not available')
            );

            
$post_id = wp_insert_post([
                'post_title' => $facility_data['name'],
                'post_type' => 'facility',
                'post_status' => 'publish',
                'post_content' => $post_content
            ]);
if (!empty($lat) && !empty($lng)) {
    update_post_meta($post_id, 'facility_lat', $lat);
    update_post_meta($post_id, 'facility_lng', $lng);
}

            if (!is_wp_error($post_id)) {
                // Store facility data
                update_post_meta($post_id, 'place_id', $place_id);
                update_post_meta($post_id, 'facility_data', $facility_data);
                
                // Set the template
                update_post_meta($post_id, '_wp_page_template', 'templates/single-facility.php');
                
                // Redirect to the new facility page
                $redirect_url = get_permalink($post_id);
                if ($redirect_url) {
                    wp_redirect($redirect_url);
                    exit;
                }
            }
        }

        // If we get here, load the not found template
        status_header(404);
        include(plugin_dir_path(__FILE__) . 'templates/facility-not-found.php');
        exit;
    }
}, 10);

// Register custom templates
add_filter('template_include', function($template) {
    if (is_singular('facility')) {
        $new_template = plugin_dir_path(__FILE__) . 'templates/single-facility.php';
        if (file_exists($new_template)) {
            return $new_template;
        }
    }
    return $template;
});

// Add rewrite rules for facilities
add_action('init', function() {
    add_rewrite_rule(
        'facilities/([^/]+)/?$',
        'index.php?post_type=facility&name=$matches[1]',
        'top'
    );
    
    // Flush rewrite rules only if needed
    if (get_option('factory_tracker_flush_needed')) {
        flush_rewrite_rules();
        delete_option('factory_tracker_flush_needed');
    }
});

// Helper function to get facility data from Places API
function get_facility_data_from_places_api($place_id) {
    $details_url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $place_id,
        'fields' => 'name,formatted_address,geometry,type,rating,user_ratings_total,formatted_phone_number,website,photos',
        'key' => 'AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c'
    ]);

    $response = wp_remote_get($details_url);
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['result'])) {
            $result = $data['result'];
            
            // Determine facility type based on available data
            $facility_type = 'industrial';
            if (isset($result['types']) && is_array($result['types'])) {
                if (in_array('golf_course', $result['types'])) {
                    $facility_type = 'golf';
                } elseif (in_array('airport', $result['types'])) {
                    $facility_type = 'airport';
                }
            }

            // Get photo references if available
            $photos = [];
            if (!empty($result['photos'])) {
                foreach ($result['photos'] as $photo) {
                    $photo_url = 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query([
                        'maxwidth' => 800,
                        'photo_reference' => $photo['photo_reference'],
                        'key' => 'AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c'
                    ]);
                    $photos[] = $photo_url;
                }
            }

            return [
                'name' => $result['name'],
                'address' => $result['formatted_address'],
                'lat' => $result['geometry']['location']['lat'],
                'lng' => $result['geometry']['location']['lng'],
                'type' => $facility_type,
                'phone' => $result['formatted_phone_number'] ?? '',
                'website' => $result['website'] ?? '',
                'rating' => $result['rating'] ?? null,
                'reviews' => $result['user_ratings_total'] ?? 0,
                'photos' => $photos,
                'impact_score' => rand(20, 80), // Placeholder for demo
                'water_usage' => rand(100000, 1000000) . ' gallons/year', // Placeholder for demo
                'pesticide_use' => rand(1000, 5000) . ' lbs/year', // Placeholder for demo
                'environmental_impact' => [
                    'Air pollution from industrial processes',
                    'Groundwater contamination risk',
                    'Soil quality impact'
                ]
            ];
        }
    }
    return false;
}

// Handle review submission
add_action('init', function() {
    if (!isset($_POST['review_nonce']) || !wp_verify_nonce($_POST['review_nonce'], 'submit_review')) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('You must be logged in to submit a review.');
    }

    $facility_id = intval($_POST['facility_id']);
    $rating = intval($_POST['rating']);
    $review_text = sanitize_textarea_field($_POST['review_text']);

    if ($rating < 1 || $rating > 5) {
        wp_die('Invalid rating.');
    }

    global $wpdb;
    $reviews_table = $wpdb->prefix . 'facility_reviews';
    
    // Insert new review or update existing one
    $result = $wpdb->replace(
        $reviews_table,
        [
            'facility_id' => $facility_id,
            'user_id' => get_current_user_id(),
            'rating' => $rating,
            'review_text' => $review_text,
            'review_date' => current_time('mysql'),
            'status' => 'approved', // Auto-approve reviews
            'helpful_votes' => 0
        ],
        ['%d', '%d', '%d', '%s', '%s', '%s', '%d']
    );

    if ($result === false) {
        wp_die('Error saving review. Please try again.');
    }

    // Update facility meta with new rating average
    $avg_rating = $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(rating) FROM $reviews_table WHERE facility_id = %d AND status = 'approved'",
        $facility_id
    ));

    $review_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $reviews_table WHERE facility_id = %d AND status = 'approved'",
        $facility_id
    ));

    $facility_data = get_post_meta($facility_id, 'facility_data', true) ?: [];
    $facility_data['rating'] = round($avg_rating, 1);
    $facility_data['reviews'] = $review_count;
    update_post_meta($facility_id, 'facility_data', $facility_data);

    // Redirect back to facility page
    wp_redirect(add_query_arg('review_added', '1', get_permalink($facility_id) . '#reviews'));
    exit;
});

// Add airport search to facility results
add_filter('pre_facility_search', function($facilities, $lat, $lng, $radius) {
    // Search for airports
    $places_url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query([
        'location' => "$lat,$lng",
        'radius' => $radius * 1609.34, // Convert miles to meters
        'type' => 'airport',
        'key' => 'AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c'
    ]);

    $places_response = wp_remote_get($places_url);
    if (!is_wp_error($places_response)) {
        $places_data = json_decode(wp_remote_retrieve_body($places_response), true);
        if (isset($places_data['results'])) {
            foreach ($places_data['results'] as $place) {
                $facilities[] = [
                    'name' => $place['name'],
                    'address' => $place['vicinity'],
                    'lat' => $place['geometry']['location']['lat'],
                    'lng' => $place['geometry']['location']['lng'],
                    'place_id' => $place['place_id'],
                    'type' => 'airport',
                    'distance' => calculate_distance($lat, $lng, $place['geometry']['location']['lat'], $place['geometry']['location']['lng']),
                    'pollutants' => [
                        [
                            'name' => 'Aircraft Emissions',
                            'amount' => rand(50000, 200000) . ' lbs/year',
                            'health_effects' => 'Can cause respiratory issues and contribute to air pollution'
                        ],
                        [
                            'name' => 'De-icing Chemicals',
                            'amount' => rand(10000, 50000) . ' lbs/year',
                            'health_effects' => 'Can contaminate groundwater and affect aquatic ecosystems'
                        ],
                        [
                            'name' => 'Ground Support Equipment Emissions',
                            'amount' => rand(20000, 80000) . ' lbs/year',
                            'health_effects' => 'Contributes to local air quality issues and smog formation'
                        ]
                    ]
                ];
            }
        }
    }

    return $facilities;
}, 10, 4);

// Add demo facility
function add_demo_facility() {
    $demo_facility = [
        'name' => 'Nevada Clean Earth Asphalt Plant',
        'address' => 'Sparks, NV',
        'type' => 'industrial',
        'badge_type' => BADGE_REFORMING,
        'verification_notes' => 'Installed basic scrubbers and stopped illegal dumping in 2023. Further transparency needed on waste disposal and soil testing.',
        'lat' => 39.5349,
        'lng' => -119.7474
    ];

    // Create or update the facility post
    $existing = get_posts([
        'post_type' => 'facility',
        'meta_key' => 'facility_name',
        'meta_value' => $demo_facility['name'],
        'posts_per_page' => 1
    ]);

    if (empty($existing)) {
        
$post_id = wp_insert_post([
            'post_title' => $demo_facility['name'],
            'post_type' => 'facility',
            'post_status' => 'publish'
        ]);
if (!empty($lat) && !empty($lng)) {
    update_post_meta($post_id, 'facility_lat', $lat);
    update_post_meta($post_id, 'facility_lng', $lng);
}

        update_post_meta($post_id, 'facility_data', $demo_facility);

        // Add badge
        global $wpdb;
        $badges_table = $wpdb->prefix . 'facility_badges';
        $wpdb->insert(
            $badges_table,
            [
                'facility_id' => $post_id,
                'badge_type' => BADGE_REFORMING,
                'verification_notes' => $demo_facility['verification_notes'],
                'verification_date' => '2023-01-15'
            ]
        );
    }
}

// Add demo facility on plugin activation
register_activation_hook(__FILE__, 'add_demo_facility');

// Add function to estimate revenue and cleanup costs
function get_facility_financial_data($type) {
    $data = [
        'automotive' => [
            'revenue_range' => [50000000000, 100000000000],
            'cleanup_cost' => [2000000, 5000000],
            'examples' => ['Tesla', 'Ford', 'GM']
        ],
        'chemical' => [
            'revenue_range' => [10000000000, 30000000000],
            'cleanup_cost' => [3000000, 8000000],
            'examples' => ['Dow Chemical', 'DuPont', 'BASF']
        ],
        'energy' => [
            'revenue_range' => [20000000000, 80000000000],
            'cleanup_cost' => [5000000, 15000000],
            'examples' => ['ExxonMobil', 'Shell', 'BP']
        ],
        'manufacturing' => [
            'revenue_range' => [5000000000, 20000000000],
            'cleanup_cost' => [1000000, 4000000],
            'examples' => ['GE', 'Siemens', '3M']
        ],
        'mining' => [
            'revenue_range' => [8000000000, 25000000000],
            'cleanup_cost' => [4000000, 12000000],
            'examples' => ['Rio Tinto', 'BHP', 'Vale']
        ],
        'golf' => [
            'revenue_range' => [2000000, 10000000],
            'cleanup_cost' => [500000, 1500000],
            'examples' => ['Augusta National', 'Pebble Beach', 'St Andrews']
        ],
        'airport' => [
            'revenue_range' => [100000000, 500000000],
            'cleanup_cost' => [3000000, 8000000],
            'examples' => ['LAX', 'JFK', 'O\'Hare']
        ],
        'default' => [
            'revenue_range' => [1000000, 5000000],
            'cleanup_cost' => [1000000, 3000000],
            'examples' => []
        ]
    ];

    return $data[$type] ?? $data['default'];
}

// Remove country restriction from search
function search_facilities($lat, $lng, $radius, $per_page = 15, $paged = 1) {
    global $wpdb;
    $facilities = [];
    $offset = ($paged - 1) * $per_page;
    if ($per_page > 10000) $per_page = 10000; // Enforce max limit
    
    // Get list of flagged facility IDs
    $flagged_ids = $wpdb->get_col("SELECT facility_id FROM {$wpdb->prefix}facility_meta WHERE is_flagged = 1");
    $flagged_ids = empty($flagged_ids) ? [-1] : $flagged_ids;
    
    // Search parameters for different facility types
    $search_types = [
        [
            'type' => '',
            'keyword' => 'factory OR manufacturing OR industrial OR chemical OR refinery OR plant OR asphalt OR concrete OR waste'
        ],
        [
            'type' => 'golf_course',
            'keyword' => 'golf course'
        ],
        [
            'type' => 'airport',
            'keyword' => 'airport'
        ]
    ];

    foreach ($search_types as $search) {
        $places_url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query([
            'location' => "$lat,$lng",
            'radius' => $radius * 1609.34, // Convert miles to meters
            'type' => $search['type'],
            'keyword' => $search['keyword'],
            'key' => 'AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c'
        ]);

        $places_response = wp_remote_get($places_url);
        if (!is_wp_error($places_response)) {
            $places_data = json_decode(wp_remote_retrieve_body($places_response), true);
            if (isset($places_data['results'])) {
                foreach ($places_data['results'] as $place) {
                    // Skip if already flagged
                    if (in_array($place['place_id'], $flagged_ids)) {
                        continue;
                    }

                    // Determine facility type
                    $facility_type = 'default';
                    if (in_array('airport', $place['types'])) {
                        $facility_type = 'airport';
                    } elseif (in_array('golf_course', $place['types'])) {
                        $facility_type = 'golf';
                    } else {
                        foreach (['automotive', 'chemical', 'energy', 'manufacturing', 'mining'] as $type) {
                            if (stripos($place['name'], $type) !== false) {
                                $facility_type = $type;
                                break;
                            }
                        }
                    }

                    // Get financial data and estimate impact
                    $financial_data = get_facility_financial_data($facility_type);
                    $revenue = rand($financial_data['revenue_range'][0], $financial_data['revenue_range'][1]);
                    $cleanup_cost = rand($financial_data['cleanup_cost'][0], $financial_data['cleanup_cost'][1]);

                    $facilities[] = [
                        'name' => $place['name'],
                        'address'    => $place['vicinity'] ?? $place['formatted_address'] ?? '',
                        'lat' => $place['geometry']['location']['lat'],
                        'lng' => $place['geometry']['location']['lng'],
                        'place_id' => $place['place_id'],
                        'type' => $facility_type,
                        'revenue' => $revenue,
                        'cleanup_cost' => $cleanup_cost,
                        'distance' => calculate_distance($lat, $lng, $place['geometry']['location']['lat'], $place['geometry']['location']['lng']),
                        'pollutants' => get_estimated_pollutants($facility_type)
                    ];
                }
            }
        }
    }

    return array_filter($facilities);
}

// Add function to get estimated pollutants by facility type
function get_estimated_pollutants($type) {
    $pollutants = [
        'industrial' => [
            ['name' => 'Particulate Matter', 'amount' => rand(5000, 20000) . ' lbs/year', 'health_effects' => 'Respiratory issues, heart disease'],
            ['name' => 'Volatile Organic Compounds', 'amount' => rand(10000, 50000) . ' lbs/year', 'health_effects' => 'Respiratory irritation, cancer risk'],
            ['name' => 'Nitrogen Oxides', 'amount' => rand(15000, 75000) . ' lbs/year', 'health_effects' => 'Respiratory problems, acid rain']
        ],
        'golf' => [
            ['name' => 'Pesticides', 'amount' => rand(500, 2000) . ' lbs/year', 'health_effects' => 'Water contamination, wildlife impact'],
            ['name' => 'Fertilizers', 'amount' => rand(1000, 5000) . ' lbs/year', 'health_effects' => 'Water pollution, algal blooms'],
            ['name' => 'Water Usage', 'amount' => rand(10000000, 50000000) . ' gallons/year', 'health_effects' => 'Groundwater depletion']
        ],
        'airport' => [
            ['name' => 'Aircraft Emissions', 'amount' => rand(50000, 200000) . ' lbs/year', 'health_effects' => 'Air pollution, respiratory issues'],
            ['name' => 'De-icing Chemicals', 'amount' => rand(10000, 50000) . ' lbs/year', 'health_effects' => 'Water contamination'],
            ['name' => 'Ground Equipment Emissions', 'amount' => rand(20000, 80000) . ' lbs/year', 'health_effects' => 'Local air quality impact']
        ],
        'default' => [
            ['name' => 'General Emissions', 'amount' => rand(5000, 25000) . ' lbs/year', 'health_effects' => 'Various health impacts'],
            ['name' => 'Waste Products', 'amount' => rand(1000, 5000) . ' lbs/year', 'health_effects' => 'Environmental contamination']
        ]
    ];

    return $pollutants[$type] ?? $pollutants['default'];
}

// Add comment support for facilities
function factory_tracker_add_comment_support() {
    add_post_type_support('facility', 'comments');
    
    // Enable comments for facilities
    if (is_singular('facility')) {
        global $post;
        $post->comment_status = 'open';
    }
}
add_action('init', 'factory_tracker_add_comment_support');

// Customize comment form fields
function factory_tracker_comment_form_defaults($defaults) {
    if (get_post_type() === 'facility') {
        $defaults['title_reply'] = 'Leave a Comment';
        $defaults['title_reply_to'] = 'Reply to %s';
        $defaults['comment_field'] = '<p class="comment-form-comment">
            <label for="comment" class="block text-sm font-medium text-gray-700 mb-2">Your Comment</label>
            <textarea id="comment" name="comment" rows="4" 
                     class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                     required></textarea>
        </p>';
        $defaults['class_form'] = 'space-y-4';
        $defaults['class_submit'] = 'bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700';
    }
    return $defaults;
}
add_filter('comment_form_defaults', 'factory_tracker_comment_form_defaults');

// Handle comment submission
function factory_tracker_pre_comment_content($commentdata) {
    if (get_post_type($commentdata['comment_post_ID']) === 'facility') {
        if (empty($commentdata['comment_content'])) {
            wp_die('Error: please type a comment.');
        }
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'factory_tracker_pre_comment_content');

// Add meta box for facility flagging
add_action('add_meta_boxes', function() {
    add_meta_box(
        'facility_flag_box',
        'Facility Flagging',
        'facility_flag_meta_box_callback',
        'facility',
        'side',
        'high'
    );
});

// Meta box callback function
function facility_flag_meta_box_callback($post) {
    global $wpdb;
    
    // Get current flag status and note
    $meta = $wpdb->get_row($wpdb->prepare(
        "SELECT is_flagged, admin_note FROM {$wpdb->prefix}facility_meta WHERE facility_id = %d",
        $post->ID
    ));
    
    $is_flagged = $meta ? $meta->is_flagged : 0;
    $admin_note = $meta ? $meta->admin_note : '';
    
    wp_nonce_field('facility_flag_save', 'facility_flag_nonce');
    ?>
    <div class="facility-flag-box">
        <p>
            <label>
                <input type="checkbox" name="is_flagged" value="1" <?php checked($is_flagged, 1); ?> />
                Flag as incorrect or misclassified
            </label>
        </p>
        <p>
            <label for="admin_note">Admin Note:</label><br>
            <textarea name="admin_note" id="admin_note" rows="3" style="width: 100%;"><?php echo esc_textarea($admin_note); ?></textarea>
            <span class="description">Explain why this facility was flagged (e.g., "Restaurant with no industrial activity")</span>
        </p>
    </div>
    <?php
}

// Handle flag updates separately
function update_facility_flag($post_id, $is_flagged, $admin_note) {
    global $wpdb;
    
    return $wpdb->replace(
        $wpdb->prefix . 'facility_meta',
        [
            'facility_id' => $post_id,
            'is_flagged' => $is_flagged ? 1 : 0,
            'admin_note' => $admin_note
        ],
        ['%d', '%d', '%s']
    );
}

// Save meta box data
add_action('save_post_facility', function($post_id) {
    // Check if our nonce is set and verify it
    if (!isset($_POST['facility_flag_nonce']) || !wp_verify_nonce($_POST['facility_flag_nonce'], 'facility_flag_save')) {
        return;
    }

    // If this is an autosave, don't do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check the user's permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $is_flagged = isset($_POST['is_flagged']) ? 1 : 0;
    $admin_note = sanitize_textarea_field($_POST['admin_note'] ?? '');
    
    update_facility_flag($post_id, $is_flagged, $admin_note);
    
    // Clear any cached data
    clean_post_cache($post_id);
});

// Add flagged filter to admin list
add_filter('parse_query', function($query) {
    global $pagenow;
    
    if (is_admin() && $pagenow == 'edit.php' && 
        isset($_GET['post_type']) && $_GET['post_type'] == 'facility' && 
        isset($_GET['is_flagged'])) {
        
        global $wpdb;
        
        // Get flagged facility IDs
        $flagged_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT facility_id FROM {$wpdb->prefix}facility_meta WHERE is_flagged = %d",
            1
        ));
        
        // Instead of using post__in, modify the meta query
        $query->query_vars['meta_query'] = [
            [
                'key' => 'facility_meta_flagged',
                'value' => '1',
                'compare' => '='
            ]
        ];

        // Also store the IDs in post meta for quick access
        if (!empty($flagged_ids)) {
            foreach ($flagged_ids as $id) {
                update_post_meta($id, 'facility_meta_flagged', '1');
            }
        }
    }
    return $query;
});

// Add admin column for flag status
add_filter('manage_facility_posts_columns', function($columns) {
    $columns['flagged'] = 'Flagged';
    return $columns;
});

add_action('manage_facility_posts_custom_column', function($column, $post_id) {
    if ($column === 'flagged') {
        global $wpdb;
        $is_flagged = $wpdb->get_var($wpdb->prepare(
            "SELECT is_flagged FROM {$wpdb->prefix}facility_meta WHERE facility_id = %d",
            $post_id
        ));
        echo $is_flagged ? '⚠️ Yes' : 'No';
    }
}, 10, 2);

// Add admin filter for flagged facilities
add_filter('views_edit-facility', function($views) {
    global $wpdb;
    
    $flagged_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}facility_meta WHERE is_flagged = %d",
        1
    ));
    
    $class = isset($_GET['is_flagged']) ? 'current' : '';
    $views['flagged'] = sprintf(
        '<a href="%s" class="%s">Flagged <span class="count">(%d)</span></a>',
        esc_url(add_query_arg(['post_type' => 'facility', 'is_flagged' => 1], 'edit.php')),
        esc_attr($class),
        intval($flagged_count)
    );
    
    return $views;
});

// Add meta box for facility details
add_action('add_meta_boxes', function() {
    add_meta_box(
        'facility_details_box',
        'Facility Details',
        'facility_details_meta_box_callback',
        'facility',
        'normal',
        'high'
    );
});

// Meta box callback function
function facility_details_meta_box_callback($post) {
    $facility_data = get_post_meta($post->ID, 'facility_data', true) ?: [];
    wp_nonce_field('facility_details_save', 'facility_details_nonce');
    ?>
    <div class="facility-details-box">
        <style>
            .details-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px; }
            .details-grid label { display: block; margin-bottom: 5px; font-weight: bold; }
            .details-grid input { width: 100%; padding: 5px; }
        </style>
        <div class="details-grid">
            <div>
                <label for="facility_name">Facility Name:</label>
                <input type="text" name="facility_data[name]" id="facility_name" 
                       value="<?php echo esc_attr($facility_data['name'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="facility_type">Facility Type:</label>
                <select name="facility_data[type]" id="facility_type">
                    <option value="industrial" <?php selected(($facility_data['type'] ?? ''), 'industrial'); ?>>Industrial Facility</option>
                    <option value="golf" <?php selected(($facility_data['type'] ?? ''), 'golf'); ?>>Golf Course</option>
                    <option value="airport" <?php selected(($facility_data['type'] ?? ''), 'airport'); ?>>Airport</option>
                </select>
            </div>
            <div>
                <label for="facility_address">Address:</label>
                <input type="text" name="facility_data[address]" id="facility_address" 
                       value="<?php echo esc_attr($facility_data['address'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="facility_phone">Phone:</label>
                <input type="text" name="facility_data[phone]" id="facility_phone" 
                       value="<?php echo esc_attr($facility_data['phone'] ?? ''); ?>">
            </div>
            <div>
                <label for="facility_website">Website:</label>
                <input type="url" name="facility_data[website]" id="facility_website" 
                       value="<?php echo esc_attr($facility_data['website'] ?? ''); ?>">
            </div>
            <div>
                <label for="facility_revenue">Annual Revenue:</label>
                <input type="number" name="facility_data[revenue]" id="facility_revenue" min="0" step="1000" 
                       value="<?php echo esc_attr($facility_data['revenue'] ?? ''); ?>">
            </div>
            <div>
                <label for="facility_cleanup_cost">Cleanup Cost:</label>
                <input type="number" name="facility_data[cleanup_cost]" id="facility_cleanup_cost" min="0" step="1000" 
                       value="<?php echo esc_attr($facility_data['cleanup_cost'] ?? ''); ?>">
            </div>
            <div>
                <label for="facility_lat">Latitude:</label>
                <input type="number" name="facility_data[lat]" id="facility_lat" step="0.000001" 
                       value="<?php echo esc_attr($facility_data['lat'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="facility_lng">Longitude:</label>
                <input type="number" name="facility_data[lng]" id="facility_lng" step="0.000001" 
                       value="<?php echo esc_attr($facility_data['lng'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="facility_photo_url">Image URL:</label>
                <input type="text" name="facility_data[photo_url]" id="facility_photo_url" 
                       value="<?php echo esc_attr($facility_data['photo_url'] ?? ''); ?>" placeholder="https://...">
            </div>
        </div>
    </div>
    <?php
}

// Save meta box data
add_action('save_post_facility', function($post_id) {
    // Verify nonce
    if (!isset($_POST['facility_details_nonce']) || !wp_verify_nonce($_POST['facility_details_nonce'], 'facility_details_save')) {
        return;
    }

    // If this is an autosave, don't do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save the facility data
    if (isset($_POST['facility_data']) && is_array($_POST['facility_data'])) {
        $facility_data = array_map('sanitize_text_field', $_POST['facility_data']);
        
        // Convert numeric values
        foreach (['revenue', 'cleanup_cost', 'lat', 'lng'] as $numeric_field) {
            if (isset($facility_data[$numeric_field])) {
                $facility_data[$numeric_field] = floatval($facility_data[$numeric_field]);
            }
        }
        
        update_post_meta($post_id, 'facility_data', $facility_data);
    }
});

// Add meta box for facility management
add_action('add_meta_boxes', function() {
    add_meta_box(
        'facility_management',
        'Facility Management',
        'render_facility_management_box',
        'facility',
        'normal',
        'high'
    );
});

// Render facility management meta box
function render_facility_management_box($post) {
    wp_nonce_field('save_facility_meta', 'facility_meta_nonce');
    
    // Get existing meta
    $meta = get_post_meta($post->ID, 'facility_meta', true) ?: [];
    $is_flagged = get_post_meta($post->ID, 'is_flagged', true) ?: false;
    $admin_note = get_post_meta($post->ID, 'admin_note', true) ?: '';
    $phone = get_post_meta($post->ID, 'phone', true) ?: '';
    $revenue = get_post_meta($post->ID, 'revenue', true) ?: '';
    $website = get_post_meta($post->ID, 'website', true) ?: '';
    $employees = get_post_meta($post->ID, 'employees', true) ?: '';
    ?>
    <div class="facility-management-box p-4">
        <style>
            .facility-management-box .form-group { margin-bottom: 1rem; }
            .facility-management-box label { display: block; margin-bottom: 0.5rem; font-weight: bold; }
            .facility-management-box input[type="text"],
            .facility-management-box textarea { width: 100%; padding: 0.5rem; }
            .facility-management-box .checkbox-group { display: flex; align-items: center; gap: 0.5rem; }
        </style>

        <div class="form-group">
            <div class="checkbox-group">
                <input type="checkbox" id="is_flagged" name="is_flagged" value="1" <?php checked($is_flagged, '1'); ?>>
                <label for="is_flagged">Flag as Incorrect/Misclassified</label>
            </div>
        </div>

        <div class="form-group">
            <label for="admin_note">Admin Notes</label>
            <textarea id="admin_note" name="admin_note" rows="3"><?php echo esc_textarea($admin_note); ?></textarea>
        </div>

        <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="text" id="phone" name="phone" value="<?php echo esc_attr($phone); ?>">
        </div>

        <div class="form-group">
            <label for="revenue">Annual Revenue (USD)</label>
            <input type="text" id="revenue" name="revenue" value="<?php echo esc_attr($revenue); ?>">
        </div>

        <div class="form-group">
            <label for="website">Website URL</label>
            <input type="text" id="website" name="website" value="<?php echo esc_attr($website); ?>">
        </div>

        <div class="form-group">
            <label for="employees">Number of Employees</label>
            <input type="text" id="employees" name="employees" value="<?php echo esc_attr($employees); ?>">
        </div>
    </div>
    <?php
}

// Save facility meta data
add_action('save_post_facility', function($post_id) {
    if (!isset($_POST['facility_meta_nonce']) || 
        !wp_verify_nonce($_POST['facility_meta_nonce'], 'save_facility_meta')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save meta values
    update_post_meta($post_id, 'is_flagged', isset($_POST['is_flagged']) ? '1' : '0');
    update_post_meta($post_id, 'admin_note', sanitize_textarea_field($_POST['admin_note']));
    update_post_meta($post_id, 'phone', sanitize_text_field($_POST['phone']));
    update_post_meta($post_id, 'revenue', sanitize_text_field($_POST['revenue']));
    update_post_meta($post_id, 'website', esc_url_raw($_POST['website']));
    update_post_meta($post_id, 'employees', sanitize_text_field($_POST['employees']));
});

// Add custom columns to admin list
add_filter('manage_facility_posts_columns', function($columns) {
    $columns['is_flagged'] = 'Flagged';
    $columns['phone'] = 'Phone';
    $columns['revenue'] = 'Revenue';
    return $columns;
});

// Fill custom columns
add_action('manage_facility_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'is_flagged':
            echo get_post_meta($post_id, 'is_flagged', true) ? '✓' : '–';
            break;
        case 'phone':
            echo esc_html(get_post_meta($post_id, 'phone', true));
            break;
        case 'revenue':
            echo esc_html(get_post_meta($post_id, 'revenue', true));
            break;
    }
}, 10, 2);

// Make columns sortable
add_filter('manage_edit-facility_sortable_columns', function($columns) {
    $columns['is_flagged'] = 'is_flagged';
    $columns['revenue'] = 'revenue';
    return $columns;
});

// Add facility details to single facility view
add_action('facility_details', function() {
    $facility_id = get_the_ID();
    $phone = get_post_meta($facility_id, 'phone', true);
    $revenue = get_post_meta($facility_id, 'revenue', true);
    $website = get_post_meta($facility_id, 'website', true);
    $employees = get_post_meta($facility_id, 'employees', true);
    
    if ($phone || $revenue || $website || $employees):
    ?>
    <div class="facility-details mt-6 bg-white rounded-lg shadow-sm p-6">
        <h3 class="text-xl font-semibold mb-4">Additional Details</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php if ($phone): ?>
            <div class="detail-item">
                <span class="font-medium text-gray-600">Phone:</span>
                <span class="ml-2"><?php echo esc_html($phone); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($revenue): ?>
            <div class="detail-item">
                <span class="font-medium text-gray-600">Annual Revenue:</span>
                <span class="ml-2"><?php echo esc_html($revenue); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($website): ?>
            <div class="detail-item">
                <span class="font-medium text-gray-600">Website:</span>
                <span class="ml-2">
                    <a href="<?php echo esc_url($website); ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
                        Visit Website
                    </a>
                </span>
            </div>
            <?php endif; ?>
            
            <?php if ($employees): ?>
            <div class="detail-item">
                <span class="font-medium text-gray-600">Employees:</span>
                <span class="ml-2"><?php echo esc_html($employees); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    endif;
});

// Handle review actions (approve/delete)
add_action('init', function() {
    if (!isset($_POST['review_nonce']) || !wp_verify_nonce($_POST['review_nonce'], 'review_action')) {
        return;
    }

    if (!current_user_can('edit_posts')) {
        wp_die('You do not have permission to manage reviews.');
    }

    $review_id = intval($_POST['review_id']);
    $action = sanitize_text_field($_POST['action']);

    global $wpdb;
    $reviews_table = $wpdb->prefix . 'facility_reviews';

    switch ($action) {
        case 'approve':
            $wpdb->update(
                $reviews_table,
                ['status' => 'approved'],
                ['id' => $review_id],
                ['%s'],
                ['%d']
            );
            break;
        case 'delete':
            $wpdb->delete(
                $reviews_table,
                ['id' => $review_id],
                ['%d']
            );
            break;
    }

    // Redirect back to the facility page
    wp_redirect(wp_get_referer() . '#reviews');
    exit;
});

// Allow admins to submit reviews without approval
add_action('init', function() {
    if (!isset($_POST['review_nonce']) || !wp_verify_nonce($_POST['review_nonce'], 'submit_review')) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('You must be logged in to submit a review.');
    }

    $facility_id = intval($_POST['facility_id']);
    $rating = intval($_POST['rating']);
    $review_text = sanitize_textarea_field($_POST['review_text']);

    if ($rating < 1 || $rating > 5) {
        wp_die('Invalid rating.');
    }

    global $wpdb;
    $reviews_table = $wpdb->prefix . 'facility_reviews';
    
    // Auto-approve reviews from admins
    $status = current_user_can('edit_posts') ? 'approved' : 'pending';
    
    // Insert new review or update existing one
    $result = $wpdb->replace(
        $reviews_table,
        [
            'facility_id' => $facility_id,
            'user_id' => get_current_user_id(),
            'rating' => $rating,
            'review_text' => $review_text,
            'review_date' => current_time('mysql'),
            'status' => $status,
            'helpful_votes' => 0
        ],
        ['%d', '%d', '%d', '%s', '%s', '%s', '%d']
    );

    if ($result === false) {
        wp_die('Error saving review. Please try again.');
    }

    // Only update facility meta for approved reviews
    if ($status === 'approved') {
        // Update facility meta with new rating average
        $avg_rating = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(rating) FROM $reviews_table WHERE facility_id = %d AND status = 'approved'",
            $facility_id
        ));

        $review_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $reviews_table WHERE facility_id = %d AND status = 'approved'",
            $facility_id
        ));

        $facility_data = get_post_meta($facility_id, 'facility_data', true) ?: [];
        $facility_data['rating'] = round($avg_rating, 1);
        $facility_data['reviews'] = $review_count;
        update_post_meta($facility_id, 'facility_data', $facility_data);
    }

    // Redirect back to facility page
    wp_redirect(add_query_arg('review_added', '1', get_permalink($facility_id) . '#reviews'));
    exit;
});

// Handle facility updates and flags
add_action('wp_ajax_update_facility', 'handle_facility_update');
add_action('wp_ajax_nopriv_update_facility', 'handle_facility_update');

function handle_facility_update() {
    check_ajax_referer('facility_update', 'nonce');
    
    $facility_id = intval($_POST['facility_id']);
    $action_type = sanitize_text_field($_POST['action_type']);
    
    if (!$facility_id) {
        wp_send_json_error('Invalid facility ID');
    }

    global $wpdb;
    
    switch ($action_type) {
        case 'flag':
            $reason = sanitize_text_field($_POST['reason']);
            $wpdb->update(
                $wpdb->prefix . 'facility_meta',
                ['is_flagged' => 1, 'admin_note' => $reason],
                ['facility_id' => $facility_id],
                ['%d', '%s'],
                ['%d']
            );
            wp_send_json_success('Facility has been flagged for review');
            break;
            
        case 'update':
            $revenue = sanitize_text_field($_POST['revenue']);
            $employees = intval($_POST['employees']);
            
            update_post_meta($facility_id, 'revenue', $revenue);
            update_post_meta($facility_id, 'employees', $employees);
            
            // Update facility data array
            $facility_data = get_post_meta($facility_id, 'facility_data', true);
            if (is_array($facility_data)) {
                $facility_data['revenue'] = $revenue;
                $facility_data['employees'] = $employees;
                update_post_meta($facility_id, 'facility_data', $facility_data);
            }
            
            wp_send_json_success('Facility information updated');
            break;
    }
    
    wp_send_json_error('Invalid action type');
}

// Add badge system functionality
function get_facility_badge($facility_id) {
    global $wpdb;
    
    // Get facility data
    $facility_data = get_post_meta($facility_id, 'facility_data', true);
    if (!$facility_data) return null;

    // Check badge criteria
    $badge = null;
    $impact_score = isset($facility_data['impact_score']) ? intval($facility_data['impact_score']) : 100;
    
    if ($impact_score <= 30) {
        $badge = [
            'type' => 'green',
            'name' => 'Environmental Leader',
            'emoji' => '🌿',
            'color' => 'green',
            'description' => 'This facility demonstrates exceptional environmental responsibility.'
        ];
    } elseif ($impact_score <= 60) {
        $badge = [
            'type' => 'yellow',
            'name' => 'Improving Performance',
            'emoji' => '📈',
            'color' => 'yellow',
            'description' => 'This facility is actively working to improve its environmental impact.'
        ];
    } elseif ($impact_score > 60) {
        $badge = [
            'type' => 'red',
            'name' => 'Needs Improvement',
            'emoji' => '⚠️',
            'color' => 'red',
            'description' => 'This facility requires significant environmental improvements.'
        ];
    }

    return $badge;
}

// Enqueue necessary scripts
add_action('wp_enqueue_scripts', function() {
    // Enqueue Leaflet CSS and JS for maps
    wp_enqueue_style(
        'leaflet-css',
        'https://unpkg.com/leaflet@1.9.3/dist/leaflet.css',
        [],
        '1.9.3'
    );
    wp_enqueue_script(
        'leaflet-js',
        'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js',
        [],
        '1.9.3',
        true
    );

    if (is_singular('facility')) {
        // Enqueue Swiper
        wp_enqueue_style(
            'swiper-css',
            'https://unpkg.com/swiper/swiper-bundle.min.css',
            [],
            '8.4.7'
        );
        wp_enqueue_script(
            'swiper-js',
            'https://unpkg.com/swiper/swiper-bundle.min.js',
            [],
            '8.4.7',
            true
        );

        // Enqueue our custom JS
        wp_enqueue_script(
            'facility-page',
            plugins_url('js/facility-page.js', __FILE__),
            ['jquery', 'swiper-js', 'leaflet-js'],
            '1.0',
            true
        );

        // Localize script
        wp_localize_script('facility-page', 'facilityData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('facility_update')
        ]);
    }

    // Enqueue scripts for search results page
    if (is_page('facility-results') || is_page('facilities')) {
        wp_enqueue_script(
            'facility-search',
            plugins_url('js/facility-search.js', __FILE__),
            ['jquery', 'leaflet-js'],
            '1.0',
            true
        );

        wp_localize_script('facility-search', 'facilitySearchData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('facility_search'),
            'posts_per_page' => 12
        ]);
    }
});

// Handle facility flag submissions
function handle_facility_flag() {
    check_ajax_referer('factory_tracker_nonce', 'nonce');
    
    $facility_id = intval($_POST['facility_id']);
    $reason = sanitize_textarea_field($_POST['reason']);
    
    if (!$facility_id || !$reason) {
        wp_send_json_error('Invalid data provided');
        return;
    }
    
    // Store the flag in post meta
    $flags = get_post_meta($facility_id, '_facility_flags', true) ?: array();
    $flags[] = array(
        'reason' => $reason,
        'date' => current_time('mysql'),
        'user_id' => get_current_user_id(),
        'status' => 'pending'
    );
    update_post_meta($facility_id, '_facility_flags', $flags);
    
    // Send email notification to admin
    $facility = get_post($facility_id);
    $admin_email = get_option('admin_email');
    $subject = sprintf('New Facility Report - %s', $facility->post_title);
    
    $message = sprintf(
        "A new report has been submitted for facility: %s\n\n" .
        "Report Details:\n" .
        "Date: %s\n" .
        "Reason: %s\n\n" .
        "View Facility: %s",
        $facility->post_title,
        current_time('mysql'),
        $reason,
        get_edit_post_link($facility_id)
    );
    
    wp_mail($admin_email, $subject, $message);
    
    // Add admin notification
    add_action('admin_notices', function() use ($facility) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php printf(
                    'New facility report for <strong>%s</strong>. <a href="%s">View facility</a>',
                    esc_html($facility->post_title),
                    esc_url(get_edit_post_link($facility->ID))
                ); ?>
            </p>
        </div>
        <?php
    });
    
    wp_send_json_success();
}
add_action('wp_ajax_flag_facility', 'handle_facility_flag');
add_action('wp_ajax_nopriv_flag_facility', 'handle_facility_flag');

// Add Flags column to facilities list
function add_facility_flags_column($columns) {
    $columns['flags'] = 'Reports';
    return $columns;
}
add_filter('manage_facility_posts_columns', 'add_facility_flags_column');

// Display flags count in admin column
function display_facility_flags_column($column, $post_id) {
    if ($column === 'flags') {
        $flags = get_post_meta($post_id, '_facility_flags', true) ?: array();
        $pending_flags = array_filter($flags, function($flag) {
            return $flag['status'] === 'pending';
        });
        
        if (count($pending_flags) > 0) {
            printf(
                '<span class="badge" style="background: #ca4a1f; color: white; padding: 2px 8px; border-radius: 12px;">%d</span>',
                count($pending_flags)
            );
        } else {
            echo '—';
        }
    }
}
add_action('manage_facility_posts_custom_column', 'display_facility_flags_column', 10, 2);

// Add facility flags meta box
function add_facility_flags_meta_box() {
    add_meta_box(
        'facility_flags',
        'Facility Reports',
        'render_facility_flags_meta_box',
        'facility',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'add_facility_flags_meta_box');

// Render facility flags meta box
function render_facility_flags_meta_box($post) {
    $flags = get_post_meta($post->ID, '_facility_flags', true) ?: array();
    
    if (empty($flags)) {
        echo '<p>No reports submitted for this facility.</p>';
        return;
    }
    
    echo '<table class="widefat fixed" style="margin-top: 10px;">';
    echo '<thead><tr>';
    echo '<th>Date</th>';
    echo '<th>Reported By</th>';
    echo '<th>Reason</th>';
    echo '<th>Status</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($flags as $index => $flag) {
        $user = get_user_by('id', $flag['user_id']);
        $username = $user ? $user->display_name : 'Anonymous';
        
        echo '<tr>';
        echo '<td>' . esc_html($flag['date']) . '</td>';
        echo '<td>' . esc_html($username) . '</td>';
        echo '<td>' . esc_html($flag['reason']) . '</td>';
        echo '<td>' . esc_html(ucfirst($flag['status'])) . '</td>';
        echo '<td>';
        if ($flag['status'] === 'pending') {
            printf(
                '<button class="button resolve-flag" data-facility="%d" data-index="%d">Resolve</button>',
                $post->ID,
                $index
            );
        }
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Add JavaScript for handling resolve button
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.resolve-flag').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const facilityId = button.data('facility');
            const flagIndex = button.data('index');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'resolve_facility_flag',
                    facility_id: facilityId,
                    flag_index: flagIndex,
                    nonce: '<?php echo wp_create_nonce("resolve_flag_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        button.closest('tr').find('td:eq(3)').text('Resolved');
                        button.remove();
                    }
                }
            });
        });
    });
    </script>
    <?php
}

// Handle resolving flags
function handle_resolve_flag() {
    check_ajax_referer('resolve_flag_nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $facility_id = intval($_POST['facility_id']);
    $flag_index = intval($_POST['flag_index']);
    
    $flags = get_post_meta($facility_id, '_facility_flags', true) ?: array();
    
    if (isset($flags[$flag_index])) {
        $flags[$flag_index]['status'] = 'resolved';
        update_post_meta($facility_id, '_facility_flags', $flags);
        wp_send_json_success();
    } else {
        wp_send_json_error('Flag not found');
    }
}
add_action('wp_ajax_resolve_facility_flag', 'handle_resolve_flag');

// Add sitemap page template
function factory_tracker_add_sitemap_template($templates) {
    $templates['templates/sitemap.php'] = 'Facilities Sitemap';
    return $templates;
}
add_filter('theme_page_templates', 'factory_tracker_add_sitemap_template');

// Add health risk data
function get_facility_health_risks($facility_type) {
    $risks = array(
        'industrial' => array(
            'respiratory' => array(
                'level' => 'high',
                'description' => 'Industrial facilities may emit particulate matter and toxic gases that can cause respiratory issues.',
                'effects' => array(
                    'Increased risk of asthma',
                    'Bronchitis',
                    'Reduced lung function'
                )
            ),
            'water' => array(
                'level' => 'medium',
                'description' => 'Industrial runoff can contaminate local water sources.',
                'effects' => array(
                    'Groundwater contamination',
                    'Drinking water quality issues',
                    'Aquatic ecosystem damage'
                )
            )
        ),
        'chemical' => array(
            'respiratory' => array(
                'level' => 'high',
                'description' => 'Chemical facilities may release toxic fumes and gases.',
                'effects' => array(
                    'Severe respiratory irritation',
                    'Chemical pneumonia risk',
                    'Long-term lung damage'
                )
            ),
            'skin' => array(
                'level' => 'high',
                'description' => 'Chemical exposure can cause skin issues.',
                'effects' => array(
                    'Chemical burns',
                    'Skin irritation',
                    'Dermatitis'
                )
            )
        ),
        'waste_management' => array(
            'soil' => array(
                'level' => 'high',
                'description' => 'Waste facilities can contaminate soil.',
                'effects' => array(
                    'Soil toxicity',
                    'Groundwater leaching',
                    'Plant life damage'
                )
            ),
            'air' => array(
                'level' => 'medium',
                'description' => 'Decomposing waste can release harmful gases.',
                'effects' => array(
                    'Odor problems',
                    'Respiratory irritation',
                    'Air quality reduction'
                )
            )
        )
    );

    return isset($risks[$facility_type]) ? $risks[$facility_type] : array();
}

// Add meta box for health risks
function add_health_risks_meta_box() {
    add_meta_box(
        'facility_health_risks',
        'Health Risks',
        'render_health_risks_meta_box',
        'facility',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'add_health_risks_meta_box');

// Render health risks meta box
function render_health_risks_meta_box($post) {
    $facility_type = get_post_meta($post->ID, 'facility_type', true);
    $health_risks = get_facility_health_risks($facility_type);
    
    wp_nonce_field('health_risks_meta_box', 'health_risks_meta_box_nonce');
    ?>
    <div class="health-risks-container">
        <p><strong>Facility Type:</strong> <?php echo esc_html(ucfirst($facility_type)); ?></p>
        
        <?php if (empty($health_risks)): ?>
            <p>No specific health risks identified for this facility type.</p>
        <?php else: ?>
            <?php foreach ($health_risks as $type => $risk): ?>
                <div class="risk-section">
                    <h4><?php echo esc_html(ucfirst($type)); ?> Impact</h4>
                    <p><strong>Risk Level:</strong> 
                        <span class="risk-level-<?php echo esc_attr($risk['level']); ?>">
                            <?php echo esc_html(ucfirst($risk['level'])); ?>
                        </span>
                    </p>
                    <p><?php echo esc_html($risk['description']); ?></p>
                    <ul>
                        <?php foreach ($risk['effects'] as $effect): ?>
                            <li><?php echo esc_html($effect); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <style>
        .health-risks-container {
            padding: 10px;
        }
        .risk-section {
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .risk-level-high {
            color: #dc3545;
            font-weight: bold;
        }
        .risk-level-medium {
            color: #ffc107;
            font-weight: bold;
        }
        .risk-level-low {
            color: #28a745;
            font-weight: bold;
        }
    </style>
    <?php
}

// Save health risks data
function save_health_risks_meta_box($post_id) {
    if (!isset($_POST['health_risks_meta_box_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['health_risks_meta_box_nonce'], 'health_risks_meta_box')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save any custom health risk data here if needed
}
add_action('save_post', 'save_health_risks_meta_box');

// Create demo facilities if none exist
function create_demo_facilities() {
    $existing_facilities = get_posts(array(
        'post_type' => 'facility',
        'posts_per_page' => 1
    ));

    if (empty($existing_facilities)) {
        $demo_facilities = array(
            array(
                'title' => 'Nevada Clean Earth Asphalt Plant',
                'type' => 'industrial',
                'address' => '123 Industrial Way',
                'city' => 'Sparks',
                'state' => 'NV',
                'phone' => '(775) 555-0123',
                'revenue' => '1000000',
                'cleanup_cost' => '1000000'
            ),
            array(
                'title' => 'Northern Tier Solid Waste Authority Facility',
                'type' => 'waste_management',
                'address' => 'US-6',
                'city' => 'Wellsboro',
                'state' => 'PA',
                'phone' => '(570) 724-0145',
                'revenue' => '1000000',
                'cleanup_cost' => '1000000'
            ),
            array(
                'title' => 'Midwest Chemical Processing Plant',
                'type' => 'chemical',
                'address' => '456 Chemical Ave',
                'city' => 'Gary',
                'state' => 'IN',
                'phone' => '(219) 555-0189',
                'revenue' => '2000000',
                'cleanup_cost' => '1500000'
            )
        );

        foreach ($demo_facilities as $facility) {
            $post_data = array(
                'post_title' => $facility['title'],
                'post_type' => 'facility',
                'post_status' => 'publish'
            );

            
$post_id = wp_insert_post($post_data);
if (!empty($lat) && !empty($lng)) {
    update_post_meta($post_id, 'facility_lat', $lat);
    update_post_meta($post_id, 'facility_lng', $lng);
}

            if ($post_id) {
                update_post_meta($post_id, 'facility_type', $facility['type']);
                update_post_meta($post_id, 'facility_address', $facility['address']);
                update_post_meta($post_id, 'facility_city', $facility['city']);
                update_post_meta($post_id, 'facility_state', $facility['state']);
                update_post_meta($post_id, 'facility_phone', $facility['phone']);
                update_post_meta($post_id, 'estimated_annual_revenue', $facility['revenue']);
                update_post_meta($post_id, 'estimated_cleanup_cost', $facility['cleanup_cost']);
            }
        }
    }
}

// Call create_demo_facilities on plugin activation
add_action('admin_init', 'create_demo_facilities');

/**
 * Save all facilities from a search as posts, with logging for troubleshooting.
 * Call facility_save_search_results($facilities) after calling search_facilities().
 *
 * @param array $facilities Array of facilities as returned by search_facilities().
 */
if (!function_exists('facility_save_search_results')) {
function facility_save_search_results($facilities) {
    error_log('facility_save_search_results called with ' . count($facilities) . ' facilities');
    foreach ($facilities as $facility) {
        $slug = sanitize_title($facility['name']);
        error_log('Checking facility: ' . $facility['name'] . ' | Slug: ' . $slug);

        // Check if already exists by post title
        $existing = get_posts([
            'title'       => $facility['name'],
            'post_type'   => 'facility',
            'post_status' => 'any',
            'numberposts' => 1
        ]);
        if ($existing) {
            error_log('Facility already exists: ' . $facility['name']);
            continue;
        }

        // Fetch full details if place_id is available
        $facility_data = [];
        if (!empty($facility['place_id']) && function_exists('get_facility_data_from_places_api')) {
            $facility_data = get_facility_data_from_places_api($facility['place_id']);
        }

        // Fallback to search data if API fails
        if (empty($facility_data)) {
            $facility_data = $facility;
        }

        // Prepare post content (use generate_facility_content if available)
        $post_content = '';
        if (function_exists('generate_facility_content')) {
            $post_content = generate_facility_content($facility_data);
        } else {
            $post_content = "Address: {$facility_data['address']}\n";
        }

        // Save the post
        $post_id = wp_insert_post([
            'post_type'    => 'facility',
            'post_title'   => $facility_data['name'],
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_content' => $post_content,
            'meta_input'   => [
                'address'         => $facility_data['address'] ?? '',
                'lat'             => $facility_data['lat'] ?? '',
                'lng'             => $facility_data['lng'] ?? '',
                'type'            => $facility_data['type'] ?? '',
                'revenue'         => $facility_data['revenue'] ?? '',
                'cleanup_cost'    => $facility_data['cleanup_cost'] ?? '',
                'place_id'        => $facility_data['place_id'] ?? $facility['place_id'] ?? '',
                'pollutants'      => $facility_data['pollutants'] ?? '',
                'photo_url'       => !empty($facility_data['photos'][0]) ? $facility_data['photos'][0] : '',
                'phone'           => $facility_data['phone'] ?? '',
                'website'         => $facility_data['website'] ?? '',
                'impact_score'    => $facility_data['impact_score'] ?? '',
                'rating'          => $facility_data['rating'] ?? '',
                'reviews'         => $facility_data['reviews'] ?? '',
                'environmental_impact' => $facility_data['environmental_impact'] ?? '',
            ]
        ]);
        // Save the full facility_data array for template compatibility
        if ($post_id && !empty($facility_data)) {
            update_post_meta($post_id, 'facility_data', $facility_data);
        }
        error_log('Inserted facility post: ' . $facility_data['name'] . ' (ID: ' . $post_id . ')');
    }
}
}



function cleanyact_enqueue_style_patch() {
    wp_enqueue_style('photon-style', plugin_dir_url(__FILE__) . 'css/photon-style.css');
}
add_action('wp_enqueue_scripts', 'cleanyact_enqueue_style_patch');


function cleanyact10_enqueue_map_scripts() {
    wp_enqueue_style('maplibre-gl', 'https://unpkg.com/maplibre-gl@3.5.2/dist/maplibre-gl.css');
    wp_enqueue_script('maplibre-gl', 'https://unpkg.com/maplibre-gl@3.5.2/dist/maplibre-gl.js', [], null, true);
    wp_enqueue_script('facility-map', plugin_dir_url(__FILE__) . '/js/facility-map.js', ['maplibre-gl'], null, true);

    // Localize script to pass facilityData to JavaScript
    $lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
    $lng = isset($_GET['lng']) ? floatval($_GET['lng']) : 0;
    $radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 50;

    $facilities_in_range = [];

    if ($lat && $lng) {
        $fetched_facilities = search_facilities($lat, $lng, $radius);
        foreach ($fetched_facilities as $facility) {
            if (isset($facility['lat']) && isset($facility['lng']) && isset($facility['name'])) {
                $facilities_in_range[] = array(
                    'lat' => $facility['lat'],
                    'lng' => $facility['lng'],
                    'name' => $facility['name'],
                    'address' => $facility['address'] ?? 'N/A',
                    'type' => $facility['type'] ?? 'N/A'
                );
            }
        }
    } else {
        $args = array(
            'post_type' => 'facility',
            'posts_per_page' => -1
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $lat_meta = get_post_meta(get_the_ID(), 'facility_lat', true);
                $lng_meta = get_post_meta(get_the_ID(), 'facility_lng', true);
                $name = get_the_title();
                if ($lat_meta && $lng_meta) {
                    $facilities_in_range[] = array('lat' => $lat_meta, 'lng' => $lng_meta, 'name' => $name,
                        'address' => get_post_meta(get_the_ID(), 'facility_address', true) ?? 'N/A',
                        'type' => get_post_meta(get_the_ID(), 'facility_type', true) ?? 'N/A'
                    );
                }
            }
        }
        wp_reset_postdata();
    }

    wp_localize_script('facility-map', 'cleanyact10MapData', array(
        'facilityData' => $facilities_in_range
    ));
}
add_action('wp_enqueue_scripts', 'cleanyact10_enqueue_map_scripts');

// === Facility Map Shortcode ===
function cleanyact10_facility_map_shortcode() {
    $lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
    $lng = isset($_GET['lng']) ? floatval($_GET['lng']) : 0;
    $radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 50; // Default radius of 50 miles

    $facilities_in_range = [];

    if ($lat && $lng) {
        // Fetch facilities from the search_facilities function (which uses Google Places API)
        $fetched_facilities = search_facilities($lat, $lng, $radius);

        // Convert fetched facilities to the format expected by the frontend
        foreach ($fetched_facilities as $facility) {
            if (isset($facility['lat']) && isset($facility['lng']) && isset($facility['name'])) {
                $facilities_in_range[] = array(
                    'lat' => $facility['lat'],
                    'lng' => $facility['lng'],
                    'name' => $facility['name'],
                    'address' => $facility['address'] ?? 'N/A',
                    'type' => $facility['type'] ?? 'N/A'
                );
            }
        }
    } else {
        // Original logic: retrieve all facilities if no search parameters
        $args = array(
            'post_type' => 'facility',
            'posts_per_page' => -1
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $lat_meta = get_post_meta(get_the_ID(), 'facility_lat', true);
                $lng_meta = get_post_meta(get_the_ID(), 'facility_lng', true);
                $name = get_the_title();
                if ($lat_meta && $lng_meta) {
                    $facilities_in_range[] = array('lat' => $lat_meta, 'lng' => $lng_meta, 'name' => $name,
                        'address' => get_post_meta(get_the_ID(), 'facility_address', true) ?? 'N/A',
                        'type' => get_post_meta(get_the_ID(), 'facility_type', true) ?? 'N/A'
                    );
                }
            }
        }
        wp_reset_postdata();
    }

    ob_start();
    ?>
    <div id="facility-map" style="width: 100%; height: 500px;"></div>
    <?php
    return ob_get_clean();
}
add_shortcode('cleanyact10_facility_map', 'cleanyact10_facility_map_shortcode');

// === Enqueue Scripts ===
function cleanyact10_maplibre_scripts() {
    wp_enqueue_style('maplibre-gl', 'https://unpkg.com/maplibre-gl@3.5.2/dist/maplibre-gl.css');
    wp_enqueue_script('maplibre-gl', 'https://unpkg.com/maplibre-gl@3.5.2/dist/maplibre-gl.js', [], null, true);
    if (!wp_script_is('facility-map', 'enqueued')) {
        wp_enqueue_script('facility-map-shortcode', plugin_dir_url(__FILE__) . 'js/facility-map.js', ['maplibre-gl'], null, true);
    }
}
add_action('wp_enqueue_scripts', 'cleanyact10_maplibre_scripts');

function enqueue_facility_scripts() {
    wp_enqueue_script(
        'facility-search-js',
        plugins_url('js/facility-search.js', __FILE__),
        array('jquery'), // Dependencies, if any
        '1.0',
        true // Load in footer
    );

    wp_enqueue_script(
        'leaflet-geocoder-js',
        'https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js',
        array(), 
        '1.13.0',
        true
    );
}

add_action('wp_enqueue_scripts', 'enqueue_facility_scripts');

// 1A) Create the cities table on activation
register_activation_hook( __FILE__, 'cra_create_cities_table' );
function cra_create_cities_table() {
  global $wpdb;
  $table   = $wpdb->prefix . 'cra_cities';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name    VARCHAR(200) NOT NULL,
    lat     DECIMAL(10,6) NOT NULL,
    lng     DECIMAL(10,6) NOT NULL,
    country VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    KEY name_idx (name(100))
  ) $charset;";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta( $sql );
}

// 1B) Admin UI to import your CSV
add_action('admin_menu', function(){
  add_submenu_page(
    'tools.php',
    'Import Cities CSV',
    'Import Cities CSV',
    'manage_options',
    'import-cities-csv',
    'cra_cities_csv_import_page'
  );
});
function cra_cities_csv_import_page(){
  echo '<div class="wrap"><h1>Import Cities CSV</h1>';
  echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('cra_csv_import','cra_csv_nonce');
    echo '<input type="file" name="cra_csv_file" accept=".csv" required />';
    submit_button('Import Cities');
  echo '</form>';

  if ( $_POST && check_admin_referer('cra_csv_import','cra_csv_nonce') ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cra_cities';
    if ( ! empty($_FILES['cra_csv_file']['tmp_name']) ) {
      $f      = fopen($_FILES['cra_csv_file']['tmp_name'], 'r');
      $header = fgetcsv($f);
      $count  = 0;
      while( $row = fgetcsv($f) ) {
        $data    = array_combine($header, $row);
        $name    = sanitize_text_field( $data['name']    ?? '' );
        $lat     = floatval(      $data['lat']     ?? 0 );
        $lng     = floatval(      $data['lng']     ?? 0 );
        $country = sanitize_text_field( $data['country'] ?? '' );
        if ( $name && $lat && $lng ) {
          // REPLACE INTO will upsert by primary key
          $wpdb->query( $wpdb->prepare(
            "REPLACE INTO $table (id,name,lat,lng,country)
             VALUES (
               (SELECT id FROM $table WHERE name=%s),
               %s, %f, %f, %s
             )",
             $name, $name, $lat, $lng, $country
          ) );
          $count++;
        }
      }
      fclose($f);
      echo "<div class='updated'><p>Imported/updated {$count} cities.</p></div>";
    }
  }
  echo '</div>';
}


add_action('rest_api_init', function(){
  register_rest_route('cra/v1','/cities', [
    'methods'  => 'GET',
    'callback' => 'cra_search_cities',
    'permission_callback' => '__return_true',
    'args' => [
      'q' => ['required'=>true],
    ],
  ]);
});

function cra_search_cities( WP_REST_Request $req ) {
  global $wpdb;
  $q     = sanitize_text_field( $req->get_param('q') );
  $like  = $wpdb->esc_like( $q ) . '%';
  $table = 'worldcities'; // Use your actual table name

  $rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT city AS name, lat, lng, country 
     FROM $table 
     WHERE city_ascii LIKE %s OR city LIKE %s
     ORDER BY population DESC 
     LIMIT 10",
    $like, $like
  ), ARRAY_A );

  return rest_ensure_response( $rows );
}


