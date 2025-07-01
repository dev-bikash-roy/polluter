<?php
/**
 * Template Name: Facility Results
 */
get_header();
global $wpdb;

// 1) Pull & sanitize inputs
$location = sanitize_text_field( $_GET['location'] ?? '' );
$radius   = floatval( $_GET['radius']   ?? 25 );

// 2) Geocode via worldcities table
$searchLat = 39.8283;   // default center USA
$searchLng = -98.5795;

if ( $location ) {
    $like = '%' . $wpdb->esc_like( $location ) . '%';
    $row  = $wpdb->get_row( $wpdb->prepare(
        "SELECT lat, lng
         FROM worldcities
         WHERE city_ascii LIKE %s OR city LIKE %s
         ORDER BY population DESC
         LIMIT 1",
        $like, $like
    ) );
    if ( $row ) {
        $searchLat = floatval( $row->lat );
        $searchLng = floatval( $row->lng );
    }
}

// 3) Haversine to find facility IDs within $radius miles
$earthRadius = 3959; // miles
$sql = $wpdb->prepare(
    "SELECT p.ID FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm_lat
       ON pm_lat.post_id = p.ID AND pm_lat.meta_key = 'facility_lat'
     INNER JOIN {$wpdb->postmeta} pm_lng
       ON pm_lng.post_id = p.ID AND pm_lng.meta_key = 'facility_lng'
     WHERE p.post_type='facility' AND p.post_status='publish'
     HAVING (
        %f * ACOS(
          COS(RADIANS(%f)) * COS(RADIANS(pm_lat.meta_value))
          * COS(RADIANS(pm_lng.meta_value) - RADIANS(%f))
          + SIN(RADIANS(%f)) * SIN(RADIANS(pm_lat.meta_value))
        )
     ) <= %f
     ORDER BY 1
     LIMIT 50",
    $earthRadius, $searchLat, $searchLng, $searchLat, $radius
);
$facility_ids = $wpdb->get_col( $sql );

// 4) Render results & build map data
$posts_array = [];
?>
<div id="facility-results-wrapper">
  <div id="facility-results-list">
    <?php
    if ( ! empty( $facility_ids ) ) {
      // Optimized: Batch-load meta fields for up to 50 facilities
      $ids_in = implode(',', array_map('intval', $facility_ids));
      $results = $wpdb->get_results("
        SELECT p.ID, p.post_title,
          lat.meta_value AS lat,
          lng.meta_value AS lng,
          type.meta_value AS type,
          address.meta_value AS address
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key = 'facility_lat'
        LEFT JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key = 'facility_lng'
        LEFT JOIN {$wpdb->postmeta} type ON type.post_id = p.ID AND type.meta_key = 'facility_type'
        LEFT JOIN {$wpdb->postmeta} address ON address.post_id = p.ID AND address.meta_key = 'facility_address'
        WHERE p.ID IN ($ids_in)
        ORDER BY FIELD(p.ID, $ids_in)
      ");
      foreach ($results as $row) {
        $type = $row->type;
        $display_label = '';
        $badge_class = '';
        $icon_svg = '';
        if (!$type) {
          $name = strtolower($row->post_title);
          if (strpos($name, 'golf') !== false) {
            $type = 'golf';
          } elseif (strpos($name, 'plant') !== false || strpos($name, 'factory') !== false) {
            $type = 'industrial';
          } else {
            $type = 'other';
          }
        }
        // Set display label and badge style
        if ($type === 'golf') {
          $display_label = 'Golf Course';
          $badge_class = 'bg-green-100 text-green-800';
          $icon_svg = '<svg class="inline w-4 h-4 mr-1 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 21v-2a4 4 0 00-8 0v2M12 11V3l6 3-6 3"/></svg>';
        } elseif ($type === 'industrial') {
          $display_label = 'Industrial Facility';
          $badge_class = 'bg-red-100 text-red-800';
          $icon_svg = '<svg class="inline w-4 h-4 mr-1 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6m-6 0h6"/></svg>';
        } else {
          $display_label = 'Other Facility';
          $badge_class = 'bg-gray-100 text-gray-800';
          $icon_svg = '<svg class="inline w-4 h-4 mr-1 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/></svg>';
        }
        $posts_array[] = [
          'lat'       => $row->lat,
          'lng'       => $row->lng,
          'name'      => $row->post_title,
          'address'   => $row->address,
          'type'      => $type,
          'image'     => '', // Add image logic if needed
          'permalink' => get_permalink($row->ID),
        ];
        // Render the card with badge and icon
        echo '<div class="facility-card bg-white rounded-lg shadow-md p-6 mb-4 flex flex-col gap-2">';
        echo '<div class="flex items-center justify-between mb-2">';
        echo '<h3 class="text-xl font-bold text-gray-900">' . esc_html($row->post_title) . '</h3>';
        echo '<span class="px-3 py-1 rounded-full text-sm font-medium ' . $badge_class . '">' . $icon_svg . esc_html($display_label) . '</span>';
        echo '</div>';
        echo '<p class="text-gray-600 mb-2">' . esc_html($row->address) . '</p>';
        echo '<a href="' . esc_url(get_permalink($row->ID)) . '" class="inline-block mt-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">View Full Details</a>';
        echo '</div>';
      }
    } else {
      get_template_part( 'templates/facility-not-found' );
    }
    ?>
  </div>
</div>

<script>
  // Provide your map script with both the center and the facilities
  const cleanyact10MapData = {
    facilityData: <?php echo wp_json_encode( $posts_array, JSON_UNESCAPED_SLASHES ); ?>,
    searchCenter: [ <?php echo json_encode( $searchLat ); ?>, <?php echo json_encode( $searchLng ); ?> ]
  };
</script>

<?php get_footer(); ?>
