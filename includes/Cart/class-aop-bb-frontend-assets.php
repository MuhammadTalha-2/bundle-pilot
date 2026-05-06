<?php
/**
 * Frontend Assets — Conditional Enqueue
 *
 * Loads CSS and JS assets only on the pages where they are needed:
 * - Cart CSS: on the cart and checkout pages.
 * - Builder assets: on single bundle_builder product pages (Phase 3).
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Frontend_Assets
 */
class AOP_BB_Frontend_Assets {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Conditionally enqueue frontend assets.
     *
     * @return void
     */
    public function enqueue_assets(): void {

        // Cart bundle styles — load on cart and checkout pages.
        if ( is_cart() || is_checkout() ) {
            wp_enqueue_style(
                'aop-bb-cart',
                AOP_BB_PLUGIN_URL . 'assets/cart-bundle.css',
                array(),
                AOP_BB_VERSION
            );
        }

        // Builder assets — only on single bundle_builder product pages.
        if ( $this->is_bundle_product_page() ) {
            $this->enqueue_builder_assets();
        }
    }

    /**
     * Check if the current page is a single bundle_builder product page.
     *
     * @return bool
     */
    private function is_bundle_product_page(): bool {

        if ( ! is_product() ) {
            return false;
        }

        global $post;

        if ( ! $post ) {
            return false;
        }

        $product = wc_get_product( $post->ID );

        return $product && 'bundle_builder' === $product->get_type();
    }

    /**
     * Enqueue the React builder app and its dependencies.
     *
     * This prepares the script localization data that the React
     * builder will need. The actual React bundle is built in Phase 3.
     *
     * @return void
     */
    private function enqueue_builder_assets(): void {

        global $post;

        $product = wc_get_product( $post->ID );

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return;
        }

        // Register the builder script placeholder.
        // The actual React build file will be enqueued in Phase 3.
        $asset_file = AOP_BB_PLUGIN_PATH . 'frontend/build/index.asset.php';

        if ( file_exists( $asset_file ) ) {
            $asset = include $asset_file;

            wp_enqueue_script(
                'aop-bb-builder',
                AOP_BB_PLUGIN_URL . 'frontend/build/index.js',
                $asset['dependencies'] ?? array( 'wp-element', 'wp-api-fetch' ),
                $asset['version'] ?? AOP_BB_VERSION,
                true
            );

            wp_enqueue_style(
                'aop-bb-builder',
                AOP_BB_PLUGIN_URL . 'frontend/build/index.css',
                array(),
                $asset['version'] ?? AOP_BB_VERSION
            );
        }

        // Localize data for the builder.
        $bb_settings = AOP_BB_Settings_Page::get_settings();

        wp_localize_script(
            'aop-bb-builder',
            'aopBundleBuilder',
            array(
                'bundleId'      => $product->get_id(),
                'bundleName'    => $product->get_name(),
                'restUrl'       => rest_url( 'aop-bb/v1' ),
                'restNonce'     => wp_create_nonce( 'wp_rest' ),
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'cartNonce'     => wp_create_nonce( 'aop_bb_add_to_cart' ),
                'cartUrl'       => wc_get_cart_url(),
                'checkoutUrl'   => wc_get_checkout_url(),
                'currency'      => get_woocommerce_currency(),
                'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
                'priceDecimals' => wc_get_price_decimals(),
                'thousandSep'   => wc_get_price_thousand_separator(),
                'decimalSep'    => wc_get_price_decimal_separator(),
                'priceFormat'   => get_woocommerce_price_format(),
                'settings'      => array(
                    'primaryColor'         => $bb_settings['primary_color'] ?? '#FF4D00',
                    'gridColumns'          => absint( $bb_settings['grid_columns'] ?? 3 ),
                    'mobileColumns'        => absint( $bb_settings['mobile_columns'] ?? 2 ),
                    'cardStyle'            => $bb_settings['card_style'] ?? 'bordered',
                    'progressStyle'        => $bb_settings['progress_style'] ?? 'pills',
                    'showDescriptions'     => 'yes' === ( $bb_settings['show_product_descriptions'] ?? 'no' ),
                    'showProductPrices'    => 'yes' === ( $bb_settings['show_product_prices'] ?? 'yes' ),
                    'showStockBadges'      => 'yes' === ( $bb_settings['show_stock_badges'] ?? 'yes' ),
                    'showStepCounter'      => 'yes' === ( $bb_settings['show_step_counter'] ?? 'yes' ),
                    'showSavingsBadge'     => 'yes' === ( $bb_settings['show_savings_badge'] ?? 'yes' ),
                    'lazyLoadImages'       => 'yes' === ( $bb_settings['lazy_load_images'] ?? 'yes' ),
                ),
                'i18n'          => array(
                    'addToCart'       => __( 'Add Bundle to Cart', 'bundlepilot' ),
                    'adding'          => __( 'Adding...', 'bundlepilot' ),
                    'added'           => __( 'Added to Cart!', 'bundlepilot' ),
                    'viewCart'        => __( 'View Cart', 'bundlepilot' ),
                    'checkout'        => __( 'Checkout', 'bundlepilot' ),
                    'outOfStock'      => __( 'Out of stock', 'bundlepilot' ),
                    'selectItems'     => __( 'Select items', 'bundlepilot' ),
                    'minRequired'     => __( 'Minimum %d required', 'bundlepilot' ),
                    'maxAllowed'      => __( 'Maximum %d allowed', 'bundlepilot' ),
                    'bundleTotal'     => __( 'Bundle Total', 'bundlepilot' ),
                    'youSave'         => __( 'You save', 'bundlepilot' ),
                    'next'            => __( 'Next', 'bundlepilot' ),
                    'previous'        => __( 'Previous', 'bundlepilot' ),
                    'step'            => __( 'Step', 'bundlepilot' ),
                    'of'              => __( 'of', 'bundlepilot' ),
                    'selected'        => __( 'selected', 'bundlepilot' ),
                    'loading'         => __( 'Loading bundle...', 'bundlepilot' ),
                    'error'           => __( 'Something went wrong. Please try again.', 'bundlepilot' ),
                    'connectionError' => __( 'Connection error. Please check your internet.', 'bundlepilot' ),
                ),
            )
        );
    }
}
