<?php
/**
 * Bundle Builder for WooCommerce — Uninstall
 *
 * Fired when the plugin is uninstalled (deleted) from WordPress.
 * Cleans up all plugin data from the database.
 *
 * @package AOP_BundleBuilder
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Only remove data if the user has opted in via the
 * "Delete data on uninstall" setting. This prevents
 * accidental data loss when users reinstall the plugin.
 */
$delete_data = get_option( 'aop_bb_delete_data_on_uninstall', 'no' );

if ( 'yes' !== $delete_data ) {
    return;
}

// -------------------------------------------------------------------------
// 1. Remove plugin settings (individual WooCommerce Settings API options).
// -------------------------------------------------------------------------
$setting_keys = array(
    'aop_bb_redirect_after_add',
    'aop_bb_hide_child_items_cart',
    'aop_bb_show_child_price_label',
    'aop_bb_cart_child_label',
    'aop_bb_primary_color',
    'aop_bb_grid_columns',
    'aop_bb_mobile_columns',
    'aop_bb_card_style',
    'aop_bb_progress_style',
    'aop_bb_show_product_descriptions',
    'aop_bb_show_product_prices',
    'aop_bb_show_stock_badges',
    'aop_bb_show_step_counter',
    'aop_bb_show_savings_badge',
    'aop_bb_lazy_load_images',
    'aop_bb_show_bundle_in_emails',
    'aop_bb_hide_child_items_emails',
    'aop_bb_delete_data_on_uninstall',
);

foreach ( $setting_keys as $key ) {
    delete_option( $key );
}

// Also remove legacy single-array option if present from older version.
delete_option( 'aop_bb_settings' );

// -------------------------------------------------------------------------
// 2. Remove bundle product meta (from all posts).
// -------------------------------------------------------------------------
global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
     WHERE meta_key LIKE '_aop_bb_%'"
);
// phpcs:enable

// -------------------------------------------------------------------------
// 3. Remove any transients.
// -------------------------------------------------------------------------
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_aop_bb_%'
        OR option_name LIKE '_transient_timeout_aop_bb_%'"
);

// -------------------------------------------------------------------------
// 4. Clear object cache.
// -------------------------------------------------------------------------
wp_cache_flush();
