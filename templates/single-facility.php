<?php
get_header();

$facility_id   = get_post_meta(get_the_ID(), 'place_id', true);
$facility_data = get_post_meta(get_the_ID(), 'facility_data', true);
// If this is an imported facility and $facility_data is empty, build it from meta fields
if (get_post_meta(get_the_ID(), '_csv_imported', true) && empty($facility_data)) {
    $facility_data = [
        'name' => get_the_title(),
        'address' => get_post_meta(get_the_ID(), 'street_address', true),
        'lat' => get_post_meta(get_the_ID(), 'lat', true),
        'lng' => get_post_meta(get_the_ID(), 'lng', true),
        'type' => get_post_meta(get_the_ID(), 'industry_sector', true) ?: 'Imported Facility',
        // Add more fields as needed for your template
    ];
}

// Get flag status and note
global $wpdb;
$flag_data = $wpdb->get_row($wpdb->prepare(
    "SELECT is_flagged, admin_note FROM {$wpdb->prefix}facility_meta WHERE facility_id = %d",
    get_the_ID()
));

// Show admin notice for flagged facilities
if ($flag_data && $flag_data->is_flagged) {
    ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">⚠️ This facility has been flagged</h3>
                <?php if ($flag_data->admin_note): ?>
                    <p class="mt-2 text-sm text-yellow-700"><strong>Reason:</strong> <?php echo esc_html($flag_data->admin_note); ?></p>
                <?php endif; ?>
                <?php if (!current_user_can('edit_posts')): ?>
                    <p class="mt-2 text-sm text-yellow-700">This facility may contain incorrect or outdated information.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Only block access for non-admin users
if (!current_user_can('edit_posts') && $flag_data && $flag_data->is_flagged) {
    wp_redirect(home_url());
    exit;
}

// Get badge information
$badges_table = $wpdb->prefix . 'facility_badges';
$badge_data   = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $badges_table WHERE facility_id = %d ORDER BY verification_date DESC LIMIT 1",
    get_the_ID()
));

// Get reviews
$reviews_table = $wpdb->prefix . 'facility_reviews';
$reviews = $wpdb->get_results($wpdb->prepare(
    "SELECT r.*, u.display_name, r.status
     FROM $reviews_table r 
     JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE facility_id = %d
     ORDER BY helpful_votes DESC, review_date DESC",
    get_the_ID()
));

// Calculate average rating
$avg_rating = $wpdb->get_var($wpdb->prepare(
    "SELECT AVG(rating) FROM $reviews_table WHERE facility_id = %d AND status = 'approved'",
    get_the_ID()
));

// If we don't have facility data yet, fetch it from the Places API
if (empty($facility_data) && !empty($facility_id)) {
    $details_url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $facility_id,
        'fields'   => 'name,formatted_address,geometry,type,rating,user_ratings_total,formatted_phone_number,website',
        'key'      => 'AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c'
    ]);

    $details_response = wp_remote_get($details_url);
    if (!is_wp_error($details_response)) {
        $details_data = json_decode(wp_remote_retrieve_body($details_response), true);
        if (isset($details_data['result'])) {
            $result = $details_data['result'];
            $facility_data = [
                'name'      => $result['name'],
                'address'   => $result['formatted_address'],
                'lat'       => $result['geometry']['location']['lat'],
                'lng'       => $result['geometry']['location']['lng'],
                'type'      => strpos(strtolower($result['name']), 'golf') !== false ? 'golf' : 'industrial',
                'phone'     => $result['formatted_phone_number'] ?? '',
                'website'   => $result['website'] ?? '',
                'rating'    => $result['rating'] ?? null,
                'reviews'   => $result['user_ratings_total'] ?? 0
            ];
            
            // Store the data for future use
            update_post_meta(get_the_ID(), 'facility_data', $facility_data);
            wp_update_post([
                'ID'         => get_the_ID(),
                'post_title' => $facility_data['name']
            ]);
        }
    }
}

if ($facility_data || get_post_meta(get_the_ID(), '_csv_imported', true)):
    $name = $facility_data['name'] ?? get_the_title();
    $address = $facility_data['address'] ?? get_post_meta(get_the_ID(), 'street_address', true);
    $badge = $badge_data ? get_badge_info($badge_data->badge_type) : null;
?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <?php 
        $main_image = '';
        if (!empty($facility_data['photos'][0])) {
            $main_image = esc_url($facility_data['photos'][0]);
        }
        ?>
        <div class="h-96 w-full relative flex flex-col items-center justify-center bg-gray-100">
            <?php if ($main_image): ?>
                <img src="<?php echo $main_image; ?>" alt="<?php echo esc_attr($name); ?>" class="w-full h-96 object-cover rounded-t-xl">
            <?php endif; ?>
            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-6">
                <h1 class="text-4xl font-bold text-white"><?php echo esc_html($name); ?></h1>
                <p class="text-gray-200 mt-2"><?php echo esc_html($address); ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 p-8">
            <div class="lg:col-span-2 space-y-8">
                
    
    <?php
/**
 * Template Part: Health Risks
 */

// Get facility type and impact data
$facility_type = get_post_meta(get_the_ID(), 'facility_type', true);
$impact_data = get_post_meta(get_the_ID(), 'impact_data', true) ?: array();

// Default health risks
$health_risks = array(
    'respiratory' => array(
        'title' => 'Respiratory Health',
        'description' => 'Air pollutants can cause or worsen respiratory conditions.',
        'risks' => array(
            'Increased risk of asthma and bronchitis',
            'Reduced lung function',
            'Chronic respiratory conditions'
        )
    ),
    'water' => array(
        'title' => 'Water Quality Impact',
        'description' => 'Contamination of local water sources can affect drinking water quality.',
        'risks' => array(
            'Potential groundwater contamination',
            'Impact on local water supplies',
            'Increased risk of waterborne illnesses'
        )
    ),
    'soil' => array(
        'title' => 'Soil Contamination',
        'description' => 'Industrial processes can lead to soil contamination.',
        'risks' => array(
            'Exposure through direct contact',
            'Food safety concerns for local agriculture',
            'Long-term environmental impact'
        )
    )
);

// Add facility-specific risks
if ($facility_type === 'industrial') {
    $health_risks['chemical'] = array(
        'title' => 'Chemical Exposure',
        'description' => 'Industrial chemicals may pose health risks.',
        'risks' => array(
            'Potential exposure to hazardous materials',
            'Long-term health effects',
            'Impact on vulnerable populations'
        )
    );
}
?>

<div class="bg-red-50 rounded-lg p-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Health Risks Assessment</h2>
        <div class="flex items-center space-x-2">
            <span class="text-2xl">⚠️</span>
            <span class="px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                High Risk Area
            </span>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <?php foreach ($health_risks as $risk): ?>
        <div class="bg-white rounded-lg p-4 shadow">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-gray-900"><?php echo esc_html($risk['title']); ?></h3>
                    <p class="text-sm text-gray-600 mt-1"><?php echo esc_html($risk['description']); ?></p>
                    <ul class="mt-2 space-y-1">
                        <?php foreach ($risk['risks'] as $item): ?>
                        <li class="flex items-start text-sm">
                            <span class="flex-shrink-0 w-4 h-4 mt-1 mr-2">
                                <svg class="text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                            </span>
                            <span class="text-gray-700"><?php echo esc_html($item); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

<div class="mt-6 bg-white rounded-lg p-4 shadow">
    <h3 class="font-bold text-gray-900 mb-2">Vulnerable Populations</h3>
    <p class="text-gray-600">Although all people are affected by toxins and pollution, the following groups may be particularly sensitive to these health risks:</p>
    <ul class="mt-2 grid grid-cols-2 gap-2">
        <li class="flex items-center text-sm text-gray-700">
            <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Children under 12
        </li>
        <li class="flex items-center text-sm text-gray-700">
            <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Elderly individuals
        </li>
        <li class="flex items-center text-sm text-gray-700">
            <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Pregnant women
        </li>
        <li class="flex items-center text-sm text-gray-700">
            <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Those with pre-existing conditions (and healthy individuals)
        </li>
    </ul>
    <a href="https://cleanrecoveryact.com/illnesses-caused-by-pollution" class="mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600">Illness List</a>
</div>

<div class="mt-6 p-4 border border-red-200 rounded-lg">
    <p class="text-sm text-gray-600">
        <strong>Disclaimer:</strong> This health risk assessment is based on available data and general environmental impact patterns. For specific health concerns, please consult with healthcare professionals and local environmental agencies.
    </p>
</div>

</div> 
    
    
  <?php
/**
 * Helper function to format large numbers in shorthand:
 * - ≥ 1,000,000 becomes "X million"
 * - ≥ 1,000 becomes "Yk"
 * - Otherwise, prints the full number
 */
function format_price_shorthand( $number ) {
    if ( $number >= 1000000 ) {
        // Divide by 1,000,000 and remove any trailing "."
        $millions = rtrim( number_format( $number / 1000000, 1 ), '.' );
        return $millions . ' million';
    } elseif ( $number >= 1000 ) {
        // Divide by 1,000 and remove any trailing "."
        $thousands = rtrim( number_format( $number / 1000, 1 ), '.' );
        return $thousands . 'k';
    } else {
        return number_format( $number );
    }
}

// Set hard-coded defaults
$display_revenue     = '$' . format_price_shorthand( 5000000 );   // "$5 million"
$display_cleanup     = '$' . format_price_shorthand( 500000 ) . '+'; // "$500k+"
?>

<div class="bg-gray-50 rounded-lg p-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Facility Overview</h2>
    <div class="grid grid-cols-2 gap-6">
        <?php
        $is_imported = get_post_meta(get_the_ID(), '_csv_imported', true);
        if ($is_imported) {
            // Show imported CSV meta fields
            $fields = [
                'Type' => get_post_meta(get_the_ID(), 'industry_sector', true) ?: 'Imported Facility',
                'Street Address' => get_post_meta(get_the_ID(), 'street_address', true),
                'City' => get_post_meta(get_the_ID(), 'city', true),
                'County' => get_post_meta(get_the_ID(), 'county', true),
                'State' => get_post_meta(get_the_ID(), 'state', true),
                'ZIP' => get_post_meta(get_the_ID(), 'zip', true),
                'Latitude' => get_post_meta(get_the_ID(), 'lat', true),
                'Longitude' => get_post_meta(get_the_ID(), 'lng', true),
                'Primary SIC' => get_post_meta(get_the_ID(), 'primary_sic', true),
                'Primary NAICS' => get_post_meta(get_the_ID(), 'primary_naics', true),
                'Chemical' => get_post_meta(get_the_ID(), 'chemical', true),
                'Carcinogen' => get_post_meta(get_the_ID(), 'carcinogen', true),
                'PBT' => get_post_meta(get_the_ID(), 'pbt', true),
                'Total Releases' => get_post_meta(get_the_ID(), 'total_releases', true),
                'Year' => get_post_meta(get_the_ID(), 'year', true),
            ];
            foreach ($fields as $label => $value) {
                if ($value !== '') {
                    echo '<div><h3 class="text-sm font-medium text-gray-500">' . esc_html($label) . '</h3>';
                    echo '<p class="mt-1 text-lg text-gray-900">' . esc_html($value) . '</p></div>';
                }
            }
        } else {
            // Show the regular facility data (your existing code)
            ?>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Type</h3>
                <p class="mt-1 text-lg text-gray-900">
                    <?php echo esc_html(
                        $facility_data['type'] === 'golf'
                            ? 'Golf Course'
                            : 'Industrial Facility'
                    ); ?>
                </p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Estimated Annual Revenue</h3>
                <p class="mt-1 text-lg text-gray-900">
                    <?php echo esc_html( $display_revenue ); ?>
                </p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Estimated Cleanup Cost</h3>
                <p class="mt-1 text-lg text-gray-900">
                    <?php echo esc_html( $display_cleanup ); ?>
                </p>
            </div>
            <?php if ( ! empty( $facility_data['phone'] ) ) : ?>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Phone</h3>
                <p class="mt-1 text-lg text-gray-900">
                    <?php echo esc_html( $facility_data['phone'] ); ?>
                </p>
            </div>
            <?php endif;
        }
        ?>
    </div>
</div>



                <!-- Environmental Impact -->
                <?php
                $is_imported = get_post_meta(get_the_ID(), '_csv_imported', true);
                if ($is_imported) {
                    $releases = floatval(get_post_meta(get_the_ID(), 'total_releases', true));
                    $carcinogen = strtolower(get_post_meta(get_the_ID(), 'carcinogen', true)) === 'yes' ? 1 : 0;
                    $pbt = strtolower(get_post_meta(get_the_ID(), 'pbt', true)) === 'yes' ? 1 : 0;
                    $score = 0;
                    if ($releases > 0) $score += min(50, $releases / 10000 * 50);
                    if ($carcinogen) $score += 30;
                    if ($pbt) $score += 20;
                    $score = min(100, round($score));
                } else {
                    $score = $facility_data['impact_score'] ?? 0;
                }
                ?>
                <div class="bg-red-50 rounded-lg p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-4">Environmental Impact</h2>
                    <?php if ($facility_data['type'] === 'golf'): ?>
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Water Usage</h3>
                            <p class="mt-1 text-lg text-gray-900"><?php echo esc_html($facility_data['water_usage'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Pesticide Use</h3>
                            <p class="mt-1 text-lg text-gray-900"><?php echo esc_html($facility_data['pesticide_use'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-sm font-medium text-gray-500">Impact Score</h3>
                                <span class="text-lg font-bold <?php echo $score > 66 ? 'text-red-600' : ($score > 33 ? 'text-yellow-600' : 'text-green-600'); ?>">
                                    <?php echo $score; ?>%
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="h-2.5 rounded-full <?php echo $score > 66 ? 'bg-red-600' : ($score > 33 ? 'bg-yellow-500' : 'bg-green-500'); ?>" 
                                     style="width: <?php echo esc_attr($score); ?>%">
                                </div>
                            </div>
                            <!-- Button to view scoring system -->
                            <div class="mt-4">
                                <a href="https://cleanrecoveryact.com/pollution-scoring-system/" target="_blank" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-sm font-medium">Learn How Score is Calculated</a>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="space-y-6">
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-sm font-medium text-gray-500">Impact Score</h3>
                                <span class="text-lg font-bold <?php echo $score > 66 ? 'text-red-600' : ($score > 33 ? 'text-yellow-600' : 'text-green-600'); ?>">
                                    <?php echo $score; ?>%
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="h-2.5 rounded-full <?php echo $score > 66 ? 'bg-red-600' : ($score > 33 ? 'bg-yellow-500' : 'bg-green-500'); ?>" 
                                     style="width: <?php echo esc_attr($score); ?>%">
                                </div>
                            </div>
                            <!-- Button to view scoring system -->
                            <div class="mt-4">
                                <a href="https://cleanrecoveryact.com/pollution-scoring-system/" target="_blank" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-sm font-medium">Learn How Score is Calculated</a>
                            </div>
                        </div>
                        <?php if (!empty($facility_data['environmental_impact'])): ?>
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Environmental Concerns</h3>
                            <ul class="space-y-2">
                                <?php foreach ($facility_data['environmental_impact'] as $impact): ?>
                                <li class="flex items-start">
                                    <span class="flex-shrink-0 w-4 h-4 mt-1 mr-2">
                                        <svg class="text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                        </svg>
                                    </span>
                                    <span class="text-gray-700"><?php echo esc_html($impact); ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ← New Section: Health Risks -->
                <div class="mt-6">
                    <?php get_template_part('templates/facility-health-risks'); ?>
                </div>

                <!-- Nearby Schools -->
                <?php if (!empty($facility_data['schools_nearby'])): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-4">Nearby Schools</h2>
                    <div class="space-y-4">
                        <?php foreach ($facility_data['schools_nearby'] as $school): ?>
                        <div class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                            <h3 class="font-medium text-gray-900"><?php echo esc_html($school['name']); ?></h3>
                            <p class="text-sm text-gray-500"><?php echo esc_html($school['address']); ?></p>
                            <p class="text-sm text-gray-500">Distance: <?php echo number_format($school['distance'], 1); ?> miles</p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Badge Information -->
                <?php if ($badge): ?>
                <div class="bg-<?php echo $badge['color']; ?>-50 rounded-lg p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-4">Environmental Status</h2>
                    <div class="space-y-4">
                        <div class="flex items-center space-x-3">
                            <div class="text-4xl"><?php echo $badge['emoji']; ?></div>
                            <div>
                                <h3 class="font-bold text-lg"><?php echo $badge['name']; ?></h3>
                                <p class="text-gray-600"><?php echo $badge['description']; ?></p>
                            </div>
                        </div>
                        <?php if ($badge_data->verification_notes): ?>
                        <div class="mt-4">
                            <h4 class="font-medium text-gray-700">Verification Notes</h4>
                            <p class="text-gray-600"><?php echo nl2br(esc_html($badge_data->verification_notes)); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($badge_data->evidence_url): ?>
                        <div class="mt-4">
                            <a href="<?php echo esc_url($badge_data->evidence_url); ?>" 
                               target="_blank"
                               class="text-blue-600 hover:text-blue-800">
                                View Evidence Documentation →
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Badge Embed Code -->
                <div class="bg-gray-50 rounded-lg p-6 mt-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Embed Badge</h2>
                    <p class="text-gray-600 mb-4">Copy this code to display your environmental badge on your website:</p>
                    <div class="bg-gray-100 p-4 rounded-lg">
                        <code class="text-sm text-gray-800 break-all">
                            <?php
                            $embed_code = sprintf(
                                '<a href="%s" title="%s">%s %s - Verified by Clean Recovery Act</a>',
                                get_permalink(),
                                esc_attr($badge['name']),
                                $badge['emoji'],
                                esc_html($badge['name'])
                            );
                            echo htmlspecialchars($embed_code);
                            ?>
                        </code>
                        <button onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent)"
                                class="mt-2 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">
                            Copy Code
                        </button>
                    </div>
                </div>
                <?php endif; ?>

               <?php
// ——————————————————————————————————————————————
// 1) Fetch local officials via Google Civic API
// ——————————————————————————————————————————————

$facility_name    = get_the_title();
$facility_url     = get_permalink();
$facility_address = urlencode( $facility_data['address'] );
$civic_api_key    = 'AIzaSyAXWzTJ7-iEdSr5ydZsvqo1IBxAWEDUxYk';

$civic_url      = "https://www.googleapis.com/civicinfo/v2/representatives?key={$civic_api_key}&address={$facility_address}";
$civic_response = wp_remote_get( $civic_url );
$officials      = [];

if ( ! is_wp_error( $civic_response ) ) {
    $body = json_decode( wp_remote_retrieve_body( $civic_response ), true );
    if ( isset( $body['offices'], $body['officials'] ) ) {
        foreach ( $body['offices'] as $office ) {
            foreach ( $office['officialIndices'] as $idx ) {
                $off = $body['officials'][ $idx ];
                $officials[] = [
                    'name'   => $off['name'],
                    'office' => $office['name'],
                    'phones' => $off['phones'][0] ?? '',
                    'urls'   => $off['urls'][0]   ?? '',
                ];
            }
        }
    }
}
?>

<!-- ——————————————————————————————————————————————
     2) TAKE ACTION SECTION
—————————————————————————————————————————————— -->
<div class="bg-blue-50 rounded-xl p-8 mt-6 shadow-xl max-w-4xl mx-auto">
  <h4 class="text-3xl font-bold text-blue-800 mb-6">Take Action</h4>

  <ul class="space-y-6 text-gray-800 text-base leading-relaxed">
    <!-- Step 1 -->
    <li>
      <span class="font-semibold text-blue-900">
        1. Call and ask what they're doing to reduce pollution:
      </span><br>
      <?php if ( ! empty( $facility_data['phone'] ) ): ?>
        <a href="tel:<?php echo esc_attr( $facility_data['phone'] ); ?>"
           class="text-blue-700 underline">
          <?php echo esc_html( $facility_data['phone'] ); ?>
        </a>
      <?php else: ?>
        <span class="italic">(No phone number available)</span>
      <?php endif; ?>
    </li>

    <!-- Step 2 -->
   <li>
  <span class="font-semibold text-blue-900">2. Leave public feedback:</span>
  <ul class="mt-2 ml-6 space-y-2">
    <!-- Google Review Link -->
    <li>
      <a
        href="https://www.google.com/search?q=<?php echo urlencode( $facility_name . ' review' ); ?>"
        target="_blank"
        class="flex items-center text-blue-700 hover:text-blue-800 underline text-lg"
      >
        <img
          src="https://upload.wikimedia.org/wikipedia/commons/thumb/c/c1/Google_%22G%22_logo.svg/768px-Google_%22G%22_logo.svg.png"
          alt="Google G"
          class="w-6 h-6 mr-2 flex-shrink-0"
        />
        Write a review on Google
      </a>
    </li>
    <!-- Inline Page Comment Link -->
    
  </ul>
</li>


    <!-- Step 3 -->
    <li>
      <span class="font-semibold text-blue-900">3. Contact your local officials:</span>
      <?php if ( $officials ): ?>
        <ul class="mt-2 space-y-3 ml-4">
          <?php foreach ( $officials as $rep ): ?>
          <li>
            <strong><?php echo esc_html( $rep['name'] ); ?></strong> —
            <em><?php echo esc_html( $rep['office'] ); ?></em><br>
            <?php if ( $rep['phones'] ): ?>
              📞 <a href="tel:<?php echo esc_attr( $rep['phones'] ); ?>"
                    class="text-blue-700 underline">
                <?php echo esc_html( $rep['phones'] ); ?>
              </a>
            <?php endif; ?>
            <?php if ( $rep['urls'] ): ?>
              &nbsp;🌐 <a href="<?php echo esc_url( $rep['urls'] ); ?>"
                         target="_blank"
                         class="text-blue-700 underline">
                Website
              </a>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <span class="italic"></span>
      <?php endif; ?>
    </li>

    <!-- Step 4 -->
    <li>
      <span class="font-semibold text-blue-900">4. Sign the petition:</span><br>
      <a href="https://cleanrecoveryact.com/sign-the-petition/"
         target="_blank"
         class="inline-block px-6 py-2 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition">
        Sign the Petition →
      </a>
    </li>

    <!-- Step 5 -->
   <!-- Step 5: Share on social media -->
<li>
  <span class="font-semibold text-blue-900">5. Share on social media:</span>
  <div class="mt-2 ml-2 text-lg grid grid-cols-2 sm:grid-cols-3 gap-2">
    <!-- Facebook -->
    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( $facility_url ); ?>"
       target="_blank"
       class="flex items-center whitespace-nowrap text-blue-700 hover:text-blue-900">
      <img src="https://cdn-icons-png.flaticon.com/24/733/733547.png"
           class="w-5 h-5 mr-1" alt="Facebook" />
      Facebook
    </a>
    <!-- Twitter -->
    <a href="https://x.com/intent/tweet?text=<?php echo urlencode( $facility_name . ' is polluting. Learn more: ' . $facility_url ); ?>"
       target="_blank"
       class="flex items-center whitespace-nowrap text-blue-700 hover:text-blue-900">
      <img src="https://cdn-icons-png.flaticon.com/24/733/733579.png"
           class="w-5 h-5 mr-1" alt="Twitter" />
      Twitter
    </a>
    <!-- LinkedIn -->
    <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode( $facility_url ); ?>&title=<?php echo urlencode( $facility_name ); ?>"
       target="_blank"
       class="flex items-center whitespace-nowrap text-blue-700 hover:text-blue-900">
      <img src="https://cdn-icons-png.flaticon.com/24/733/733561.png"
           class="w-5 h-5 mr-1" alt="LinkedIn" />
      LinkedIn
    </a>
  </div>
</li>


    <!-- Step 6 -->
    <li>
      <span class="font-semibold text-blue-900">6. Plant trees to restore soil:</span>
      Around impacted areas, plant bioremediation trees and shrubs to absorb toxins and restore soil health.
      <a href="https://cleanrecoveryact.com/bioremediation-resources/"
         target="_blank"
         class="ml-1 text-blue-700 underline">
        Learn how →
      </a>
    </li>
  </ul>
</div>


<!-- ——————————————————————————————————————————————
     3) REVIEWS / COMMENT SECTION
—————————————————————————————————————————————— -->
<div class="bg-white rounded-lg shadow p-6 mt-10 max-w-4xl mx-auto">

  <!-- Header + Toggle Button -->
<div class="flex flex-col space-y-4 md:flex-row md:space-y-0 md:items-center md:justify-between mb-6">    <h2 class="text-2xl font-bold text-gray-800">Submit a Comment</h2>
    <button id="show-review-form"
            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">
      Write a Comment
    </button>
  </div>

  <!-- Review Form (Visible to All) -->
  <div id="review-form" class="hidden mb-8 bg-gray-50 rounded-lg p-6 shadow-inner">
    <form method="post" class="space-y-4">
      <?php wp_nonce_field( 'submit_review', 'review_nonce' ); ?>
      <input type="hidden" name="facility_id" value="<?php echo get_the_ID(); ?>">

      <!-- Rating -->
      <div>
        <label class="block text-gray-700 font-semibold mb-2">Your Rating</label>
        <div class="flex space-x-2" id="rating-stars">
          <?php for ( $i = 1; $i <= 5; $i++ ): ?>
            <button type="button"
                    class="star-button text-gray-300 hover:text-yellow-400 focus:outline-none"
                    data-rating="<?php echo $i; ?>">
              <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
              </svg>
            </button>
          <?php endfor; ?>
          <input type="hidden" name="rating" id="selected-rating" required>
        </div>
      </div>

      <!-- Comment Text -->
      <div>
        <label for="review_text" class="block text-gray-700 font-semibold mb-2">
          Your Comment
        </label>
        <textarea id="review_text"
                  name="review_text"
                  rows="4"
                  class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"
                  placeholder="Share your thoughts..."
                  required></textarea>
      </div>

      <!-- Submit Button -->
      <div class="text-end">
        <button type="submit"
                class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">
          Submit Review
        </button>
      </div>
    </form>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const reviewForm      = document.getElementById('review-form');
    const toggleBtn       = document.getElementById('show-review-form');
    const stars           = document.querySelectorAll('.star-button');
    const selectedRating  = document.getElementById('selected-rating');

    // Toggle form visibility
    toggleBtn.addEventListener('click', () => {
      reviewForm.classList.toggle('hidden');
      if (!reviewForm.classList.contains('hidden')) {
        reviewForm.scrollIntoView({ behavior: 'smooth' });
      }
    });

    // Star rating logic
    stars.forEach(star => {
      star.addEventListener('click', () => {
        const r = parseInt(star.dataset.rating);
        selectedRating.value = r;
        stars.forEach(s => {
          const val = parseInt(s.dataset.rating);
          s.classList[val <= r ? 'add' : 'remove']('text-yellow-400');
          s.classList[val <= r ? 'remove' : 'add']('text-gray-300');
        });
      });
    });

    // Prevent submit without rating
    document.querySelector('#review-form form').addEventListener('submit', function(e) {
      if (!selectedRating.value) {
        e.preventDefault();
        alert('Please select a rating.');
      }
    });
  });
  </script>

  <!-- Reviews List -->
  <?php if ( $reviews ): ?>
    <div class="space-y-6">
      <?php foreach ( $reviews as $review ): ?>
        <div class="border-b border-gray-200 pb-6 last:border-0">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center space-x-2">
              <span class="font-medium text-gray-900">
                <?php echo esc_html( $review->display_name ); ?>
              </span>
              <span class="text-gray-500">•</span>
              <span class="text-gray-500">
                <?php echo human_time_diff( strtotime( $review->review_date ), current_time('timestamp') ); ?> ago
              </span>
            </div>
            <div class="flex">
              <?php for ( $i = 1; $i <= 5; $i++ ): ?>
                <svg class="w-5 h-5 <?php echo ( $i <= intval($review->rating) ) ? 'text-yellow-400' : 'text-gray-300'; ?>"
                     fill="currentColor" viewBox="0 0 20 20">
                  <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                </svg>
              <?php endfor; ?>
            </div>
          </div>
          <p class="text-gray-600"><?php echo nl2br( esc_html( $review->review_text ) ); ?></p>
          <?php if ( intval($review->helpful_votes) > 0 ): ?>
            <div class="mt-2 text-sm text-gray-500">
              <?php echo intval($review->helpful_votes); ?> people found this helpful
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="text-center py-8">
      <p class="text-gray-600">No comments yet. Be the first to comment!</p>
    </div>
  <?php endif; ?>
</div>
</div>

            <div class="space-y-8">
                <!-- Map -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div id="facility-map" class="h-64 w-full" 
                         data-lat="<?php echo esc_attr($facility_data['lat'] ?? ''); ?>"
                         data-lng="<?php echo esc_attr($facility_data['lng'] ?? ''); ?>"
                         data-name="<?php echo esc_attr($facility_data['name'] ?? ''); ?>">
                    </div>
                </div>
                
 
                

                
<!-- Air Quality Section -->
<div id="aqi-info" class="max-w-3xl mx-auto mt-10 p-6 bg-blue-50 border border-blue-300 rounded-lg shadow-md text-slate-800 font-sans">
  <div class="flex items-center justify-between mb-4">
    <h3 class="text-xl font-semibold">🌫️ Air Quality Index (Live)</h3>
    <button onclick="document.getElementById('aqi-explanation').scrollIntoView({ behavior: 'smooth' });"
      class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700 transition">
      📘 How AQI is Measured
    </button>
  </div>

  <?php
    $lat = $facility_data['lat'] ?? '';
    $lng = $facility_data['lng'] ?? '';
    $lat_str = (string) $lat;
    $lng_str = (string) $lng;

    $aqi_token = '29007ffc767e4905f5fcb4c8fd4d62e79a1a7174';
    $url = "https://api.waqi.info/feed/geo:{$lat_str};{$lng_str}/?token={$aqi_token}";
    $resp = wp_remote_get($url);

    if (is_wp_error($resp)) : ?>
      <p class="text-red-500">Error retrieving air quality data.</p>
    <?php else :
      $data = json_decode(wp_remote_retrieve_body($resp), true);

      if (isset($data['status']) && $data['status'] === 'ok' && !empty($data['data'])) :
        $aqi_data = $data['data'];
        $aqi = $aqi_data['aqi'] ?? 'N/A';
        $dominent_pol = $aqi_data['dominentpol'] ?? 'N/A';
        $city_name = $aqi_data['city']['name'] ?? 'N/A';
        $time_iso = $aqi_data['time']['iso'] ?? 'N/A';

        $pm25 = $aqi_data['iaqi']['pm25']['v'] ?? 'N/A';
        $pm10 = $aqi_data['iaqi']['pm10']['v'] ?? 'N/A';
        $o3 = $aqi_data['iaqi']['o3']['v'] ?? 'N/A';
        $no2 = $aqi_data['iaqi']['no2']['v'] ?? 'N/A';
        $so2 = $aqi_data['iaqi']['so2']['v'] ?? 'N/A';
        $co = $aqi_data['iaqi']['co']['v'] ?? 'N/A';
        $temp = $aqi_data['iaqi']['t']['v'] ?? 'N/A';
        $humidity = $aqi_data['iaqi']['h']['v'] ?? 'N/A';
        $pressure = $aqi_data['iaqi']['p']['v'] ?? 'N/A';
        $wind = $aqi_data['iaqi']['w']['v'] ?? 'N/A';

        $aqi_levels = [
          'Good' => [0, 50, '#00e400'],
          'Moderate' => [51, 100, '#ffff00'],
          'Unhealthy for Sensitive Groups' => [101, 150, '#ff7e00'],
          'Unhealthy' => [151, 200, '#ff0000'],
          'Very Unhealthy' => [201, 300, '#8f3f97'],
          'Hazardous' => [301, 999, '#7e0023'],
        ];

        $aqi_category = 'N/A';
        $aqi_color = '#ccc';
        foreach ($aqi_levels as $label => [$min, $max, $color]) {
          if ($aqi >= $min && $aqi <= $max) {
            $aqi_category = $label;
            $aqi_color = $color;
            break;
          }
        }

        function hex2rgba($hex, $alpha = 0.1) {
          $hex = str_replace('#', '', $hex);
          $r = hexdec(substr($hex, 0, 2));
          $g = hexdec(substr($hex, 2, 2));
          $b = hexdec(substr($hex, 4, 2));
          return "rgba($r,$g,$b,$alpha)";
        }

        $bgTint = hex2rgba($aqi_color, 0.15);
  ?>

    <p class="text-sm text-gray-600 mb-4">
      Last updated: <strong><?= esc_html($time_iso) ?></strong><br>
      Reporting area: <strong><?= esc_html($city_name) ?></strong>
    </p>

    <div class="p-4 rounded-md mb-4" style="background: <?= $bgTint ?>; border-left: 6px solid <?= $aqi_color ?>;">
      <h4 class="text-lg font-semibold">Main Pollutant: <?= esc_html(strtoupper($dominent_pol)) ?></h4>
      <div class="flex justify-between items-center mt-2">
        <span class="text-4xl font-bold"><?= esc_html($aqi) ?></span>
        <span class="uppercase text-sm font-medium tracking-wide">Category: <?= esc_html($aqi_category) ?></span>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2 text-sm">
      <div><strong>PM2.5:</strong> <?= esc_html($pm25) ?> µg/m³</div>
      <div><strong>PM10:</strong> <?= esc_html($pm10) ?> µg/m³</div>
      <div><strong>O₃:</strong> <?= esc_html($o3) ?> ppb</div>
      <div><strong>NO₂:</strong> <?= esc_html($no2) ?> ppb</div>
      <div><strong>SO₂:</strong> <?= esc_html($so2) ?> ppb</div>
      <div><strong>CO:</strong> <?= esc_html($co) ?> ppm</div>
      <div><strong>Temperature:</strong> <?= esc_html($temp) ?> °C</div>
      <div><strong>Humidity:</strong> <?= esc_html($humidity) ?> %</div>
      <div><strong>Pressure:</strong> <?= esc_html($pressure) ?> hPa</div>
      <div><strong>Wind:</strong> <?= esc_html($wind) ?> m/s</div>
    </div>


  <?php else: ?>
    <p class="text-gray-600">No AQI data available for this location.</p>
  <?php endif; ?>
<?php endif; ?>
</div>

<!-- Measurement Info Section -->
<div id="aqi-explanation" style="max-width: 700px; margin: 4rem auto; padding: 24px; background: #ffffff; border: 1px solid #ddd; border-radius: 8px; font-family: system-ui, sans-serif;">
  <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 16px; color: #1e293b;">📘 How Air Quality Measured</h2>
  <p style="color: #334155; line-height: 1.6;">
    The Air Quality Index (AQI) is a standardized indicator used globally to communicate how polluted the air currently is or forecast to become.
  </p>

  <div style="margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
    <div style="background: #00e400; color: #000; padding: 12px; border-radius: 6px; font-weight: 500;">🟢 0–50 Good</div>
    <div style="background: #ffff00; color: #000; padding: 12px; border-radius: 6px; font-weight: 500;">🟡 51–100 Moderate</div>
    <div style="background: #ff7e00; color: #fff; padding: 12px; border-radius: 6px; font-weight: 500;">🟠 101–150 Unhealthy for Sensitive Groups</div>
    <div style="background: #ff0000; color: #fff; padding: 12px; border-radius: 6px; font-weight: 500;">🔴 151–200 Unhealthy</div>
    <div style="background: #8f3f97; color: #fff; padding: 12px; border-radius: 6px; font-weight: 500;">🟣 201–300 Very Unhealthy</div>
    <div style="background: #7e0023; color: #fff; padding: 12px; border-radius: 6px; font-weight: 500;">🟥 301+ Hazardous</div>
  </div>

  <div style="margin-top: 30px;">
    <h3 style="font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 8px;">What Are the Pollutants?</h3>
    <ul style="padding-left: 20px; color: #475569; line-height: 1.6;">
      <li><strong>PM2.5 & PM10:</strong> Fine and coarse particulate matter that can penetrate lungs.</li>
      <li><strong>O₃ (Ozone):</strong> Harmful ground-level ozone from sunlight reacting with pollutants.</li>
      <li><strong>NO₂, SO₂, CO:</strong> Gases from vehicles, factories, and fires with respiratory impacts.</li>
    </ul>
    <p style="margin-top: 10px; font-size: 14px; color: #475569;">Source: <a href="https://aqicn.org" target="_blank" style="color: #2563eb; text-decoration: underline;">aqicn.org</a></p>
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

                <!-- Ratings -->
                <?php if (!empty($facility_data['rating'])): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Ratings & Reviews</h2>
                    <div class="text-center">
                        <div class="text-5xl font-bold text-gray-900"><?php echo number_format($facility_data['rating'], 1); ?></div>
                        <div class="flex justify-center text-yellow-400 my-2">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?php if ($i <= $facility_data['rating']): ?>
                                    <svg class="h-5 w-5 fill-current" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                <?php else: ?>
                                    <svg class="h-5 w-5 fill-current text-gray-300" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <div class="text-sm text-gray-500"><?php echo number_format($facility_data['reviews']); ?> reviews</div>
                        
                        <a href="https://www.google.com/search?q=<?php echo urlencode(get_the_title() . ' review'); ?>"
   target="_blank"
   class="inline-block mt-4 px-4 py-2 bg-green-600 text-white font-bold rounded hover:bg-green-700 transition">
  Leave a Google Review
</a>

                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Community Concern CTA -->
<div class="bg-blue-50 border border-blue-100 rounded-xl p-6 shadow-sm mt-8">
  <h2 class="text-2xl font-bold text-blue-900 mb-2">Report a Community Concern</h2>
  <p class="text-sm text-blue-700 mb-4">
    Notice pollution, odors, or unsafe activity? Let us know and help keep your area safe.
  </p>

  <?php if (is_singular('facility')) : ?>
    <a href="<?php echo site_url('/community-reporting/'); ?>"
       class="inline-block w-full sm:w-auto text-center px-6 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold text-sm rounded shadow transition duration-200">
      Report This Facility
    </a>
  <?php endif; ?>
</div>

               <!-- Community Discussion -->

<!--div class="bg-white rounded-lg shadow p-6 mt-8" id="community-discussion">
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Community Discussion</h2>

    <?php if (comments_open() || get_comments_number()): ?>
        <?php if (get_comments_number() > 0): ?>
            <div class="mb-8">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo get_comments_number(); ?> Comment<?php echo get_comments_number() > 1 ? 's' : ''; ?></h3>

                <?php
                wp_list_comments([
                    'style'       => 'div',
                    'short_ping'  => true,
                    'avatar_size' => 50,
                    'callback'    => function($comment, $args, $depth) {
                        ?>
                        <div id="comment-<?php comment_ID(); ?>" class="border-b border-gray-200 mb-6 pb-4">
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    <?php echo get_avatar($comment, $args['avatar_size'], '', '', ['class' => 'rounded-full']); ?>
                                </div>
                                <div>
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="font-semibold text-gray-800"><?php echo get_comment_author(); ?></span>
                                        <span class="text-gray-500 text-sm"><?php echo human_time_diff(get_comment_time('U'), current_time('timestamp')); ?> ago</span>
                                    </div>
                                    <div class="text-gray-700 text-sm leading-relaxed">
                                        <?php 
                                            if ($comment->comment_approved == '0') {
                                                echo '<em class="text-yellow-600">Your comment is awaiting moderation.</em><br>';
                                            }
                                            comment_text();
                                        ?>
                                    </div>
                                    <div class="mt-2 text-sm">
                                        <?php comment_reply_link(array_merge($args, [
                                            'depth'     => $depth,
                                            'max_depth' => $args['max_depth'],
                                            'before'    => '',
                                            'after'     => '',
                                            'reply_text'=> '<span class="text-blue-600 hover:underline">Reply</span>'
                                        ])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                ]);
                ?>

                <?php if (get_comment_pages_count() > 1 && get_option('page_comments')): ?>
                    <nav class="comment-navigation flex justify-between mt-4">
                        <div class="text-blue-600 hover:text-blue-800"><?php previous_comments_link('&larr; Older Comments'); ?></div>
                        <div class="text-blue-600 hover:text-blue-800"><?php next_comments_link('Newer Comments &rarr;'); ?></div>
                    </nav>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="text-center text-gray-500 mb-6">
                No comments yet. Be the first to join the discussion!
            </div>
        <?php endif; ?>

        <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Leave a Comment</h3>
            <?php
            comment_form([
                'class_submit'  => 'bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700',
                'title_reply'   => '',
                'comment_field' => '
                    <p class="mb-4">
                        <label for="comment" class="block text-sm font-medium text-gray-700 mb-1">Your Comment</label>
                        <textarea id="comment" name="comment" rows="4" 
                            class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                            required></textarea>
                    </p>'
            ]);
            ?>
        </div>

    <?php else: ?>
        <p class="text-gray-600">Comments are closed for this facility.</p>
    <?php endif; ?>
</div-->



            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Swiper for photo gallery
    if (document.querySelector('.swiper-container')) {
        new Swiper('.swiper-container', {
            loop: true,
            pagination: {
                el: '.swiper-pagination',
                clickable: true
            },
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev'
            }
        });
    }

    // Initialize Leaflet map
    const mapDiv = document.getElementById('facility-map');
    if (!mapDiv) return;

    const lat = parseFloat(mapDiv.dataset.lat);
    const lng = parseFloat(mapDiv.dataset.lng);
    const name = mapDiv.dataset.name;

    if (isNaN(lat) || isNaN(lng)) {
        console.error('Invalid facility coordinates');
        return;
    }

    // Initialize map
    const map = L.map('facility-map').setView([lat, lng], 15);

    // Add OpenStreetMap tile layer
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a>'
    }).addTo(map);

    // Add marker for the facility
    const marker = L.marker([lat, lng], {
        icon: L.divIcon({
            className: 'facility-marker',
            html: '<div class="marker-pin bg-blue-500"></div>',
            iconSize: [30, 30],
            iconAnchor: [15, 30]
        })
    })
    .addTo(map)
    .bindPopup(`
        <div class="p-2">
            <h3 class="font-bold text-lg mb-2">${name}</h3>
            <p class="text-sm">Facility location</p>
        </div>
    `)
    .openPopup();

    // Add a circle to show the facility's radius (if available)
    const radius = parseFloat(mapDiv.dataset.radius);
    if (!isNaN(radius)) {
        L.circle([lat, lng], {
            radius: radius * 1609.34, // Convert miles to meters
            color: '#3B82F6',
            fillColor: '#93C5FD',
            fillOpacity: 0.2,
            weight: 2
        }).addTo(map);
    }
});
</script>

<style>
.facility-marker {
    position: relative;
}

.marker-pin {
    width: 30px;
    height: 30px;
    border-radius: 50% 50% 50% 0;
    position: relative;
    transform: rotate(-45deg);
    left: 50%;
    top: 50%;
    margin: -15px 0 0 -15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.marker-pin::after {
    content: '';
    width: 14px;
    height: 14px;
    margin: 8px 0 0 8px;
    background: #fff;
    position: absolute;
    border-radius: 50%;
}
</style>

<?php
else: ?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="bg-white rounded-xl shadow-lg p-8 text-center">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Facility Not Found</h2>
        <p class="text-gray-600">The requested facility could not be found. Please try searching again.</p>
        <a href="<?php echo home_url('/'); ?>" 
           class="inline-block mt-6 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Return to Search
        </a>
    </div>
</div>






<?php 
endif;

// Add review management section for admins
if (current_user_can('edit_posts')): ?>
    <div class="bg-white rounded-lg shadow p-6 mt-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Review Management</h2>
        
        <?php if (empty($reviews)): ?>
            <p class="text-gray-600">No reviews yet.</p>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($reviews as $review): ?>
                    <div class="border rounded-lg p-4 <?php echo $review->status === 'pending' ? 'bg-yellow-50' : 'bg-white'; ?>">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="font-medium"><?php echo esc_html($review->display_name); ?></p>
                                <div class="flex items-center mt-1">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <svg class="h-5 w-5 <?php echo $i <= $review->rating ? 'text-yellow-400' : 'text-gray-300'; ?>" 
                                             fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    <?php endfor; ?>
                                </div>
                                <p class="mt-2 text-gray-600"><?php echo esc_html($review->review_text); ?></p>
                                <p class="mt-1 text-sm text-gray-500">
                                    Posted <?php echo human_time_diff(strtotime($review->review_date), current_time('timestamp')); ?> ago
                                </p>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($review->status === 'pending'): ?>
                                    <form method="post" class="inline">
                                        <?php wp_nonce_field('review_action', 'review_nonce'); ?>
                                        <input type="hidden" name="review_id" value="<?php echo $review->id; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" 
                                                class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700">
                                            Approve
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="inline">
                                    <?php wp_nonce_field('review_action', 'review_nonce'); ?>
                                    <input type="hidden" name="review_id" value="<?php echo $review->id; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" 
                                            class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700"
                                            onclick="return confirm('Are you sure you want to delete this review?')">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php if ($review->status === 'pending'): ?>
                            <div class="mt-2 inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-sm rounded">
                                Pending Approval
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Add this right after the facility overview section -->
<!--<div class="bg-white rounded-lg shadow p-6 mt-4">-->
<!--    <div class="flex justify-between items-center">-->
<!--        <h2 class="text-2xl font-bold text-gray-800">Facility Information</h2>-->
<!--        <button id="show-flag-modal" -->
<!--                class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors">-->
<!--            Report Incorrect Information-->
<!--        </button>-->
<!--    </div>-->
    


    <!--<?php if (current_user_can('edit_posts')): ?>-->
    <!--<form id="update-facility-form" class="mt-6 space-y-4" data-facility-id="<?php echo get_the_ID(); ?>">-->
    <!--    <div>-->
    <!--        <label for="facility-revenue" class="block text-sm font-medium text-gray-700">Annual Revenue</label>-->
    <!--        <input type="text" -->
    <!--               id="facility-revenue" -->
    <!--               name="revenue" -->
    <!--               value="<?php echo esc_attr($facility_data['revenue'] ?? ''); ?>"-->
    <!--               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"-->
    <!--               placeholder="Enter annual revenue">-->
    <!--    </div>-->
    <!--    <div>-->
    <!--        <label for="facility-employees" class="block text-sm font-medium text-gray-700">Number of Employees</label>-->
    <!--        <input type="number" -->
    <!--               id="facility-employees" -->
    <!--               name="employees" -->
    <!--               value="<?php echo esc_attr($facility_data['employees'] ?? ''); ?>"-->
    <!--               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"-->
    <!--               placeholder="Enter number of employees">-->
    <!--    </div>-->
    <!--    <button type="submit" -->
    <!--            class="w-full px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">-->
    <!--        Update Information-->
    <!--    </button>-->
    <!--</form>-->
    <!--<?php endif; ?>-->
</div>

<!-- Flag Modal -->
<div id="flag-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50" style="display: none;">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg max-w-lg w-full p-6 relative">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Report Incorrect Information</h3>
                <button type="button" class="close-modal text-gray-400 hover:text-gray-500">
                    <span class="sr-only">Close</span>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form id="flag-facility-form" data-facility-id="<?php echo get_the_ID(); ?>">
                <div class="mb-4">
                    <label for="flag-reason" class="block text-sm font-medium text-gray-700">
                        Please explain what information is incorrect
                    </label>
                    <textarea id="flag-reason" 
                              name="reason" 
                              rows="4" 
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                              placeholder="Please provide details about what information needs to be corrected"></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" 
                            class="close-modal px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700">
                        Submit Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Badge Section (Available for all facilities) -->
<!-- Badge Section (Available for all facilities) -->
<div class="bg-white rounded-lg shadow p-6 mt-4">
  <h2 class="text-2xl font-bold text-gray-800 mb-4">Environmental Badge</h2>

  <?php
  $badge = get_facility_badge( get_the_ID() );
  $is_imported = get_post_meta(get_the_ID(), '_csv_imported', true);

  // If no badge and imported, create a default badge based on CSV meta
  if (!$badge && $is_imported) {
      $carcinogen = strtolower(get_post_meta(get_the_ID(), 'carcinogen', true));
      if ($carcinogen === 'yes') {
          $badge = [
              'emoji' => '⚠️',
              'name' => 'Carcinogen Present',
              'description' => 'This facility reports carcinogenic chemicals.',
              'color' => 'red'
          ];
      } else {
          $badge = [
              'emoji' => '✅',
              'name' => 'Imported Facility',
              'description' => 'This facility was imported from CSV data.',
              'color' => 'green'
          ];
      }
  }
  ?>

  <?php if ( $badge ): ?>
    <div class="bg-<?php echo esc_attr($badge['color']); ?>-50 rounded-lg p-6">

      <!-- BADGE HEADER -->
      <div class="flex items-center space-x-3 mb-4">
        <div class="text-4xl"><?php echo $badge['emoji']; ?></div>
        <div>
          <h3 class="font-bold text-lg"><?php echo esc_html($badge['name']); ?></h3>
          <p class="text-gray-600"><?php echo esc_html($badge['description']); ?></p>
        </div>
      </div>

      <!-- BADGE PREVIEW -->
      <div class="mt-6">
        <h4 class="font-medium text-gray-700 mb-2">Badge Preview</h4>
        <div class="bg-gray-50 rounded-lg p-4 overflow-x-auto">
          <a 
            href="<?php echo esc_url( get_permalink() ); ?>" 
            title="<?php echo esc_attr( $badge['name'] ); ?>"
            class="flex flex-wrap items-center gap-2 px-4 py-2 bg-white rounded-full border-2 border-blue-600 text-blue-600"
          >
            <span class="text-2xl"><?php echo $badge['emoji']; ?></span>
            <span class="font-medium"><?php echo esc_html( $badge['name'] ); ?></span>
            <span class="text-lg">–</span>
            <img 
              src="https://cleanrecoveryact.com/wp-content/uploads/2025/06/clean-recovery-act-official-logo.jpg" 
              alt="Clean Recovery Act Logo" 
              class="w-5 h-5"
            >
            <span class="font-medium"><?php echo esc_html( get_the_title() ); ?></span>
            <span class="text-lg">–</span>
            <img 
              src="https://cleanrecoveryact.com/wp-content/uploads/2025/06/leaf.png" 
              alt="Leaf Icon" 
              class="w-4 h-4"
            >
            <span class="font-medium">Clean Recovery Act</span>
          </a>
        </div>
      </div>

      <!-- EMBED BADGE CODE -->
      <div class="mt-6">
        <h4 class="font-medium text-gray-700 mb-2">Embed Badge</h4>
        <div class="bg-gray-50 rounded-lg p-4 overflow-x-auto">
          <code class="block break-all text-sm text-gray-800 bg-gray-100 p-2 rounded border border-blue-600 mb-2">
            <?php
            $facility_name = get_the_title();
            $embed_code = sprintf(
              '<a href="%s" title="%s" class="flex flex-wrap items-center gap-2 px-4 py-2 bg-white rounded-full border-2 border-blue-600 text-blue-600">%s <span>%s</span> – <img src="https://cleanrecoveryact.com/wp-content/uploads/2025/06/clean-recovery-act-official-logo.jpg" alt="Logo" class="w-5 h-5"> <span>%s</span> – <img src="https://cleanrecoveryact.com/wp-content/uploads/2025/06/leaf.png" alt="Leaf" class="w-4 h-4"> <span>Clean Recovery Act</span></a>',
              esc_url( get_permalink() ),
              esc_attr( $badge['name'] ),
              $badge['emoji'],
              esc_html( $badge['name'] ),
              esc_html( $facility_name )
            );
            echo esc_html( $embed_code );
            ?>
          </code>
          <button
            onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent)"
            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors"
          >
            Copy Code
          </button>
        </div>
      </div>

    </div>
  <?php else: ?>
    <div class="bg-gray-50 rounded-lg p-6">
      <p class="text-gray-600">This facility's environmental impact is currently being assessed.</p>
    </div>
  <?php endif; ?>
</div>

<?php
$lat = get_post_meta(get_the_ID(), 'facility_lat', true);
$lng = get_post_meta(get_the_ID(), 'facility_lng', true);

if ($lat && $lng):
?>
<script>
  window.facilityMapData = {
    lat: "<?php echo esc_js($lat); ?>",
    lng: "<?php echo esc_js($lng); ?>",
    title: "<?php echo esc_js(get_the_title()); ?>"
  };
</script>
<?php endif; ?>

<?php get_footer(); ?>
