<?php
/**
 * Admin Settings Page — WooCommerce Settings Tab
 *
 * This file contains two classes:
 *
 * 1. AOP_BB_Settings_Page — A lightweight class with static helper
 *    methods for reading settings from anywhere in the plugin
 *    (frontend, AJAX, admin). Does NOT extend WC_Settings_Page.
 *
 * 2. AOP_BB_WC_Settings_Tab — Extends WC_Settings_Page to register
 *    a "Bundle Builder" tab under WooCommerce > Settings. Only
 *    instantiated via the `woocommerce_get_settings_pages` filter
 *    when WC_Settings_Page is guaranteed to be available.
 *
 * Settings are stored as individual wp_options with the prefix
 * `aop_bb_` for compatibility with the WooCommerce Settings API.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Settings_Page
 *
 * Static helper class for reading plugin settings.
 * Safe to load at any point — no WooCommerce class dependencies.
 */
class AOP_BB_Settings_Page {

    /**
     * Get the default settings values.
     *
     * @return array
     */
    public static function get_defaults(): array {

        return array(
            'redirect_after_add'        => 'none',
            'hide_child_items_cart'      => 'no',
            'show_child_price_label'     => 'yes',
            'cart_child_label'           => 'included',
            'primary_color'             => '#FF4D00',
            'grid_columns'              => '3',
            'mobile_columns'            => '2',
            'card_style'                => 'bordered',
            'progress_style'            => 'pills',
            'show_product_descriptions'  => 'no',
            'show_product_prices'       => 'yes',
            'show_stock_badges'         => 'yes',
            'show_step_counter'         => 'yes',
            'show_savings_badge'         => 'yes',
            'lazy_load_images'           => 'yes',
            'show_bundle_in_emails'      => 'yes',
            'hide_child_items_emails'    => 'no',
            'delete_data_on_uninstall'   => 'no',
        );
    }

    /**
     * Retrieve all settings merged with defaults.
     *
     * Reads individual wp_options with the `aop_bb_` prefix.
     *
     * @return array
     */
    public static function get_settings(): array {

        $defaults = self::get_defaults();
        $settings = array();

        foreach ( $defaults as $key => $default ) {
            $settings[ $key ] = get_option( 'aop_bb_' . $key, $default );
        }

        return $settings;
    }

    /**
     * Get a single setting value.
     *
     * @param string $key     Setting key (without prefix).
     * @param mixed  $default Fallback if not set.
     * @return mixed
     */
    public static function get_setting( string $key, $default = null ) {

        $defaults = self::get_defaults();
        $fallback = $default ?? ( $defaults[ $key ] ?? null );

        return get_option( 'aop_bb_' . $key, $fallback );
    }

    /**
     * Get all WooCommerce settings field definitions.
     *
     * Used by the WC Settings Tab class and available for
     * programmatic access to field definitions.
     *
     * @param string $section Section slug.
     * @return array WooCommerce settings fields.
     */
    public static function get_fields_for_section( string $section = '' ): array {

        if ( 'appearance' === $section ) {
            return self::get_appearance_fields();
        }

        if ( 'emails' === $section ) {
            return self::get_emails_fields();
        }

        if ( 'advanced' === $section ) {
            return self::get_advanced_fields();
        }

        return self::get_general_fields();
    }

    /* ------------------------------------------------------------------
     |  Section: General (default)
     | ------------------------------------------------------------------*/

    /**
     * @return array
     */
    private static function get_general_fields(): array {

        return array(

            array(
                'title' => __( 'Cart Behaviour', 'bundlepilot' ),
                'type'  => 'title',
                'desc'  => __( 'Control what happens when a customer adds a bundle to their cart.', 'bundlepilot' ),
                'id'    => 'aop_bb_cart_behaviour_section',
            ),

            array(
                'title'    => __( 'After adding bundle to cart', 'bundlepilot' ),
                'desc'     => __( 'What happens after a customer adds a bundle to their cart.', 'bundlepilot' ),
                'id'       => 'aop_bb_redirect_after_add',
                'type'     => 'select',
                'default'  => 'none',
                'options'  => array(
                    'none'     => __( 'Show success message on product page', 'bundlepilot' ),
                    'cart'     => __( 'Redirect to cart page', 'bundlepilot' ),
                    'checkout' => __( 'Redirect to checkout page', 'bundlepilot' ),
                ),
                'desc_tip' => true,
            ),

            array( 'type' => 'sectionend', 'id' => 'aop_bb_cart_behaviour_section' ),

            array(
                'title' => __( 'Cart Display', 'bundlepilot' ),
                'type'  => 'title',
                'desc'  => __( 'Customize how bundles appear in the cart and checkout.', 'bundlepilot' ),
                'id'    => 'aop_bb_cart_display_section',
            ),

            array(
                'title'   => __( 'Hide bundled items in cart', 'bundlepilot' ),
                'desc'    => __( 'When enabled, only the parent bundle row is visible in the cart. Bundled items are hidden but still processed for stock.', 'bundlepilot' ),
                'id'      => 'aop_bb_hide_child_items_cart',
                'type'    => 'checkbox',
                'default' => 'no',
            ),

            array(
                'title'   => __( 'Show "Included" price label', 'bundlepilot' ),
                'desc'    => __( 'Show an "Included" label instead of $0.00 for bundled child items in the cart.', 'bundlepilot' ),
                'id'      => 'aop_bb_show_child_price_label',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array(
                'title'    => __( 'Bundled item price label', 'bundlepilot' ),
                'desc'     => __( 'Text shown in place of the price for bundled items. Only used when the "Included" label is enabled above.', 'bundlepilot' ),
                'id'       => 'aop_bb_cart_child_label',
                'type'     => 'select',
                'default'  => 'included',
                'options'  => array(
                    'included'       => __( 'Included', 'bundlepilot' ),
                    'bundled'        => __( 'Bundled', 'bundlepilot' ),
                    'part_of_bundle' => __( 'Part of bundle', 'bundlepilot' ),
                ),
                'desc_tip' => true,
            ),

            array( 'type' => 'sectionend', 'id' => 'aop_bb_cart_display_section' ),
        );
    }

    /* ------------------------------------------------------------------
     |  Section: Appearance
     | ------------------------------------------------------------------*/

    /**
     * @return array
     */
    private static function get_appearance_fields(): array {

        $fields = array(

            array(
                'title' => __( 'Builder Appearance', 'bundlepilot' ),
                'type'  => 'title',
                'desc'  => __( 'Adjust the look and feel of the step-by-step bundle builder on product pages.', 'bundlepilot' ),
                'id'    => 'aop_bb_appearance_section',
            ),

            array(
                'title'    => __( 'Accent color', 'bundlepilot' ),
                'desc'     => __( 'Main color used for buttons, progress bar, selected state borders, and checkmarks.', 'bundlepilot' ),
                'id'       => 'aop_bb_primary_color',
                'type'     => 'color',
                'default'  => '#FF4D00',
                'css'      => 'width:6em;',
                'desc_tip' => true,
            ),

            array(
                'title'    => __( 'Product grid columns', 'bundlepilot' ),
                'desc'     => __( 'Number of product columns in each bundle step on desktop.', 'bundlepilot' ),
                'id'       => 'aop_bb_grid_columns',
                'type'     => 'select',
                'default'  => '3',
                'options'  => array(
                    '2' => __( '2 columns', 'bundlepilot' ),
                    '3' => __( '3 columns (default)', 'bundlepilot' ),
                    '4' => __( '4 columns', 'bundlepilot' ),
                ),
                'desc_tip' => true,
            ),

            array(
                'title'    => __( 'Mobile grid columns', 'bundlepilot' ),
                'desc'     => __( 'Number of product columns on small screens (below 480px).', 'bundlepilot' ),
                'id'       => 'aop_bb_mobile_columns',
                'type'     => 'select',
                'default'  => '2',
                'options'  => array(
                    '1' => __( '1 column', 'bundlepilot' ),
                    '2' => __( '2 columns (default)', 'bundlepilot' ),
                ),
                'desc_tip' => true,
            ),

            array(
                // Free plan: only "bordered" card style. Pro unlocks shadow + minimal.
                // Rendered with the gated_select field type so locked options
                // appear in the dropdown but are disabled with a (PRO) suffix.
                'title'         => __( 'Card style', 'bundlepilot' ),
                'desc'          => __( 'Visual style of the product cards in the builder.', 'bundlepilot' ),
                'id'            => 'aop_bb_card_style',
                'type'          => 'aop_bb_gated_select',
                'default'       => 'bordered',
                'options'       => array(
                    'bordered' => __( 'Bordered (default)', 'bundlepilot' ),
                    'shadow'   => __( 'Shadow', 'bundlepilot' ),
                    'minimal'  => __( 'Minimal', 'bundlepilot' ),
                ),
                'locked'        => AOP_BB_License_Manager::can_use( 'card_style_shadow' ) ? array() : array( 'shadow', 'minimal' ),
                'required_plan' => 'pro',
            ),

            array(
                'title'    => __( 'Progress indicator style', 'bundlepilot' ),
                'desc'     => __( 'Style of the step progress indicator at the top of the builder.', 'bundlepilot' ),
                'id'       => 'aop_bb_progress_style',
                'type'     => 'select',
                'default'  => 'pills',
                'options'  => array(
                    'pills'    => __( 'Step pills (default)', 'bundlepilot' ),
                    'numbered' => __( 'Numbered circles', 'bundlepilot' ),
                    'bar'      => __( 'Progress bar', 'bundlepilot' ),
                ),
                'desc_tip' => true,
            ),

            array(
                'title'   => __( 'Show product descriptions', 'bundlepilot' ),
                'desc'    => __( 'Display the short description below each product name in the builder steps.', 'bundlepilot' ),
                'id'      => 'aop_bb_show_product_descriptions',
                'type'    => 'checkbox',
                'default' => 'no',
            ),

            array(
                'title'   => __( 'Show individual product prices', 'bundlepilot' ),
                'desc'    => __( 'Display the price below each product in the builder. Disable to hide individual prices (useful for fixed-price bundles).', 'bundlepilot' ),
                'id'      => 'aop_bb_show_product_prices',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array(
                'title'   => __( 'Show out-of-stock badges', 'bundlepilot' ),
                'desc'    => __( 'Show an "Out of stock" overlay badge on unavailable products.', 'bundlepilot' ),
                'id'      => 'aop_bb_show_stock_badges',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array(
                'title'   => __( 'Show step item counter', 'bundlepilot' ),
                'desc'    => __( 'Display the "X / Y items selected" counter in each step header.', 'bundlepilot' ),
                'id'      => 'aop_bb_show_step_counter',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array(
                'title'   => __( 'Show savings badge', 'bundlepilot' ),
                'desc'    => __( 'Display a "You save X" badge in the price summary when a discount applies.', 'bundlepilot' ),
                'id'      => 'aop_bb_show_savings_badge',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array(
                'title'   => __( 'Lazy-load product images', 'bundlepilot' ),
                'desc'    => __( 'Load product images only when the step is visible. Recommended for bundles with many products.', 'bundlepilot' ),
                'id'      => 'aop_bb_lazy_load_images',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array( 'type' => 'sectionend', 'id' => 'aop_bb_appearance_section' ),
        );

        /**
         * Filter the Appearance section's settings fields.
         *
         * Used by Business features (e.g., White Label) to inject
         * additional appearance settings into this section.
         *
         * @since 1.0.0
         *
         * @param array $fields The current array of WC settings field definitions.
         */
        return (array) apply_filters( 'aop_bb_appearance_settings_fields', $fields );
    }

    /* ------------------------------------------------------------------
     |  Section: Orders & Emails
     | ------------------------------------------------------------------*/

    /**
     * @return array
     */
    private static function get_emails_fields(): array {

        return array(

            array(
                'title' => __( 'Orders & Emails', 'bundlepilot' ),
                'type'  => 'title',
                'desc'  => __( 'Control how bundles are shown in order details and customer emails.', 'bundlepilot' ),
                'id'    => 'aop_bb_emails_section',
            ),

            array(
                'title'   => __( 'Show bundled items in order emails', 'bundlepilot' ),
                'desc'    => __( 'Include the list of bundled items below the parent item in customer order confirmation emails.', 'bundlepilot' ),
                'id'      => 'aop_bb_show_bundle_in_emails',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),

            array(
                'title'   => __( 'Hide child line items in emails', 'bundlepilot' ),
                'desc'    => __( 'Hide individual bundled item rows in order emails. The parent bundle row with its summary will still be shown.', 'bundlepilot' ),
                'id'      => 'aop_bb_hide_child_items_emails',
                'type'    => 'checkbox',
                'default' => 'no',
            ),

            array( 'type' => 'sectionend', 'id' => 'aop_bb_emails_section' ),
        );
    }

    /* ------------------------------------------------------------------
     |  Section: Advanced
     | ------------------------------------------------------------------*/

    /**
     * @return array
     */
    private static function get_advanced_fields(): array {

        return array(

            array(
                'title' => __( 'Data Management', 'bundlepilot' ),
                'type'  => 'title',
                'desc'  => __( 'Control what happens to plugin data when the plugin is uninstalled.', 'bundlepilot' ),
                'id'    => 'aop_bb_advanced_section',
            ),

            array(
                'title'   => __( 'Remove data on uninstall', 'bundlepilot' ),
                'desc'    => __( 'Delete all BundlePilot settings and product meta when the plugin is uninstalled. Bundle product types will revert to simple products.', 'bundlepilot' ),
                'id'      => 'aop_bb_delete_data_on_uninstall',
                'type'    => 'checkbox',
                'default' => 'no',
            ),

            array( 'type' => 'sectionend', 'id' => 'aop_bb_advanced_section' ),
        );
    }
}
