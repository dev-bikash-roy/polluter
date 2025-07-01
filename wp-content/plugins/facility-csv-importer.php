<?php
/*
Plugin Name: Facility Auto Importer
Description: Automatically fetches worldwide factory facilities and generates a sitemap for SEO.
Version: 2.0
*/

// Schedule daily event on activation
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('facility_auto_import_daily')) {
        wp_schedule_event(time(), 'daily', 'facility_auto_import_daily');
    }
    // Create indexes on worldcities.city_ascii and worldcities.city for faster autocomplete
    global $wpdb;
    $table = $wpdb->prefix . 'worldcities';
    // Add index to city_ascii if not exists
    $index_city_ascii = $wpdb->get_results("SHOW INDEX FROM `$table` WHERE Key_name = 'idx_city_ascii'");
    if (empty($index_city_ascii)) {
        $wpdb->query("CREATE INDEX idx_city_ascii ON `$table` (city_ascii(191))");
    }
    // Add index to city if not exists
    $index_city = $wpdb->get_results("SHOW INDEX FROM `$table` WHERE Key_name = 'idx_city'");
    if (empty($index_city)) {
        $wpdb->query("CREATE INDEX idx_city ON `$table` (city(191))");
    }
});

// Clear scheduled event on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('facility_auto_import_daily');
});

// Main import function (fetches factories from OpenStreetMap Overpass API)
add_action('facility_auto_import_daily', 'facility_auto_import_factories');
function facility_auto_import_factories() {
    // Example: Fetch factories in the US (adjust bbox for global or regional)
    $overpass_url = 'https://overpass-api.de/api/interpreter';
    $query = '[out:json][timeout:600];node["man_made"="works"](24.396308,-125.0,49.384358,-66.93457);out body;';
    $response = wp_remote_post($overpass_url, [
        'body' => ['data' => $query],
        'timeout' => 600,
    ]);
    if (is_wp_error($response)) return;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['elements'])) return;

    foreach ($data['elements'] as $element) {
        $name = $element['tags']['name'] ?? '';
        if (!$name) continue;
        $lat = $element['lat'] ?? '';
        $lon = $element['lon'] ?? '';
        $address = $element['tags']['addr:full'] ?? '';
        $city = $element['tags']['addr:city'] ?? '';
        $state = $element['tags']['addr:state'] ?? '';
        $country = $element['tags']['addr:country'] ?? '';
        $industry = $element['tags']['industry'] ?? '';
        $osm_id = $element['id'];

        // Unique slug by OSM ID
        $slug = sanitize_title($name . '-' . $osm_id);

        // Check if already exists by slug
        $existing = get_posts([
            'name' => $slug,
            'post_type' => 'facility',
            'post_status' => 'any',
            'numberposts' => 1
        ]);
        if ($existing) continue;

        $content = "Address: $address, $city, $state\nIndustry: $industry\nLatitude: $lat, Longitude: $lon\n";
        wp_insert_post([
            'post_type'    => 'facility',
            'post_title'   => $name,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_content' => $content,
            'meta_input'   => [
                'osm_id'   => $osm_id,
                'address'  => $address,
                'city'     => $city,
                'state'    => $state,
                'country'  => $country,
                'latitude' => $lat,
                'longitude'=> $lon,
                'industry' => $industry,
            ]
        ]);
    }
}

// Generate sitemap for all facilities
add_action('init', function() {
    add_rewrite_rule('^sitemap-facilities\.xml$', 'index.php?facility_sitemap=1', 'top');
});

add_filter('query_vars', function($vars) {
    $vars[] = 'facility_sitemap';
    return $vars;
});

add_action('template_redirect', function() {
    if (get_query_var('facility_sitemap')) {
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $offset = 0;
        $batch = 100;
        do {
            $posts = get_posts([
                'post_type' => 'facility',
                'post_status' => 'publish',
                'numberposts' => $batch,
                'offset' => $offset,
                'fields' => 'ids', // Only get IDs to save memory
            ]);
            foreach ($posts as $post_id) {
                $url = get_permalink($post_id);
                $lastmod = get_the_modified_time('c', $post_id);
                echo "<url><loc>$url</loc><lastmod>$lastmod</lastmod></url>";
            }
            $offset += $batch;
        } while (count($posts) === $batch);
        echo '</urlset>';
        exit;
    }
});

// Admin notice for status
add_action('admin_notices', function() {
    if (isset($_GET['facility_auto_imported'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Facility auto import completed!</p></div>';
    }
});

// Manual trigger for facility import via URL
add_action('admin_init', function() {
    if (isset($_GET['run_facility_import']) && current_user_can('manage_options')) {
        facility_auto_import_factories();
        wp_redirect(admin_url('edit.php?post_type=facility&facility_auto_imported=1'));
        exit;
    }
});

/**
 * Save all facilities from a search as posts.
 * Call facility_save_search_results($facilities) after calling search_facilities().
 *
 * @param array $facilities Array of facilities as returned by search_facilities().
 */
function facility_save_search_results($facilities) {
    foreach ($facilities as $facility) {
        // Use place_id as unique identifier if available
        $slug = isset($facility['place_id']) ? sanitize_title($facility['name'] . '-' . $facility['place_id']) : sanitize_title($facility['name'] . '-' . $facility['address']);

        // Check if already exists by slug
        $existing = get_posts([
            'name'        => $slug,
            'post_type'   => 'facility',
            'post_status' => 'any',
            'numberposts' => 1
        ]);
        if ($existing) continue;

        // Prepare pollutants as a string if array
        $pollutants = '';
        if (!empty($facility['pollutants']) && is_array($facility['pollutants'])) {
            foreach ($facility['pollutants'] as $pollutant) {
                $pollutants .= $pollutant['name'] . ': ' . $pollutant['amount'] . ' (' . $pollutant['health_effects'] . ")\n";
            }
        }

        // Create the post
        wp_insert_post([
            'post_type'    => 'facility',
            'post_title'   => $facility['name'],
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_content' => "Address: {$facility['address']}\nPollutants:\n$pollutants",
            'meta_input'   => [
                'address'      => $facility['address'],
                'lat'          => $facility['lat'],
                'lng'          => $facility['lng'],
                'type'         => $facility['type'],
                'revenue'      => $facility['revenue'],
                'cleanup_cost' => $facility['cleanup_cost'],
                'place_id'     => $facility['place_id'],
                'pollutants'   => $pollutants,
            ]
        ]);
    }
}

// =============================
// Facility CSV Import Admin Page
// =============================
add_action('admin_menu', function() {
    add_management_page(
        'Facility CSV Import',
        'Facility CSV Import',
        'manage_options',
        'facility-csv-import',
        'facility_csv_import_admin_page'
    );
});

function facility_csv_import_admin_page() {
    echo '<div class="wrap"><h1>Facility CSV Import</h1>';
    if (!empty($_POST['facility_csv_import']) && check_admin_referer('facility_csv_import')) {
        if (!empty($_FILES['facility_csv_file']['tmp_name'])) {
            $file = $_FILES['facility_csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                $imported = 0;
                $skipped = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine($header, $row);
                    // Only import if required fields are present
                    if (empty($data['FACILITY NAME']) || empty($data['LATITUDE']) || empty($data['LONGITUDE'])) {
                        $skipped++;
                        continue;
                    }
                    $name = sanitize_text_field($data['FACILITY NAME']);
                    $lat = floatval($data['LATITUDE']);
                    $lng = floatval($data['LONGITUDE']);
                    $zip = sanitize_text_field($data['ZIP'] ?? '');
                    $address = sanitize_text_field($data['STREET ADDRESS'] ?? '');
                    $city = sanitize_text_field($data['CITY'] ?? '');
                    $state = sanitize_text_field($data['ST'] ?? '');
                    $county = sanitize_text_field($data['COUNTY'] ?? '');
                    $type = sanitize_text_field($data['INDUSTRY SECTOR'] ?? 'industrial');
                    $facility_data = [
                        'name' => $name,
                        'lat' => $lat,
                        'lng' => $lng,
                        'type' => $type,
                        'address' => $address,
                        'city' => $city,
                        'state' => $state,
                        'zip' => $zip,
                        'county' => $county,
                    ];
                    // Unique slug by name and lat/lng
                    $slug = sanitize_title($name . '-' . $lat . '-' . $lng);
                    // Check if already exists by slug
                    $existing = get_posts([
                        'name' => $slug,
                        'post_type' => 'facility',
                        'post_status' => 'any',
                        'numberposts' => 1
                    ]);
                    if ($existing) {
                        $post_id = $existing[0]->ID;
                        // Optionally update post meta here
                    } else {
                        $post_id = wp_insert_post([
                            'post_type'    => 'facility',
                            'post_title'   => $name,
                            'post_name'    => $slug,
                            'post_status'  => 'publish',
                            'post_content' => "Address: $address, $city, $state, $zip\nCounty: $county\nLatitude: $lat, Longitude: $lng\n",
                        ]);
                    }
                    if ($post_id) {
                        // Save facility_data array
                        update_post_meta($post_id, 'facility_data', $facility_data);
                        // Save individual meta fields for compatibility
                        update_post_meta($post_id, 'facility_lat', $lat);
                        update_post_meta($post_id, 'facility_lng', $lng);
                        update_post_meta($post_id, 'facility_type', $type);
                        update_post_meta($post_id, 'facility_address', $address);
                        update_post_meta($post_id, 'facility_city', $city);
                        update_post_meta($post_id, 'facility_state', $state);
                        update_post_meta($post_id, 'facility_zip', $zip);
                        update_post_meta($post_id, 'facility_county', $county);
                        $imported++;
                    } else {
                        $skipped++;
                    }
                }
                fclose($handle);
                echo '<div class="notice notice-success"><p>Import complete. Imported: ' . intval($imported) . ', Skipped: ' . intval($skipped) . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Could not open uploaded file.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>No file uploaded.</p></div>';
        }
    }
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('facility_csv_import');
    echo '<input type="file" name="facility_csv_file" accept=".csv" required> ';
    echo '<input type="submit" name="facility_csv_import" class="button button-primary" value="Import CSV">';
    echo '</form></div>';
} 