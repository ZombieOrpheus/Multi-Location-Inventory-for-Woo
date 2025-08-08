<?php
/*
Plugin Name: Multi-Location Inventory for WooCommerce
Description: Adds inventory tracking per location and syncs fulfillable stock to WooCommerce. Supports both simple and variable products. Skips subscriptions and digital items. Includes per-product opt-in.
Version: 1.4
Author: Rennie Araucto
*/

// ===== Helpers =====
function mli_is_subscription_product($product) {
    if (!$product) return false;
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
    return (bool) ($product->is_virtual() || $product->is_downloadable());
}

function mli_should_manage_stock($product) {
    // Tangible + not a subscription
    return ($product && !mli_is_subscription_product($product) && !mli_is_digital_like($product));
}

/**
 * Opt-in logic (backward compatible):
 * - If _mli_enabled is 'yes' => enabled
 * - If 'no' => disabled
 * - If unset => grandfather: behave as before (enabled) **only** for tangible, non-subscription products
 */
function mli_is_enabled($product) {
    if (!$product) return false;
    $id = $product->get_id();
    $meta = get_post_meta($id, '_mli_enabled', true);
    if ($meta === 'yes') return true;
    if ($meta === 'no') return false;
    // Grandfather behavior to avoid breaking existing setup:
    return mli_should_manage_stock($product);
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

    // Sum per-location meta
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

    // Apply to Woo stock if enabled AND product is eligible to have stock managed
    if (mli_is_enabled($product) && mli_should_manage_stock($product)) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($fulfillable);
        $product->set_stock_status($fulfillable > 0 ? 'instock' : 'outofstock');
    } else {
        // Ensure digital/subscription or opted-out items are sellable and not tracked
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
        $product->set_stock_status('instock');
    }

    $product->save();
}

// ===== Global Opt-in Checkbox (visible for all products) =====
add_action('add_meta_boxes', function() {
    add_meta_box('mli_opt_in_box', 'Multi-Location Inventory', 'mli_render_opt_in_box', 'product', 'side', 'high');
});
function mli_render_opt_in_box($post) {
    $product = wc_get_product($post->ID);
    if (!$product) return;

    wp_nonce_field('mli_save_optin_' . $post->ID, 'mli_optin_nonce');

    $meta = get_post_meta($post->ID, '_mli_enabled', true); // 'yes' | 'no' | ''
    $checked = ($meta === 'yes') ? 'checked' : '';

    echo '<p><label><input type="checkbox" name="mli_enabled" value="yes" ' . $checked . ' /> ';
    echo esc_html__('Use Multi-Location Inventory for this product', 'mli') . '</label></p>';

    // Display current effective status (help text)
    $effective = mli_is_enabled($product) ? 'Enabled' : 'Disabled';
    echo '<p><em>Status: ' . esc_html($effective) . ' (';
    echo esc_html__('unchecked defaults to enabled for physical, non-subscription products to preserve existing behavior', 'mli');
    echo ')</em></p>';
}

// Save checkbox meta
add_action('save_post_product', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['mli_optin_nonce']) || !wp_verify_nonce($_POST['mli_optin_nonce'], 'mli_save_optin_' . $post_id)) return;

    if (isset($_POST['mli_enabled']) && $_POST['mli_enabled'] === 'yes') {
        update_post_meta($post_id, '_mli_enabled', 'yes');
    } else {
        // Explicitly store 'no' when unchecked so the merchant can opt out
        update_post_meta($post_id, '_mli_enabled', 'no');
    }
});

// ===== Location Inventory Metabox (only when enabled AND product is a simple physical) =====
add_action('add_meta_boxes', function() {
    add_meta_box('multi_location_inventory_box', 'Inventory by Location', 'render_multi_location_inventory_box', 'product', 'side');
});

function render_multi_location_inventory_box($post) {
    $product = wc_get_product($post->ID);
    if (!$product) return;

    // Only show when the feature is enabled AND this is a simple physical, non-subscription product
    if (!$product->is_type('simple')) return;
    if (!mli_is_enabled($product)) return;
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

    // Accept inputs regardless; we’ll decide later whether to apply to Woo stock
    $locations = array_keys(mli_locations());
    foreach ($locations as $loc) {
        $key = "inv_{$loc}_stock";
        $val = isset($_POST[$key]) ? (int) $_POST[$key] : 0;
        if ($val < 0) $val = 0;
        update_post_meta($post_id, $key, $val);
    }

    mli_recalc_and_apply_stock($post_id);
});

// ===== Variation fields (only show/apply when parent is enabled, non-subscription, physical variations) =====
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    $variation_product = wc_get_product($variation->ID);
    if (!$variation_product) return;

    $parent = wc_get_product($variation_product->get_parent_id());
    if (!$parent) return;

    if (!mli_is_enabled($parent)) return;
    if ($parent->is_type('variable-subscription')) return;
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
    if ($parent && !$mli_is_enabled = mli_is_enabled($parent)) {
        // Parent opted out: ensure not managed
        $variation_product->set_manage_stock(false);
        $variation_product->set_stock_status('instock');
        $variation_product->save();
        return;
    }

    if ($parent && $parent->is_type('variable-subscription')) {
        // Never manage stock for subscription variations
        foreach (array_keys(mli_locations()) as $loc) {
            $key = "variation_inv_{$loc}_stock";
            if (isset($_POST[$key][$i])) {
                update_post_meta($variation_id, "inv_{$loc}_stock", (int) $_POST[$key][$i]);
            }
        }
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
    // Recalculate from existing meta and apply guarded + opt-in stock logic
    mli_recalc_and_apply_stock($product->get_id());
}, 10, 2);