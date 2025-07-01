<?php
/**
 * Template Name: Facilities Sitemap
 * Description: A sitemap page showing all facilities organized by type and location
 */

get_header();

// Get all facilities
$facilities = get_posts(array(
    'post_type' => 'facility',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
));

// Group facilities by type
$facilities_by_type = array();
foreach ($facilities as $facility) {
    $type = get_post_meta($facility->ID, 'facility_type', true) ?: 'Other';
    if (!isset($facilities_by_type[$type])) {
        $facilities_by_type[$type] = array();
    }
    $facilities_by_type[$type][] = $facility;
}

// Group facilities by state
$facilities_by_state = array();
foreach ($facilities as $facility) {
    $state = get_post_meta($facility->ID, 'facility_state', true) ?: 'Unknown';
    if (!isset($facilities_by_state[$state])) {
        $facilities_by_state[$state] = array();
    }
    $facilities_by_state[$state][] = $facility;
}

ksort($facilities_by_type);
ksort($facilities_by_state);
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-8">Facilities Directory</h1>

    <!-- Filters -->
    <div class="mb-8 flex gap-4">
        <button id="byTypeBtn" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 active">By Type</button>
        <button id="byStateBtn" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">By State</button>
    </div>

    <!-- Facilities by Type -->
    <div id="byTypeSection">
        <?php foreach ($facilities_by_type as $type => $type_facilities): ?>
            <div class="mb-8">
                <h2 class="text-2xl font-semibold mb-4 text-gray-800"><?php echo esc_html($type); ?></h2>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($type_facilities as $facility): ?>
                        <a href="<?php echo get_permalink($facility->ID); ?>" 
                           class="block p-4 bg-white rounded-lg shadow hover:shadow-md transition-shadow">
                            <h3 class="font-medium text-lg mb-2"><?php echo esc_html($facility->post_title); ?></h3>
                            <?php 
                            $address = get_post_meta($facility->ID, 'facility_address', true);
                            $city = get_post_meta($facility->ID, 'facility_city', true);
                            $state = get_post_meta($facility->ID, 'facility_state', true);
                            ?>
                            <p class="text-sm text-gray-600">
                                <?php echo esc_html($address); ?><br>
                                <?php echo esc_html("$city, $state"); ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Facilities by State -->
    <div id="byStateSection" class="hidden">
        <?php foreach ($facilities_by_state as $state => $state_facilities): ?>
            <div class="mb-8">
                <h2 class="text-2xl font-semibold mb-4 text-gray-800"><?php echo esc_html($state); ?></h2>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($state_facilities as $facility): ?>
                        <a href="<?php echo get_permalink($facility->ID); ?>" 
                           class="block p-4 bg-white rounded-lg shadow hover:shadow-md transition-shadow">
                            <h3 class="font-medium text-lg mb-2"><?php echo esc_html($facility->post_title); ?></h3>
                            <?php 
                            $type = get_post_meta($facility->ID, 'facility_type', true);
                            $city = get_post_meta($facility->ID, 'facility_city', true);
                            ?>
                            <p class="text-sm text-gray-600">
                                Type: <?php echo esc_html($type); ?><br>
                                <?php echo esc_html($city); ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const byTypeBtn = document.getElementById('byTypeBtn');
    const byStateBtn = document.getElementById('byStateBtn');
    const byTypeSection = document.getElementById('byTypeSection');
    const byStateSection = document.getElementById('byStateSection');

    byTypeBtn.addEventListener('click', function() {
        byTypeSection.classList.remove('hidden');
        byStateSection.classList.add('hidden');
        byTypeBtn.classList.add('bg-blue-500', 'text-white');
        byTypeBtn.classList.remove('bg-gray-200', 'text-gray-700');
        byStateBtn.classList.add('bg-gray-200', 'text-gray-700');
        byStateBtn.classList.remove('bg-blue-500', 'text-white');
    });

    byStateBtn.addEventListener('click', function() {
        byTypeSection.classList.add('hidden');
        byStateSection.classList.remove('hidden');
        byStateBtn.classList.add('bg-blue-500', 'text-white');
        byStateBtn.classList.remove('bg-gray-200', 'text-gray-700');
        byTypeBtn.classList.add('bg-gray-200', 'text-gray-700');
        byTypeBtn.classList.remove('bg-blue-500', 'text-white');
    });
});
</script>

<?php get_footer(); ?> 