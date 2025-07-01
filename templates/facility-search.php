<?php
get_header();

// Get search parameters
$location_name = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : '';
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? intval($_GET['radius']) : 25;
$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
$posts_per_page = 12;

// If location is present but lat/lng are missing, geocode server-side and redirect
if ($location_name && (empty($lat) || empty($lng))) {
    $geo_url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $location_name,
        'format' => 'json',
        'limit' => 1
    ]);
    $geo_response = wp_remote_get($geo_url);
    if (!is_wp_error($geo_response)) {
        $geo_data = json_decode(wp_remote_retrieve_body($geo_response), true);
        if ($geo_data && count($geo_data) > 0) {
            $lat = $geo_data[0]['lat'];
            $lng = $geo_data[0]['lon'];
            // Redirect to same page with lat/lng in URL
            $query_args = $_GET;
            $query_args['lat'] = $lat;
            $query_args['lng'] = $lng;
            wp_redirect(add_query_arg($query_args, get_permalink()));
            exit;
        }
    }
}

// Query facilities
$args = array(
    'post_type' => 'facility',
    'posts_per_page' => $posts_per_page,
    'paged' => $paged,
    'meta_query' => array()
);

if ($lat && $lng) {
    global $wpdb;
    $facilities = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title,
                pm1.meta_value as lat,
                pm2.meta_value as lng,
                pm3.meta_value as facility_data,
                (
                    3959 * acos(
                        cos(radians(%f)) *
                        cos(radians(CAST(pm1.meta_value AS DECIMAL(10,6)))) *
                        cos(radians(CAST(pm2.meta_value AS DECIMAL(10,6))) - radians(%f)) +
                        sin(radians(%f)) *
                        sin(radians(CAST(pm1.meta_value AS DECIMAL(10,6))))
                    )
                ) AS distance
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'facility_lat'
         JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'facility_lng'
         JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = 'facility_data'
         WHERE p.post_type = 'facility'
         AND p.post_status = 'publish'
         HAVING distance <= %d
         ORDER BY distance
         LIMIT %d OFFSET %d",
        $lat, $lng, $lat, $radius, $posts_per_page, ($paged - 1) * $posts_per_page
    ));
    $total_facilities = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM (
             SELECT p.ID,
                    (
                        3959 * acos(
                            cos(radians(%f)) * 
                            cos(radians(CAST(pm1.meta_value AS DECIMAL(10,6)))) * 
                            cos(radians(CAST(pm2.meta_value AS DECIMAL(10,6))) - radians(%f)) + 
                            sin(radians(%f)) * 
                            sin(radians(CAST(pm1.meta_value AS DECIMAL(10,6))))
                        )
                    ) AS distance
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'facility_lat'
             JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'facility_lng'
             WHERE p.post_type = 'facility'
             AND p.post_status = 'publish'
             HAVING distance <= %d
         ) as facilities",
        $lat, $lng, $lat, $radius
    ));
} else {
    $facilities = get_posts($args);
    $total_facilities = wp_count_posts('facility')->publish;
}

// Prepare facility data for the map
$facility_markers = array();
foreach ($facilities as $facility) {
    $facility_data = get_post_meta($facility->ID, 'facility_data', true);
    if ($facility_data) {
        $facility_markers[] = array(
            'id' => $facility->ID,
            'name' => $facility->post_title,
            'lat' => $facility_data['lat'],
            'lng' => $facility_data['lng'],
            'type' => $facility_data['type'],
            'address' => $facility_data['address'],
            'distance' => isset($facility->distance) ? round($facility->distance, 1) : null
        );
    }
}

wp_localize_script('facility-search', 'facilitySearchData', array(
    'facilityMarkers' => $facility_markers,
));
?>

<div class="flex justify-center items-center min-h-[400px] bg-gradient-to-br from-blue-900 to-blue-700 py-12">
  <form id="facility-search" class="w-full max-w-2xl bg-white/20 backdrop-blur-lg rounded-2xl shadow-2xl p-8 sm:p-12 space-y-8 border border-white/30" action="/facility-results/" method="get" autocomplete="off">
    <div class="relative">
      <label for="location-input" class="sr-only">Location</label>
      <span class="absolute left-4 top-1/2 -translate-y-1/2 text-blue-400 pointer-events-none">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
      </span>
      <input type="text"
             id="location-input"
             name="location"
             autocomplete="off"
             spellcheck="false"
             placeholder="Enter your address, city, zip code, or region"
             required
             class="w-full pl-12 pr-16 py-4 bg-white/80 text-gray-900 placeholder-gray-500 rounded-xl border border-white/30 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent text-lg shadow-sm transition" />
      <button type="button"
              onclick="getCurrentLocation()"
              class="absolute right-4 top-1/2 -translate-y-1/2 text-blue-400 hover:text-blue-600 transition-colors"
              title="Use my current location">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
        </svg>
      </button>
      <div id="location-autocomplete" class="absolute z-50 w-full mt-2 bg-white rounded-xl shadow-lg hidden border border-blue-100 overflow-hidden">
        <ul class="max-h-60 py-1 text-base overflow-auto focus:outline-none sm:text-sm"></ul>
      </div>
      <input type="hidden" name="lat" id="lat">
      <input type="hidden" name="lng" id="lng">
    </div>
    <div class="flex flex-col sm:flex-row gap-6 items-center">
      <div class="flex-1 w-full">
        <label for="radius" class="block text-sm font-medium text-white mb-2">Search Radius</label>
        <div class="relative">
          <select id="radius"
                  name="radius"
                  class="w-full px-4 py-3 bg-white/30 text-white border border-white/30 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-400 appearance-none text-lg shadow-sm transition">
            <option value="10">10 miles</option>
            <option value="25">25 miles</option>
            <option value="50" selected>50 miles</option>
            <option value="100">100 miles</option>
          </select>
          <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-white">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </div>
        </div>
      </div>
      <div class="flex-none w-full sm:w-auto mt-6 sm:mt-0">
        <button type="submit"
                class="w-full sm:w-auto px-10 py-4 bg-gradient-to-r from-blue-600 to-blue-500 text-white text-xl font-semibold rounded-xl shadow-lg hover:from-blue-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-blue-900 transition-all transform hover:scale-105 duration-150">
          <span class="inline-flex items-center gap-2">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" /></svg>
            Find Polluters Near Me
          </span>
        </button>
      </div>
    </div>
  </form>
</div>

<div class="max-w-7xl mx-auto px-4 py-8">
    <!-- Map Section -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
        <div id="search-results-map" class="h-96 w-full"></div>
    </div>

    <!-- Search Results Header -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">
            <?php if ($lat && $lng): ?>
                Environmental Hazards Near <?php echo esc_html($location_name ?: 'Your Location'); ?>
            <?php else: ?>
                All Facilities
            <?php endif; ?>
        </h1>
        <p class="text-gray-600">
            Found <span id="total-facilities-count"><?php echo number_format($total_facilities); ?></span> facilities
            <?php if ($lat && $lng): ?>
                within <?php echo $radius; ?> miles
            <?php endif; ?>
        </p>
    </div>

    <!-- Facilities List -->
    <div id="facilities-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <?php foreach ($facilities as $facility):
            $facility_data = get_post_meta($facility->ID, 'facility_data', true);
            if (!$facility_data) continue;
        ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden hover:shadow-lg transition-shadow">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">
                        <a href="<?php echo get_permalink($facility->ID); ?>" class="hover:text-blue-600">
                            <?php echo esc_html($facility->post_title); ?>
                        </a>
                    </h2>
                    <p class="text-gray-600 mb-4"><?php echo esc_html($facility_data['address']); ?></p>

                    <?php if (isset($facility->distance)): ?>
                        <p class="text-sm text-gray-500 mb-4">
                            Distance: <?php echo round($facility->distance, 1); ?> miles
                        </p>
                    <?php endif; ?>

                    <div class="flex items-center justify-between">
                        <span class="px-3 py-1 rounded-full text-sm font-medium
                            <?php echo $facility_data['type'] === 'golf' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php echo $facility_data['type'] === 'golf' ? 'Golf Course' : 'Industrial Facility'; ?>
                        </span>
                        <a href="<?php echo get_permalink($facility->ID); ?>"
                           class="text-blue-600 hover:text-blue-800 font-medium">
                            View Details →
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php
    $total_pages = ceil($total_facilities / $posts_per_page);
    if ($total_pages > 1):
        $paginate_args = array(
            'base'               => add_query_arg('paged', '%#%'),
            'format'             => '',
            'current'            => max(1, $paged),
            'total'              => $total_pages,
            'show_all'           => false,
            'end_size'           => 1,
            'mid_size'           => 2,
            'prev_next'          => true,
            'prev_text'          => '&larr; Previous',
            'next_text'          => 'Next &rarr;',
            'type'               => 'array',
            'add_args'           => $_GET,
            'add_fragment'       => '',
        );
        if (isset($paginate_args['add_args']['paged'])) {
            unset($paginate_args['add_args']['paged']);
        }
        $pages = paginate_links($paginate_args);
        if (is_array($pages)) {
            echo '<div class="flex justify-center space-x-2 mb-8" id="pagination-container">';
            foreach ($pages as $page) {
                // Add custom classes to the pagination links
                $page = str_replace('page-numbers', 'px-4 py-2 rounded-md border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50', $page);
                $page = str_replace('current', 'bg-blue-50 border-blue-500 text-blue-600', $page);
                echo '<div class="pagination-item">' . $page . '</div>';
            }
            echo '</div>';
        }
    endif;
    ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize map
    const mapDiv = document.getElementById('search-results-map');
    if (!mapDiv) {
        console.error('Map container not found');
        return;
    }

    let map = null;
    let markers = L.layerGroup();

    function initMap(lat, lng, zoom = 10) {
        if (map) {
            map.remove();
        }
        
        map = L.map('search-results-map').setView([lat, lng], zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a>'
        }).addTo(map);
        
        markers = L.layerGroup().addTo(map);
    }

    function addMarkers(facilities) {
        if (!map) return;
        
        markers.clearLayers();
        const bounds = L.latLngBounds();
        
        facilities.forEach(facility => {
            const marker = L.marker([facility.lat, facility.lng], {
                icon: L.divIcon({
                    className: 'facility-marker',
                    html: `<div class="marker-pin ${facility.type === 'golf' ? 'bg-green-500' : 'bg-blue-500'}"></div>`,
                    iconSize: [30, 30],
                    iconAnchor: [15, 30]
                })
            }).bindPopup(`
                <div class="p-2">
                    <h3 class="font-bold text-lg mb-2">${facility.name}</h3>
                    <p class="mb-1">${facility.address}</p>
                    ${facility.distance ? `<p class="text-sm">Distance: ${facility.distance} miles</p>` : ''}
                    <a href="/facilities/${facility.id}/" 
                       class="inline-block mt-2 px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                        View Details
                    </a>
                </div>
            `);
            markers.addLayer(marker);
            bounds.extend([facility.lat, facility.lng]);
        });

        if (facilities.length > 0) {
            map.fitBounds(bounds, { padding: [50, 50] });
        }
    }

    // Initialize map with current facilities
    const facilityMarkers = <?php echo json_encode($facility_markers); ?>;
    if (facilityMarkers && facilityMarkers.length > 0) {
        initMap(facilityMarkers[0].lat, facilityMarkers[0].lng);
        addMarkers(facilityMarkers);
    } else {
        // Default to Reno, NV if no facilities
        initMap(39.5296, -119.8138);
    }

    // Autocomplete functionality
    const searchInput = document.getElementById('location-input');
    const autocompleteDiv = document.getElementById('location-autocomplete');
    const autocompleteList = autocompleteDiv ? autocompleteDiv.querySelector('ul') : null;
    const latInput = document.getElementById('lat');
    const lngInput = document.getElementById('lng');
    let searchTimeout;

    if (searchInput && autocompleteDiv && autocompleteList) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            if (query.length < 2) {
                autocompleteDiv.classList.add('hidden');
                return;
            }
            searchTimeout = setTimeout(() => {
                // Use WP REST API for city autocomplete
                fetch(`/wp-json/cra/v1/cities?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        autocompleteList.innerHTML = '';
                        const list = Array.isArray(data) ? data : (data.data || []);
                        if (list.length > 0) {
                            list.forEach(city => {
                                const li = document.createElement('li');
                                li.className = 'px-4 py-2 hover:bg-gray-100 cursor-pointer';
                                li.textContent = `${city.name}, ${city.country}`;
                                li.addEventListener('click', () => {
                                    searchInput.value = `${city.name}, ${city.country}`;
                                    latInput.value = city.lat;
                                    lngInput.value = city.lng;
                                    autocompleteDiv.classList.add('hidden');
                                });
                                autocompleteList.appendChild(li);
                            });
                            autocompleteDiv.classList.remove('hidden');
                        } else {
                            autocompleteDiv.classList.add('hidden');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching city suggestions:', error);
                        autocompleteDiv.classList.add('hidden');
                    });
            }, 250);
        });
        // Close autocomplete when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !autocompleteDiv.contains(e.target)) {
                autocompleteDiv.classList.add('hidden');
            }
        });
    }

    // Handle pagination clicks
    const paginationContainer = document.getElementById('pagination-container');
    if (paginationContainer) {
        paginationContainer.addEventListener('click', function(e) {
            const link = e.target.closest('a');
            if (!link) return;
            
            e.preventDefault();
            const url = new URL(link.href);
            
            // Update URL without reload
            window.history.pushState({}, '', url);
            
            // Show loading state
            const facilitiesGrid = document.querySelector('.facilities-grid');
            if (facilitiesGrid) {
                facilitiesGrid.style.opacity = '0.5';
            }
            
            // Fetch new results
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update facilities list
                    const newFacilities = doc.querySelector('.facilities-grid');
                    if (newFacilities && facilitiesGrid) {
                        facilitiesGrid.innerHTML = newFacilities.innerHTML;
                        facilitiesGrid.style.opacity = '1';
                    }
                    
                    // Update pagination
                    const newPagination = doc.querySelector('#pagination-container');
                    if (newPagination) {
                        paginationContainer.innerHTML = newPagination.innerHTML;
                    }
                    
                    // Update map markers
                    const newMarkers = JSON.parse(doc.querySelector('#facility-markers-data').textContent);
                    if (map) {
                        addMarkers(newMarkers);
                    }
                    
                    // Scroll to top of results
                    facilitiesGrid?.scrollIntoView({ behavior: 'smooth' });
                })
                .catch(error => {
                    console.error('Error loading new page:', error);
                    if (facilitiesGrid) {
                        facilitiesGrid.style.opacity = '1';
                    }
                });
        });
    }
});
</script>

<!-- Add this right before the closing </div> -->
<script type="application/json" id="facility-markers-data">
<?php echo json_encode($facility_markers); ?>
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

<?php get_footer(); ?> 