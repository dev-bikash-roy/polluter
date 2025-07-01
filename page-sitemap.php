<?php
/**
 * Template Name: Sitemap Page
 */

get_header();
?>

<div class="max-w-7xl mx-auto px-4 py-12">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-4xl font-bold text-gray-900 mb-8">Sitemap</h1>

        <!-- Main Navigation -->
        <div class="mb-12">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Main Pages</h2>
            <ul class="space-y-2">
                <li>
                    <a href="<?php echo home_url('/'); ?>" class="text-blue-600 hover:text-blue-800">Home</a>
                </li>
                <li>
                    <a href="<?php echo home_url('/facility-results/'); ?>" class="text-blue-600 hover:text-blue-800">Facility Results</a>
                </li>
                <li>
                    <a href="<?php echo home_url('/factory-search/'); ?>" class="text-blue-600 hover:text-blue-800">Factory Search</a>
                </li>
                <li>
                    <a href="<?php echo home_url('/contact-us/'); ?>" class="text-blue-600 hover:text-blue-800">Contact Us</a>
                </li>
            </ul>
        </div>

        <!-- Facilities -->
        <div class="mb-12">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Facilities</h2>
            <?php
            $facilities = get_posts(array(
                'post_type' => 'facility',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ));

            if ($facilities): ?>
                <ul class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($facilities as $facility): ?>
                        <li>
                            <a href="<?php echo get_permalink($facility->ID); ?>" 
                               class="text-blue-600 hover:text-blue-800">
                                <?php echo esc_html($facility->post_title); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-gray-600">No facilities found.</p>
            <?php endif; ?>
        </div>

        <!-- Additional Resources -->
        <div>
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Additional Resources</h2>
            <ul class="space-y-2">
                <li>
                    <a href="<?php echo home_url('/privacy-policy/'); ?>" class="text-blue-600 hover:text-blue-800">Privacy Policy</a>
                </li>
                <li>
                    <a href="<?php echo home_url('/terms-of-service/'); ?>" class="text-blue-600 hover:text-blue-800">Terms of Service</a>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php get_footer(); ?> 