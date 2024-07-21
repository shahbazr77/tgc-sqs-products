<?php
/*
Plugin Name: TGC Squarespace Products Import
Description: Fetches products from Squarespace and inserts them into the WordPress database.
Text Domain: tgc-sqs
Version: 1.0
Author: Yodo Design
*/

if (!defined('ABSPATH')) {
    exit;
}
define('TGCSQS_Plugin_Path', plugin_dir_path(__FILE__));
define('TGCSQS_Plugin_Url', plugin_dir_url(__FILE__));

include (TGCSQS_Plugin_Path. 'inc/tgc-sqs-ajax-actions.php');
include (TGCSQS_Plugin_Path. 'inc/tgc-sqs-extra-function.php');

/*incluse the enqueue scripte functon */
function vendor_squarespace_enqueue_scripts() {
    wp_enqueue_style( 'vendor-squarespace-custom', TGCSQS_Plugin_Url. 'assets/css/custom.css' );
    wp_enqueue_script('jquery');
    wp_enqueue_script('progressbar-js', 'https://cdnjs.cloudflare.com/ajax/libs/progressbar.js/1.0.1/progressbar.min.js', array('jquery'), '1.0.1', true);
    wp_enqueue_script('vendor-squarespace-custom', TGCSQS_Plugin_Url . 'assets/js/custom.js', array('jquery'), null, true);
    // Pass the AJAX URL to the script
    wp_localize_script('vendor-squarespace-custom', 'object_squarespace', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('vendor_squarespace_progress_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'vendor_squarespace_enqueue_scripts');

/*Create the menu page from plugin*/
function vendor_squarespace_products_menu() {

    $custom_capabillity="manage_options";
    if (in_array( 'woocommerce-dropshippers-vendors/woocommerce-dropshippers-vendors.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        if (current_user_can('administrator')) {
            $custom_capabillity="manage_options";
        } else {
            $custom_capabillity="show_dropshipper_widget";
        }
    }



    add_menu_page(
        __('Vendor Squarespace Products','tgc-sqs'),
        __('Vendor Squarespace Products','tgc-sqs'),
        $custom_capabillity,
        'vendor_squarespace_products',
        'vendor_squarespace_products_page',
        'dashicons-products',
        30.6
    );
}
add_action('admin_menu', 'vendor_squarespace_products_menu',999);

function vendor_squarespace_products_page() {
    $user_id = get_current_user_id();
    $api_key = get_user_meta($user_id, 'vendor_squarespace_api_key', true);
    $loader_svg = TGCSQS_Plugin_Url.'assets/images/bars-white.svg';
    ?>
    <div class="wrap">
        <h2>Vendor Squarespace Products</h2>
        <form method="post" action="">
            <?php wp_nonce_field('vendor_squarespace_products_nonce', 'vendor_squarespace_products_nonce_field'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="vendor_squarespace_api_key">Squarespace API Key:</label></th>
                    <td><input type="text" id="vendor_squarespace_api_key" name="vendor_squarespace_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>
        <form id="import-products-form" method="post" action="">
            <?php wp_nonce_field('import_squarespace_products_nonce', 'import_squarespace_products_nonce_field'); ?>
            <?php submit_button('Import Products', 'primary', 'import_products'); ?>
        </form>
        <div id="progress-container" style="display: none;">
            <div id="progress-bar" style="width: 0%; height: 25px; background-color: green;"></div>
        </div>
        <div id="progress-message"></div>
        <div class="sqsoverlay_loader">
            <div class="sqsoverlay__inner">
                <div class="sqsoverlay__content">
                    <div class="sqsloader">
                        <img class="sqs-loader" src="<?php echo esc_url($loader_svg); ?>" />
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
/*Save the API Key that given by Vendor*/
function save_vendor_squarespace_api_key() {
    if (isset($_POST['vendor_squarespace_api_key'])) {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['vendor_squarespace_products_nonce_field']) || !wp_verify_nonce($_POST['vendor_squarespace_products_nonce_field'], 'vendor_squarespace_products_nonce')) {
            return;
        }
        $api_key = sanitize_text_field($_POST['vendor_squarespace_api_key']);
       // update_option('vendor_squarespace_api_key', $api_key);
        $user_id = get_current_user_id();
        if ($user_id) {
            update_user_meta($user_id, 'vendor_squarespace_api_key', $api_key);
            // Optionally, add a success message
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>API key saved successfully.</p></div>';
            });
        }

    }
}
add_action('admin_init', 'save_vendor_squarespace_api_key');
/*add the filter against the column of the squarespace sync button*/

add_filter('manage_edit-product_columns', 'add_sync_with_squarespace_column');
function add_sync_with_squarespace_column($columns) {
    $columns['sync_with_squarespace'] = __('Sync Squrespace', 'your-text-domain');
    return $columns;
}
// Populate the custom column with a sync button
add_action('manage_product_posts_custom_column', 'populate_sync_with_squarespace_column', 10, 2);
function populate_sync_with_squarespace_column($column, $post_id) {
    if ($column === 'sync_with_squarespace') {
        $from_squarespace = get_post_meta($post_id, '_imported_from_squarespace', true);
        $user_id = get_current_user_id();
        $api_key = get_user_meta($user_id, 'vendor_squarespace_api_key', true);
        if ($from_squarespace and $api_key and !current_user_can('administrator')) {
            $nonce = wp_create_nonce('sync_product_' . $post_id);
            echo '<button data-my="'.$from_squarespace.'" class="button sync-with-squarespace" data-product-id="' . $post_id . '" data-nonce="' . $nonce . '">' . __('Sync', 'your-text-domain') . '</button>';
        }
    }
}