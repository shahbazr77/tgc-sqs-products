<?php
/*action about the product fetching */
add_action('wp_ajax_vendor_squarespace_import_products', 'handle_import_squarespace_products_fun');
function handle_import_squarespace_products_fun() {
    check_ajax_referer('vendor_squarespace_progress_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
   // $api_key = get_option('vendor_squarespace_api_key');

    $user_id = get_current_user_id();
    $api_key = get_user_meta($user_id, 'vendor_squarespace_api_key', true);



    if (empty($api_key)) {
        wp_send_json_error('API key is missing');
    }
    $step = isset($_POST['step']) ? intval($_POST['step']) : 0;
    $batch_size = 10;  // Adjust batch size as needed

    if ($step === 0) {
        // First step: Initialize and fetch all products
        delete_transient('vendor_squarespace_all_products');
        $api_url = 'https://api.squarespace.com/1.1/commerce/products?type=PHYSICAL';
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'User-Agent' => 'YOUR_CUSTOM_APP_DESCRIPTION',
        );
        $all_products = array();
        fetch_squarespace_products_recursive($api_url, $headers, $all_products);
        set_transient('vendor_squarespace_all_products', $all_products, 3600);
    } else {
        // Subsequent steps: Process batches of products
        $all_products = get_transient('vendor_squarespace_all_products');
    }

    if (empty($all_products)) {
        wp_send_json_error('No products found');
    }

    $total_products = count($all_products);
    $batches = array_chunk($all_products, $batch_size);
    if (isset($batches[$step])) {
        foreach ($batches[$step] as $product) {
            import_or_update_product($product);
        }
    }

    $progress = round(($step + 1) / count($batches) * 100);
    $next_step = $step + 1;

    if ($next_step < count($batches)) {
        wp_send_json_success(array('step' => $next_step, 'progress' => $progress, 'message' => "$progress% completed"));
    } else {
        delete_transient('vendor_squarespace_all_products');
        wp_send_json_success(array('step' => 'done', 'progress' => 100, 'message' => 'Import completed!'));
    }
}

/*action about the product single fetching */
add_action('wp_ajax_sync_single_product_with_squarespace', 'sync_product_with_squarespace_fun');
function sync_product_with_squarespace_fun() {
    check_ajax_referer('sync_product_' . $_POST['product_id'], 'nonce');
   // $api_key = get_option('vendor_squarespace_api_key');

    $user_id = get_current_user_id();
    $api_key = get_user_meta($user_id, 'vendor_squarespace_api_key', true);


    if (empty($api_key)) {
        wp_send_json_error('API key is missing');
    }

    $product_id = intval($_POST['product_id']);
    $squarespace_id = get_post_meta($product_id, '_imported_from_squarespace', true);

    $api_url = "https://api.squarespace.com/1.1/commerce/products/" .$squarespace_id;

    // Set up the headers
    $headers = array(
        'Authorization' => 'Bearer ' . $api_key,
        'User-Agent'    => 'YOUR_CUSTOM_APP_DESCRIPTION'
    );

    // Make the request
    $response = wp_remote_get($api_url, array(
        'headers' => $headers
    ));

    // Check for errors
    if (is_wp_error($response)) {
        return new WP_Error('squarespace_api_error', 'Error connecting to Squarespace API');
    }

    // Parse the response
    $body = wp_remote_retrieve_body($response);
    $products = json_decode($body, true);

    if (is_wp_error($response)) {
        echo 'HTTP request error: ' . $response->get_error_message();
        return;
    }
    //$single_products = array();
    $http_status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if ($http_status === 200) {
        if (isset($data['products'])) {
            //$single_product = array_merge($single_products, $data['products']);

            $single_product = $data['products'][0];

           // $single_product = $data;

            $single_return = single_or_update_product($single_product);
            wp_send_json_success("Product synced successfully. Return value: " . $single_return);


        } else {
            echo 'Failed to retrieve products: ' . json_encode($data);
        }
    } else {
        echo 'HTTP error: ' . $http_status . ' - ' . json_encode($data);
    }


//    print_r($single_product);
//    die();
//
//    $single_return=single_or_update_product($single_product);
//
//    echo "this is single return value=====".$single_return;
//    die();




}



