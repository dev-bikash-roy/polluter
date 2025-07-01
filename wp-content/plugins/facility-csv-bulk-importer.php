<?php
/*
Plugin Name: Facility CSV Bulk Importer
Description: Bulk import facilities from CSV with progress and admin view. Imports to 'facility' post type.
Version: 2.0
Author: Your Team
*/

if (!defined('ABSPATH')) exit;

// --- Admin Menus ---
add_action('admin_menu', function() {
    add_management_page(
        'Bulk Facility Import',
        'Bulk Facility Import',
        'manage_options',
        'facility-csv-bulk-import',
        'facility_csv_bulk_import_page'
    );
    add_management_page(
        'Imported Facilities',
        'Imported Facilities',
        'manage_options',
        'facility-imported-list',
        'facility_csv_imported_list_page'
    );
    add_menu_page(
        'Manage Facilities',
        'Manage Facilities',
        'manage_options',
        'facility-manage',
        'facility_manage_facilities_page',
        'dashicons-building',
        25
    );
});

// --- Import Page ---
function facility_csv_bulk_import_page() {
    echo '<div class="wrap"><h1>Bulk Facility Import</h1>';
    if (!current_user_can('manage_options')) return;
    echo '<form id="facility-csv-upload-form" method="post" enctype="multipart/form-data">';
    wp_nonce_field('facility_csv_import');
    echo '<input type="file" name="facility_csv" accept=".csv" required> ';
    submit_button('Import CSV');
    echo '</form>';
    echo '<div id="facility-import-progress" style="margin-top:2em;"></div>';
    ?>
    <script>
    jQuery(function($){
        $('#facility-csv-upload-form').on('submit', function(e){
            e.preventDefault();
            var form = this;
            var data = new FormData(form);
            data.append('action', 'facility_csv_upload');
            data.append('_wpnonce', $('input[name=_wpnonce]', form).val());
            $('#facility-import-progress').html('<p>Uploading and processing...</p>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                processData: false,
                contentType: false,
                success: function(resp){
                    if(resp.success && resp.data && resp.data.rows){
                        // Start batch import
                        importBatch(resp.data.rows, 0, 0, 0, 0, []);
                    } else {
                        $('#facility-import-progress').html('<div class="notice notice-error"><p>'+ (resp.data && resp.data.message ? resp.data.message : 'Upload failed.') +'</p></div>');
                    }
                },
                error: function(){
                    $('#facility-import-progress').html('<div class="notice notice-error"><p>Upload failed.</p></div>');
                }
            });
            function importBatch(rows, i, imported, updated, failed, errors){
                if(i >= rows.length){
                    var html = '<div class="notice notice-success"><p>Import complete.<br>Imported: '+imported+', Updated: '+updated+', Failed: '+failed+'.</p>';
                    if(errors.length) html += '<details><summary>Errors</summary><ul>'+errors.map(function(e){return '<li>'+e+'</li>';}).join('')+'</ul></details>';
                    html += '</div>';
                    $('#facility-import-progress').html(html);
                    return;
                }
                $('#facility-import-progress').html('<p>Importing row '+(i+1)+' of '+rows.length+'...</p>');
                $.post(ajaxurl, {
                    action: 'facility_csv_import_row',
                    nonce: '<?php echo wp_create_nonce('facility_csv_import_row'); ?>',
                    row: rows[i]
                }, function(resp){
                    if(resp.success){
                        if(resp.data.updated) updated++; else imported++;
                    } else {
                        failed++;
                        errors.push('Row '+(i+1)+': '+(resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                    }
                    importBatch(rows, i+1, imported, updated, failed, errors);
                });
            }
        });
    });
    </script>
    <?php
    echo '</div>';
}

// --- AJAX: Parse CSV and return rows ---
add_action('wp_ajax_facility_csv_upload', function(){
    if(!current_user_can('manage_options') || !check_admin_referer('facility_csv_import')){
        wp_send_json_error(['message'=>'Permission denied.']);
    }
    if(empty($_FILES['facility_csv']['tmp_name'])){
        wp_send_json_error(['message'=>'No file uploaded.']);
    }
    $file = fopen($_FILES['facility_csv']['tmp_name'], 'r');
    $header = fgetcsv($file);
    $map = facility_csv_bulk_import_column_map($header);
    if(!$map){
        fclose($file);
        wp_send_json_error(['message'=>'CSV header missing required columns.']);
    }
    $rows = [];
    while($row = fgetcsv($file)){
        $data = [];
        foreach($map as $key=>$idx){
            $data[$key] = isset($row[$idx]) ? trim($row[$idx]) : '';
        }
        $rows[] = $data;
    }
    fclose($file);
    wp_send_json_success(['rows'=>$rows]);
});

// --- AJAX: Import a single row ---
add_action('wp_ajax_facility_csv_import_row', function(){
    if(!current_user_can('manage_options') || empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'],'facility_csv_import_row')){
        wp_send_json_error(['message'=>'Permission denied.']);
    }
    $row = isset($_POST['row']) ? (array)$_POST['row'] : [];
    $required = ['name','lat','lng'];
    foreach($required as $k){ if(empty($row[$k])) wp_send_json_error(['message'=>'Missing required: '.$k]); }
    $slug = sanitize_title($row['name'].'-'.$row['lat'].'-'.$row['lng']);
    $existing = get_posts([
        'name' => $slug,
        'post_type' => 'facility',
        'post_status' => 'any',
        'numberposts' => 1,
        'meta_key' => '_csv_imported',
        'meta_value' => '1',
    ]);
    $meta = [
        'street_address' => $row['street_address'],
        'city' => $row['city'],
        'county' => $row['county'],
        'state' => $row['state'],
        'zip' => $row['zip'],
        'lat' => $row['lat'],
        'lng' => $row['lng'],
        'industry_sector' => $row['industry_sector'],
        'primary_sic' => $row['primary_sic'],
        'primary_naics' => $row['primary_naics'],
        'chemical' => $row['chemical'],
        'carcinogen' => $row['carcinogen'],
        'pbt' => $row['pbt'],
        'total_releases' => $row['total_releases'],
        'year' => $row['year'],
        '_csv_imported' => '1',
    ];
    if($existing){
        $post_id = $existing[0]->ID;
        wp_update_post([
            'ID'=>$post_id,
            'post_title'=>$row['name'],
            'post_name'=>$slug,
        ]);
        foreach($meta as $k=>$v) update_post_meta($post_id,$k,$v);
        wp_send_json_success(['updated'=>true]);
    } else {
        $post_id = wp_insert_post([
            'post_type'=>'facility',
            'post_title'=>$row['name'],
            'post_name'=>$slug,
            'post_status'=>'publish',
        ]);
        if($post_id){
            foreach($meta as $k=>$v) update_post_meta($post_id,$k,$v);
            wp_send_json_success(['updated'=>false]);
        } else {
            wp_send_json_error(['message'=>'Insert failed.']);
        }
    }
});

// --- Column Map ---
function facility_csv_bulk_import_column_map($header){
    $map = [];
    foreach($header as $i=>$col){
        $col = trim(strtoupper($col));
        if($col==='FACILITY NAME') $map['name']=$i;
        if($col==='STREET ADDRESS') $map['street_address']=$i;
        if($col==='CITY') $map['city']=$i;
        if($col==='COUNTY') $map['county']=$i;
        if($col==='ST') $map['state']=$i;
        if($col==='ZIP') $map['zip']=$i;
        if($col==='LATITUDE') $map['lat']=$i;
        if($col==='LONGITUDE') $map['lng']=$i;
        if($col==='INDUSTRY SECTOR') $map['industry_sector']=$i;
        if($col==='PRIMARY SIC') $map['primary_sic']=$i;
        if($col==='PRIMARY NAICS') $map['primary_naics']=$i;
        if($col==='CHEMICAL') $map['chemical']=$i;
        if($col==='CARCINOGEN') $map['carcinogen']=$i;
        if($col==='PBT') $map['pbt']=$i;
        if($col==='TOTAL RELEASES') $map['total_releases']=$i;
        if($col==='YEAR') $map['year']=$i;
    }
    if(!isset($map['name'],$map['lat'],$map['lng'])) return false;
    return $map;
}

// --- Imported Facilities List Page ---
function facility_csv_imported_list_page(){
    echo '<div class="wrap"><h1>Imported Facilities</h1>';
    if(!current_user_can('manage_options')) return;
    $args = [
        'post_type'=>'facility',
        'posts_per_page'=>50,
        'meta_key'=>'_csv_imported',
        'meta_value'=>'1',
        'orderby'=>'ID',
        'order'=>'DESC',
    ];
    $q = new WP_Query($args);
    if($q->have_posts()){
        echo '<table class="widefat"><thead><tr>';
        echo '<th>Name</th><th>Address</th><th>City</th><th>State</th><th>Year</th><th>Industry</th><th>Total Releases</th><th>Lat</th><th>Lng</th>';
        echo '</tr></thead><tbody>';
        while($q->have_posts()){ $q->the_post();
            echo '<tr>';
            echo '<td><a href="'.get_edit_post_link().'">'.esc_html(get_the_title()).'</a></td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'street_address',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'city',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'state',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'year',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'industry_sector',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'total_releases',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'lat',true)).'</td>';
            echo '<td>'.esc_html(get_post_meta(get_the_ID(),'lng',true)).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        wp_reset_postdata();
    } else {
        echo '<p>No imported facilities found.</p>';
    }
    echo '</div>';
}

// --- Manage Facilities Page (WP_List_Table) ---
if (is_admin() && !class_exists('Facility_Manage_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
    class Facility_Manage_List_Table extends WP_List_Table {
        function get_columns() {
            return [
                'cb' => '<input type="checkbox" />',
                'title' => 'Name',
                'city' => 'City',
                'state' => 'State',
                'year' => 'Year',
                'industry_sector' => 'Industry',
                'total_releases' => 'Total Releases',
                'lat' => 'Lat',
                'lng' => 'Lng',
                'actions' => 'Actions',
            ];
        }
        function column_cb($item) {
            return '<input type="checkbox" name="facility[]" value="' . $item->ID . '" />';
        }
        function column_title($item) {
            $edit_link = get_edit_post_link($item->ID);
            return '<a href="' . esc_url($edit_link) . '">' . esc_html($item->post_title) . '</a>';
        }
        function column_actions($item) {
            $edit_link = get_edit_post_link($item->ID);
            $delete_link = get_delete_post_link($item->ID);
            return '<a href="' . esc_url($edit_link) . '">Edit</a> | <a href="' . esc_url($delete_link) . '" onclick="return confirm(\'Are you sure?\')">Delete</a>';
        }
        function prepare_items() {
            $per_page = 20;
            $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $args = [
                'post_type' => 'facility',
                'posts_per_page' => $per_page,
                'paged' => $paged,
                'meta_key' => '_csv_imported',
                'meta_value' => '1',
                'orderby' => 'ID',
                'order' => 'DESC',
            ];
            if (!empty($_REQUEST['s'])) {
                $args['s'] = sanitize_text_field($_REQUEST['s']);
            }
            $query = new WP_Query($args);
            $this->items = $query->posts;
            $this->_column_headers = [$this->get_columns(), [], []];
            $this->set_pagination_args([
                'total_items' => $query->found_posts,
                'per_page' => $per_page,
                'total_pages' => $query->max_num_pages,
            ]);
        }
        function column_default($item, $column_name) {
            switch ($column_name) {
                case 'city':
                case 'state':
                case 'year':
                case 'industry_sector':
                case 'total_releases':
                case 'lat':
                case 'lng':
                    return esc_html(get_post_meta($item->ID, $column_name, true));
                default:
                    return '';
            }
        }
    }
}

function facility_manage_facilities_page() {
    echo '<div class="wrap"><h1>Manage Facilities</h1>';
    $list_table = new Facility_Manage_List_Table();
    $list_table->prepare_items();
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="facility-manage">';
    $list_table->search_box('Search Facilities', 'facility');
    $list_table->display();
    echo '</form>';
    echo '</div>';
}
