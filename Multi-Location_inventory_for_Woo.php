<?php
/*
Plugin Name: Multi-Location Inventory for WooCommerce
Description: Adds inventory tracking per location and syncs fulfillable stock to WooCommerce. Supports both simple and variable products.
Version: 1.2
Author: Rennie Araucto
*/

// Hook into admin UI for simple products
add_action('add_meta_boxes', function() {
    add_meta_box('multi_location_inventory_box', 'Inventory by Location', 'render_multi_location_inventory_box', 'product', 'side');
});

function render_multi_location_inventory_box($post) {
    if ($post->post_type !== 'product') return;
    if (get_post_meta($post->ID, '_product_type', true) === 'variable') return;

    $labels = [
        'seattle' => "Seattle Warehouse Stock",
        'joseph'  => "Joseph's Stock",
        'brad'    => "Brad's Stock",
        'indy'    => "Indy Stock",
    ];

    foreach ($labels as $loc => $label) {
        $value = get_post_meta($post->ID, "inv_{$loc}_stock", true);
        echo '<p><label>' . $label . ':<br>';
        echo '<input type="number" name="inv_' . $loc . '_stock" value="' . esc_attr($value) . '" style="width:100%" /></label></p>';
    }
}

// Save location stock on product save (simple products)
add_action('save_post_product', function($post_id) {
    if (get_post_meta($post_id, '_product_type', true) === 'variable') return;

    $locations = ['seattle', 'joseph', 'brad', 'indy'];
    $fulfillable = 0;
    $total = 0;

    foreach ($locations as $loc) {
        $key = "inv_{$loc}_stock";
        $val = isset($_POST[$key]) ? intval($_POST[$key]) : 0;
        update_post_meta($post_id, $key, $val);

        $total += $val;
        if ($loc !== 'indy') {
            $fulfillable += $val;
        }
    }

    update_post_meta($post_id, 'inv_total_stock', $total);
    update_post_meta($post_id, 'inv_fulfillable_stock', $fulfillable);

    if (function_exists('wc_get_product')) {
        $product = wc_get_product($post_id);
        if ($product) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($fulfillable);
            $product->set_stock_status($fulfillable > 0 ? 'instock' : 'outofstock');
            $product->save();
        }
    }
});

// Add inventory fields to each variation
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    $labels = [
        'seattle' => "Seattle Warehouse Stock",
        'joseph'  => "Joseph's Stock",
        'brad'    => "Brad's Stock",
        'indy'    => "Indy Stock",
    ];

    foreach ($labels as $loc => $label) {
        $meta_key = "inv_{$loc}_stock";
        $value = get_post_meta($variation->ID, $meta_key, true);
        echo '<div class="form-row form-row-full">
                <label>' . esc_html($label) . ':</label>
                <input type="number" name="variation_' . $meta_key . '[' . $loop . ']" value="' . esc_attr($value) . '" />
              </div>';
    }
}, 10, 3);

// Save variation inventory fields
add_action('woocommerce_save_product_variation', function($variation_id, $i) {
    $locations = ['seattle', 'joseph', 'brad', 'indy'];
    $fulfillable = 0;
    $total = 0;

    foreach ($locations as $loc) {
        $key = "inv_{$loc}_stock";
        $field = $_POST['variation_' . $key][$i] ?? 0;
        $val = intval($field);
        update_post_meta($variation_id, $key, $val);

        $total += $val;
        if ($loc !== 'indy') {
            $fulfillable += $val;
        }
    }

    update_post_meta($variation_id, 'inv_total_stock', $total);
    update_post_meta($variation_id, 'inv_fulfillable_stock', $fulfillable);

    if (function_exists('wc_get_product')) {
        $product = wc_get_product($variation_id);
        if ($product) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($fulfillable);
            $product->set_stock_status($fulfillable > 0 ? 'instock' : 'outofstock');
            $product->save();
        }
    }
}, 10, 2);

// Handle imports from WooCommerce Product Import Suite
add_action('woocommerce_product_import_inserted_product_object', function($product, $data) {
    $post_id = $product->get_id();
    $locations = ['seattle', 'joseph', 'brad', 'indy'];
    $fulfillable = 0;
    $total = 0;

    foreach ($locations as $loc) {
        $val = (int) get_post_meta($post_id, "inv_{$loc}_stock", true);
        $total += $val;
        if ($loc !== 'indy') {
            $fulfillable += $val;
        }
    }

    update_post_meta($post_id, 'inv_total_stock', $total);
    update_post_meta($post_id, 'inv_fulfillable_stock', $fulfillable);

    $product->set_manage_stock(true);
    $product->set_stock_quantity($fulfillable);
    $product->set_stock_status($fulfillable > 0 ? 'instock' : 'outofstock');
    $product->save();
}, 10, 2);