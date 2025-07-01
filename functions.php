/**
 * Generate and update XML sitemap
 */
function update_xml_sitemap() {
    $sitemap_content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $sitemap_content .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Add homepage
    $sitemap_content .= generate_sitemap_url(home_url('/'), '1.0', 'daily');

    // Add main pages
    $main_pages = array(
        '/facility-results/' => '0.9',
        '/factory-search/' => '0.9',
        '/contact-us/' => '0.7'
    );

    foreach ($main_pages as $page => $priority) {
        $sitemap_content .= generate_sitemap_url(home_url($page), $priority, 'daily');
    }

    // Add facilities
    $facilities = get_posts(array(
        'post_type' => 'facility',
        'posts_per_page' => -1,
        'orderby' => 'modified',
        'order' => 'DESC'
    ));

    foreach ($facilities as $facility) {
        $sitemap_content .= generate_sitemap_url(
            get_permalink($facility->ID),
            '0.8',
            'weekly',
            get_the_modified_date('c', $facility->ID)
        );
    }

    $sitemap_content .= '</urlset>';

    // Save sitemap
    file_put_contents(ABSPATH . 'sitemap.xml', $sitemap_content);
}

/**
 * Generate sitemap URL entry
 */
function generate_sitemap_url($url, $priority = '0.5', $changefreq = 'monthly', $lastmod = '') {
    $entry = "    <url>\n";
    $entry .= "        <loc>" . esc_url($url) . "</loc>\n";
    if ($lastmod) {
        $entry .= "        <lastmod>" . esc_html($lastmod) . "</lastmod>\n";
    }
    $entry .= "        <changefreq>" . esc_html($changefreq) . "</changefreq>\n";
    $entry .= "        <priority>" . esc_html($priority) . "</priority>\n";
    $entry .= "    </url>\n";
    return $entry;
}

// Update sitemap when content changes
add_action('save_post', 'update_xml_sitemap');
add_action('delete_post', 'update_xml_sitemap');
add_action('publish_post', 'update_xml_sitemap');
add_action('publish_page', 'update_xml_sitemap');

/**
 * Register Sitemap Template
 */
function register_sitemap_template() {
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');
    
    // Register Sitemap Template
    add_filter('theme_page_templates', function($templates) {
        $templates['templates/sitemap.php'] = 'Sitemap';
        return $templates;
    });
}
add_action('after_setup_theme', 'register_sitemap_template');

/**
 * Register Page Templates
 */
function register_custom_templates($templates) {
    $templates['page-sitemap.php'] = 'Sitemap Page';
    return $templates;
}
add_filter('theme_page_templates', 'register_custom_templates');