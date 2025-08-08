<?php
/*
Plugin Name: Multi-Location Inventory for WooCommerce
Description: Adds inventory tracking per location and syncs fulfillable stock to WooCommerce. Supports both simple and variable products. Skips subscriptions and digital items.
Version: 1.3
Author: Rennie Araucto
*/

// ===== Helpers =====
function mli_is_subscription_product($product) {
    if (!$product) return false;
    // Parent types cover subscription variations as well
    if ($product->is_type(['subscription','variable-subscription'])) return true;
    $parent_id = method_exists($product, 'get_parent_id') ? $product->get_parent_id() : 0;
    if ($parent_id) {
        $parent = wc_get_product($parent_id);
        if ($parent && $parent->is_type(['subscription','variable-subscription'])) return true;
    }
    return false;
}

function mli_is_digital_like($product) {
    if (!$product) return false;
    // Treat virtual OR downloadable as digital-like (don’t manage stock)
    return (bool) ($product->is_virtual() || $product->is_downloadable());
}

function mli_should_manage_stock($product) {
    // Only for tangible, non-subscription products
    return ($product && !mli_is_subscription_product($product) && !mli_is_digital_like($product));
}

function mli_locations() {
    return [
        'seattle' => "Seattle Warehouse Stock",
        'joseph'  => "Joseph's Stock",
        'brad'    => "Brad's Stock",
        'indy'    => "Indy Stock",
    ];
}

function mli_recalc_and_apply_stock($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) return;

    // Sum meta for our four locations
    $locations = array_keys(mli_locations());
    $fulfillable = 0;
    $total = 0;

    foreach ($locations as $loc) {
        $val = (int) get_post_meta($product_id, "inv_{$loc}_stock", true);
        $total += $val;
        if ($loc !== 'indy') {
            $fulfillable += $val; // exclude Indy from fulfillable
        }
    }

    update_post_meta($product_id, 'inv_total_stock', $total);
    update_post_meta($product_id, 'inv_fulfillable_stock', $fulfillable);

    // Apply to Woo stock, gated
    if (mli_should_manage_stock($product)) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($fulfillable);
        $product->set_stock_status($fulfillable > 0 ? 'instock' : 'outofstock');
    } else {
        // Ensure digital/subscription items are sellable and not tracked
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null); // clears quantity on most product types
        // For safety, force in stock (subscriptions/digital shouldn’t be OOS because of inventory)
        $product->set_stock_status('instock');
    }

    $product->save();
}

// ===== Metabox for SIMPLE physical non-subscription products =====
add_action('add_meta_boxes', function() {
    add_meta_box('multi_location_inventory_box', 'Inventory by Location', 'render_multi_location_inventory_box', 'product', 'side');
});

function render_multi_location_inventory_box($post) {
    $product = wc_get_product($post->ID);
    if (!$product) return;

    // Only show for SIMPLE, physical, non-subscription products
    if (!$product->is_type('simple')) return;
    if (!mli_should_manage_stock($product)) return;

    wp_nonce_field('mli_save_meta_' . $post->ID, 'mli_nonce');

    foreach (mli_locations() as $loc => $label) {
        $value = get_post_meta($post->ID, "inv_{$loc}_stock", true);
        $value = is_numeric($value) ? (int) $value : 0;
        echo '<p><label>' . esc_html($label) . ':<br>';
        echo '<input type="number" min="0" step="1" name="inv_' . esc_attr($loc) . '_stock" value="' . esc_attr($value) . '" style="width:100%" /></label></p>';
    }
}

// Save location stock on product save (simple products)
add_action('save_post_product', function($post_id) {
    // Basic checks
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['mli_nonce']) || !wp_verify_nonce($_POST['mli_nonce'], 'mli_save_meta_' . $post_id)) return;

    $product = wc_get_product($post_id);
    if (!$product) return;

    // Accept inputs even if product is currently digital; we’ll still store values but won’t manage stock
    $locations = array_keys(mli_locations());
    foreach ($locations as $loc) {
        $key = "inv_{$loc}_stock";
        $val = isset($_POST[$key]) ? (int) $_POST[$key] : 0;
        if ($val < 0) $val = 0;
        update_post_meta($post_id, $key, $val);
    }

    mli_recalc_and_apply_stock($post_id);
});

// ===== Variation fields (only for variable, non-subscription, physical variations) =====
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    $variation_product = wc_get_product($variation->ID);
    if (!$variation_product) return;

    $parent = wc_get_product($variation_product->get_parent_id());
    if (!$parent) return;

    // Skip subscription families
    if ($parent->is_type('variable-subscription')) return;

    // Skip virtual/downloadable variations
    if (mli_is_digital_like($variation_product)) return;

    foreach (mli_locations() as $loc => $label) {
        $meta_key = "inv_{$loc}_stock";
        $value = get_post_meta($variation->ID, $meta_key, true);
        $value = is_numeric($value) ? (int) $value : 0;
        echo '<div class="form-row form-row-full">
                <label>' . esc_html($label) . ':</label>
                <input type="number" min="0" step="1" name="variation_' . esc_attr($meta_key) . '[' . esc_attr($loop) . ']" value="' . esc_attr($value) . '" />
              </div>';
    }
}, 10, 3);

// Save variation inventory fields
add_action('woocommerce_save_product_variation', function($variation_id, $i) {
    $variation_product = wc_get_product($variation_id);
    if (!$variation_product) return;

    $parent = wc_get_product($variation_product->get_parent_id());
    if ($parent && $parent->is_type('variable-subscription')) {
        // Never manage stock for subscription variations
        foreach (array_keys(mli_locations()) as $loc) {
            // Still allow saving numbers if provided, but don’t apply to Woo stock
            $key = "variation_inv_{$loc}_stock";
            if (isset($_POST[$key][$i])) {
                update_post_meta($variation_id, "inv_{$loc}_stock", (int) $_POST[$key][$i]);
            }
        }
        // Ensure stock is not managed and is sellable
        $variation_product->set_manage_stock(false);
        $variation_product->set_stock_status('instock');
        $variation_product->save();
        return;
    }

    // Save inputs
    $locations = array_keys(mli_locations());
    foreach ($locations as $loc) {
        $field = $_POST['variation_inv_' . $loc . '_stock'][$i] ?? null;
        $val = is_null($field) ? 0 : (int) $field;
        if ($val < 0) $val = 0;
        update_post_meta($variation_id, "inv_{$loc}_stock", $val);
    }

    // Recalc and apply for this variation
    mli_recalc_and_apply_stock($variation_id);
}, 10, 2);

// ===== Import hook (Product Import / CSV) =====
add_action('woocommerce_product_import_inserted_product_object', function($product, $data) {
    if (!$product) return;
    // Recalculate from existing meta and apply guarded stock logic
    mli_recalc_and_apply_stock($product->get_id());
}, 10, 2);