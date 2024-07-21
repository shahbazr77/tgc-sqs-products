<?php
function fetch_squarespace_products_recursive($url, $headers, &$all_products) {
    $response = wp_remote_get($url, array('headers' => $headers));
    if (is_wp_error($response)) {
        echo 'HTTP request error: ' . $response->get_error_message();
        return;
    }
    $http_status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if ($http_status === 200) {
        if (isset($data['products'])) {
            $all_products = array_merge($all_products, $data['products']);
            if (isset($data['pagination']['hasNextPage']) && $data['pagination']['hasNextPage']) {
                $next_url = $url . '&cursor=' . $data['pagination']['nextPageCursor'];
                fetch_squarespace_products_recursive($next_url, $headers, $all_products);
            }
        } else {
            echo 'Failed to retrieve products: ' . json_encode($data);
        }
    } else {
        echo 'HTTP error: ' . $http_status . ' - ' . json_encode($data);
    }
}
function import_or_update_product($product) {
    $is_variable = isset($product['variants']) && count($product['variants']) > 1;
    $sku = isset($product['variants'][0]['sku']) ? $product['variants'][0]['sku'] : '';
    if (empty($sku)) {
        return;
    }

    if($is_variable){
        $sku=$sku."var1122";
        $existing_product_id = get_product_id_by_sku($sku);
    }else{
        $existing_product_id = get_product_id_by_sku($sku);
    }

    $tags = array();
    if (isset($product['tags']) && is_array($product['tags'])) {
        foreach ($product['tags'] as $tag) {
            $tags[] = sanitize_text_field($tag);
        }
    }

    if ($existing_product_id) {
        // Update existing product
        $post_data = array(
            'ID'           => $existing_product_id,
            'post_title'   => sanitize_text_field($product['name']),
            'post_content' => wp_kses_post($product['description']),
        );
        wp_update_post($post_data);
        wp_set_object_terms($existing_product_id, $tags, 'product_tag');
        update_post_meta($existing_product_id, '_imported_from_squarespace', $product['id']);
        if (!$is_variable) {
            $variant = $product['variants'][0];
            update_post_meta($existing_product_id, '_price', $variant['pricing']['basePrice']['value']);
            update_post_meta($existing_product_id, '_regular_price', $variant['pricing']['basePrice']['value']);
            if (isset($variant['pricing']['salePrice']['value']) and $variant['pricing']['salePrice']['value'] > 0) {
                update_post_meta($existing_product_id, '_sale_price', $variant['pricing']['salePrice']['value']);
                update_post_meta($existing_product_id, '_price', $variant['pricing']['salePrice']['value']);
            }
            update_post_meta($existing_product_id, '_stock', $variant['stock']['quantity']);
            update_post_meta($existing_product_id, '_manage_stock', 'yes');
            if ($variant['stock']['quantity'] > 0) {
                update_post_meta($existing_product_id, '_stock_status', 'instock');
            } elseif ($variant['stock']['quantity'] == 0 && $variant['stock']['unlimited'] == 1) {
                update_post_meta($existing_product_id, '_stock_status', 'instock');
            } else {
                update_post_meta($existing_product_id, '_stock_status', 'outofstock');
            }
        } else {
            // Update variants for variable product
            foreach ($product['variants'] as $variant) {
                $variation_id = create_or_update_variation($existing_product_id, $variant);
                if ($variation_id) {
                    $variation_attributes = array();
                    foreach ($variant['attributes'] as $attribute_name => $attribute_value) {
                        $taxonomy = 'pa_' . sanitize_title($attribute_name);
                        $variation_attributes[$taxonomy] = $attribute_value;
                    }
                    update_post_meta($variation_id, '_variation_attributes', $variation_attributes);
                }
            }
        }
        return;
    }

    // Insert new product
    $post_data = array(
        'post_title'   => sanitize_text_field($product['name']),
        'post_content' => wp_kses_post($product['description']),
        'post_status'  => 'pending',
        'post_type'    => 'product',
    );
    $post_id = wp_insert_post($post_data);
    if ($post_id) {

        $current_user = wp_get_current_user();
        $new_meta_value = $current_user->user_login;
        update_post_meta( $post_id, 'woo_dropshipper', $new_meta_value);
        update_post_meta( $post_id, 'woo_drop_author', get_current_user_id());

        wp_set_object_terms($post_id, $tags, 'product_tag');
        update_post_meta($post_id, '_imported_from_squarespace', $product['id']);
        if (isset($product['images']) && is_array($product['images'])) {
            $image_ids = array();
            foreach ($product['images'] as $index => $image) {
                $image_url = esc_url($image['url']);
                $image_id = media_sideload_image($image_url, $post_id, null, 'id');
                if (!is_wp_error($image_id)) {
                    if ($index == 0) {
                        set_post_thumbnail($post_id, $image_id);
                    } else {
                        $image_ids[] = $image_id;
                    }
                }
            }
            if (!empty($image_ids)) {
                update_post_meta($post_id, '_product_image_gallery', implode(',', $image_ids));
            }
        }
        if ($is_variable) {
            wp_set_object_terms($post_id, 'variable', 'product_type');
            update_post_meta($post_id, '_sku', $sku);
            $all_attributes = array();
            foreach ($product['variants'] as $variant) {
                if (isset($variant['attributes'])) {
                    foreach ($variant['attributes'] as $attribute_name => $attribute_value) {
                        $taxonomy = 'pa_' . sanitize_title($attribute_name);
                        if (!isset($all_attributes[$taxonomy])) {
                            $all_attributes[$taxonomy] = array(
                                'name'         => $taxonomy,
                                'is_visible'   => 1,
                                'is_variation' => 1,
                                'is_taxonomy'  => 1,
                                'options'      => array(),
                            );
                        }
                        if (!in_array($attribute_value, $all_attributes[$taxonomy]['options'])) {
                            $all_attributes[$taxonomy]['options'][] = $attribute_value;
                        }
                    }
                }
            }
            foreach ($all_attributes as $taxonomy => $attribute) {
                if (!taxonomy_exists($taxonomy)) {
                    register_taxonomy(
                        $taxonomy,
                        'product',
                        array(
                            'hierarchical' => false,
                            'label' => ucfirst(str_replace('pa_', '', $taxonomy)),
                            'query_var' => true,
                            'rewrite' => array('slug' => $taxonomy),
                        )
                    );
                }
                foreach ($attribute['options'] as $option) {
                    if (!term_exists($option, $taxonomy)) {
                        wp_insert_term($option, $taxonomy);
                    }
                }
            }
            update_post_meta($post_id, '_product_attributes', $all_attributes);
            foreach ($all_attributes as $taxonomy => $attribute) {
                $values = $attribute['options'];
                wp_set_object_terms($post_id, $values, $taxonomy);
            }
            foreach ($product['variants'] as $variant) {
                $variation_id = create_or_update_variation($post_id, $variant);
                if ($variation_id) {
                    $variation_attributes = array();
                    foreach ($variant['attributes'] as $attribute_name => $attribute_value) {
                        $taxonomy = 'pa_' . sanitize_title($attribute_name);
                        $variation_attributes[$taxonomy] = $attribute_value;
                    }
                    update_post_meta($variation_id, '_variation_attributes', $variation_attributes);
                }
            }

        } else {
            $variant = $product['variants'][0];
            update_post_meta($post_id, '_price', $variant['pricing']['basePrice']['value']);
            update_post_meta($post_id, '_regular_price', $variant['pricing']['basePrice']['value']);
            if (isset($variant['pricing']['salePrice']['value']) and $variant['pricing']['salePrice']['value'] > 0) {
                update_post_meta($post_id, '_sale_price', $variant['pricing']['salePrice']['value']);
                update_post_meta($post_id, '_price', $variant['pricing']['salePrice']['value']);
            }
            update_post_meta($post_id, '_regular_price', $variant['pricing']['basePrice']['value']);
            update_post_meta($post_id, '_stock', $variant['stock']['quantity']);
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_sku', $sku);
            if ($variant['stock']['quantity'] > 0) {
                update_post_meta($post_id, '_stock_status', 'instock');
            } elseif ($variant['stock']['quantity'] == 0 && $variant['stock']['unlimited'] == 1) {
                update_post_meta($post_id, '_stock_status', 'instock');
            } else {
                update_post_meta($post_id, '_stock_status', 'outofstock');
            }
        }
    }

}
function single_or_update_product($product) {
    $is_variable = isset($product['variants']) && count($product['variants']) > 1;
    $sku = isset($product['variants'][0]['sku']) ? $product['variants'][0]['sku'] : '';
    if (empty($sku)) {
        return;
    }
    if($is_variable){
        $sku=$sku."var1122";
        $existing_product_id = get_product_id_by_sku($sku);
    }else{
        $existing_product_id = get_product_id_by_sku($sku);
    }

    $tags = array();
    if (isset($product['tags']) && is_array($product['tags'])) {
        foreach ($product['tags'] as $tag) {
            $tags[] = sanitize_text_field($tag);
        }
    }
    if ($existing_product_id) {
        // Update existing product
        $post_data = array(
            'ID'           => $existing_product_id,
            'post_title'   => sanitize_text_field($product['name']),
            'post_content' => wp_kses_post($product['description']),
        );
        wp_update_post($post_data);
        wp_set_object_terms($existing_product_id, $tags, 'product_tag');
        update_post_meta($existing_product_id, '_imported_from_squarespace', $product['id']);
        if (isset($product['images']) && is_array($product['images'])) {
            $image_ids = array();
            foreach ($product['images'] as $index => $image) {
                $image_url = esc_url($image['url']);
                $image_id = media_sideload_image($image_url, $existing_product_id, null, 'id');
                if (!is_wp_error($image_id)) {
                    if ($index == 0) {
                        set_post_thumbnail($existing_product_id, $image_id);
                    } else {
                        $image_ids[] = $image_id;
                    }
                }
            }
            if (!empty($image_ids)) {
                update_post_meta($existing_product_id, '_product_image_gallery', implode(',', $image_ids));
            }
        }

        if (!$is_variable) {
            $variant = $product['variants'][0];
            update_post_meta($existing_product_id, '_price', $variant['pricing']['basePrice']['value']);
            update_post_meta($existing_product_id, '_regular_price', $variant['pricing']['basePrice']['value']);
            if (isset($variant['pricing']['salePrice']['value']) and $variant['pricing']['salePrice']['value'] > 0) {
                update_post_meta($existing_product_id, '_sale_price', $variant['pricing']['salePrice']['value']);
                update_post_meta($existing_product_id, '_price', $variant['pricing']['salePrice']['value']);
            }
            update_post_meta($existing_product_id, '_stock', $variant['stock']['quantity']);
            update_post_meta($existing_product_id, '_manage_stock', 'yes');
            if ($variant['stock']['quantity'] > 0) {
                update_post_meta($existing_product_id, '_stock_status', 'instock');
            } elseif ($variant['stock']['quantity'] == 0 && $variant['stock']['unlimited'] == 1) {
                update_post_meta($existing_product_id, '_stock_status', 'instock');
            } else {
                update_post_meta($existing_product_id, '_stock_status', 'outofstock');
            }
            return 1;
        } else {
            // Update variants for variable product
            foreach ($product['variants'] as $variant) {
                $variation_id = create_or_update_variation($existing_product_id, $variant);
                if ($variation_id) {
                    $variation_attributes = array();
                    foreach ($variant['attributes'] as $attribute_name => $attribute_value) {
                        $taxonomy = 'pa_' . sanitize_title($attribute_name);
                        $variation_attributes[$taxonomy] = $attribute_value;
                    }
                    update_post_meta($variation_id, '_variation_attributes', $variation_attributes);
                }
            }
            return 1;
        }
        return 0;
    }

}
function create_or_update_variation($product_id, $variant) {
    $sku = $variant['sku'];
    $variation_id = get_product_id_by_sku($sku);
    $post_data = array(
        'post_title'   => 'Variation for product #' . $product_id,
        'post_status'  => 'publish',
        'post_parent'  => $product_id,
        'post_type'    => 'product_variation',
    );

    if ($variation_id) {
        $post_data['ID'] = $variation_id;
        wp_update_post($post_data);
    } else {
        $variation_id = wp_insert_post($post_data);
        if (isset($variant['image']['url'])) {
            $image_url = esc_url($variant['image']['url']);
            $image_id = media_sideload_image($image_url, $variation_id, null, 'id');
            if (!is_wp_error($image_id)) {
                update_post_meta($variation_id, '_thumbnail_id', $image_id);
            }
        }
    }

    if (isset($variant['attributes'])) {
        $variation_attributes = array();
        foreach ($variant['attributes'] as $attribute_name => $attribute_value) {
            $taxonomy = 'pa_' . sanitize_title($attribute_name);
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy(
                    $taxonomy,
                    'product',
                    array(
                        'hierarchical' => false,
                        'label' => ucfirst($attribute_name),
                        'query_var' => true,
                        'rewrite' => array('slug' => $taxonomy),
                    )
                );
            }
            if (!term_exists($attribute_value, $taxonomy)) {
                wp_insert_term($attribute_value, $taxonomy);
            }
            wp_set_object_terms($variation_id, $attribute_value, $taxonomy);
            $variation_attributes[$taxonomy] = $attribute_value;
        }
        update_post_meta($variation_id, '_variation_attributes', $variation_attributes);
    }

    update_post_meta($variation_id, '_sku', $sku);

    update_post_meta($variation_id, '_price', $variant['pricing']['basePrice']['value']);
    update_post_meta($variation_id, '_regular_price', $variant['pricing']['basePrice']['value']);
    if (isset($variant['pricing']['salePrice']['value']) and $variant['pricing']['salePrice']['value'] > 0) {
        update_post_meta($variation_id, '_sale_price', $variant['pricing']['salePrice']['value']);
        update_post_meta($variation_id, '_price', $variant['pricing']['salePrice']['value']);
    }
    update_post_meta($variation_id, '_stock', $variant['stock']['quantity']);
    update_post_meta($product_id, '_stock', $variant['stock']['quantity']);
    update_post_meta($variation_id, '_manage_stock', 'yes');
    if ($variant['stock']['quantity'] > 0) {
        update_post_meta($variation_id, '_stock_status', 'instock');
        update_post_meta($product_id, '_stock_status', 'instock');
    } elseif ($variant['stock']['quantity'] == 0 && $variant['stock']['unlimited'] == 1) {
        update_post_meta($variation_id, '_stock_status', 'instock');
        update_post_meta($product_id, '_stock_status', 'instock');
    } else {
        update_post_meta($variation_id, '_stock_status', 'outofstock');
        update_post_meta($product_id, '_stock_status', 'instock');
    }

    if (isset($variant['shippingMeasurements']['dimensions'])) {
        $dimensions = $variant['shippingMeasurements']['dimensions'];
        $length_cm = $dimensions['length'] * 2.54;
        $width_cm = $dimensions['width'] * 2.54;
        $height_cm = $dimensions['height'] * 2.54;
        update_post_meta($variation_id, '_length', $length_cm);
        update_post_meta($variation_id, '_width', $width_cm);
        update_post_meta($variation_id, '_height', $height_cm);
    }

    if (isset($variant['shippingMeasurements']['weight'])) {
        $weight_lb = $variant['shippingMeasurements']['weight']['value'];
        $weight_kg = $weight_lb * 0.453592;
        update_post_meta($variation_id, '_weight', $weight_kg);
    }

    return $variation_id;
}
function get_product_id_by_sku($sku) {
    global $wpdb;

    $product_id = $wpdb->get_var($wpdb->prepare("
        SELECT post_id FROM {$wpdb->postmeta}
        WHERE meta_key = '_sku' AND meta_value = %s
    ", $sku));

    return $product_id;
}
function fetch_squarespace_products() {
    if (!current_user_can('manage_options')) {
        return;
    }
    //$api_key = get_option('vendor_squarespace_api_key');
    $user_id = get_current_user_id();
    $api_key = get_user_meta($user_id, 'vendor_squarespace_api_key', true);

    if (empty($api_key)) {
        echo '<p>Please save your Squarespace API key first.</p>';
        return;
    }

    $api_url = 'https://api.squarespace.com/1.1/commerce/products';
    $headers = array(
        'Authorization' => 'Bearer ' . $api_key,
        'User-Agent' => 'YOUR_CUSTOM_APP_DESCRIPTION',
    );
    $all_products = array();
    $type = 'PHYSICAL'; // Use 'DIGITAL' if you want to fetch digital products
    $initial_url = $api_url . '?type=' . $type;
    fetch_squarespace_products_recursive($initial_url, $headers, $all_products);

    if (empty($all_products)) {
        echo '<p>No products found.</p>';
        return;
    }

    $batch_size = 30;
    $batches = array_chunk($all_products, $batch_size);
    foreach ($batches as $batch) {
        foreach ($batch as $product) {
            import_or_update_product($product);
        }
        sleep(5); // Optional: To avoid hitting rate limits
    }
    echo '<p style="float: right;background-color: green;color:#fff;padding:8px 6px; ">Products imported successfully!</p>';
}