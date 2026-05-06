<?php
/**
 * WooCommerce Blocks Integration — Store API Extension
 *
 * Extends the WooCommerce Store API (used by Cart & Checkout Blocks)
 * to expose bundle metadata on cart line items. This allows the
 * block-based cart to:
 *
 * - Identify parent and child bundle items.
 * - Display bundle badges and "Included" price labels.
 * - Prevent child item quantity changes and individual removal.
 * - Show bundle discount information.
 *
 * Uses the ExtendSchema and IntegrationInterface APIs from
 * WooCommerce Blocks.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Blocks_Integration
 */
class AOP_BB_Blocks_Integration {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Extend Store API cart item data.
        add_action( 'woocommerce_blocks_loaded', array( $this, 'extend_store_api' ) );

        // Register block scripts and styles.
        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_block_integration' ) );

        // Enqueue block cart/checkout styles.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_block_styles' ) );
    }

    /**
     * Extend the Store API to include bundle metadata on cart items.
     *
     * @return void
     */
    public function extend_store_api(): void {

        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(
            array(
                'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
                'namespace'       => 'bundlepilot',
                'data_callback'   => array( $this, 'cart_item_data_callback' ),
                'schema_callback' => array( $this, 'cart_item_schema_callback' ),
                'schema_type'     => ARRAY_A,
            )
        );
    }

    /**
     * Provide bundle metadata for each cart item via the Store API.
     *
     * This data is accessible in JS via `cartItem.extensions['bundlepilot']`.
     *
     * @param \WC_Cart $cart_item WC Cart item data.
     * @return array Extension data.
     */
    public function cart_item_data_callback( $cart_item ) {

        $is_bundle = ! empty( $cart_item['aop_bb_is_bundle'] );
        $is_child  = ! empty( $cart_item['aop_bb_is_child'] );

        $data = array(
            'is_bundle'       => $is_bundle,
            'is_child'        => $is_child,
            'group_key'       => $cart_item['aop_bb_group_key'] ?? '',
            'bundle_id'       => absint( $cart_item['aop_bb_bundle_id'] ?? 0 ),
            'bundle_total'    => (float) ( $cart_item['aop_bb_bundle_total'] ?? 0 ),
            'discount'        => (float) ( $cart_item['aop_bb_discount'] ?? 0 ),
            'pricing_mode'    => $cart_item['aop_bb_pricing_mode'] ?? '',
            'child_count'     => 0,
            'parent_cart_key' => $cart_item['aop_bb_parent_cart_key'] ?? '',
        );

        // Count bundled items for the parent.
        if ( $is_bundle && ! empty( $cart_item['aop_bb_child_items'] ) ) {
            $count = 0;
            foreach ( $cart_item['aop_bb_child_items'] as $child ) {
                $count += absint( $child['quantity'] ?? 0 );
            }
            $data['child_count'] = $count;
        }

        return $data;
    }

    /**
     * Define the schema for the bundle extension data.
     *
     * @return array JSON Schema definition.
     */
    public function cart_item_schema_callback() {

        return array(
            'is_bundle' => array(
                'description' => __( 'Whether this is a bundle parent item.', 'bundlepilot' ),
                'type'        => 'boolean',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'is_child' => array(
                'description' => __( 'Whether this is a bundled child item.', 'bundlepilot' ),
                'type'        => 'boolean',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'group_key' => array(
                'description' => __( 'Bundle group identifier.', 'bundlepilot' ),
                'type'        => 'string',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'bundle_id' => array(
                'description' => __( 'Parent bundle product ID.', 'bundlepilot' ),
                'type'        => 'integer',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'bundle_total' => array(
                'description' => __( 'Calculated bundle total price.', 'bundlepilot' ),
                'type'        => 'number',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'discount' => array(
                'description' => __( 'Bundle discount amount.', 'bundlepilot' ),
                'type'        => 'number',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'pricing_mode' => array(
                'description' => __( 'Bundle pricing mode.', 'bundlepilot' ),
                'type'        => 'string',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'child_count' => array(
                'description' => __( 'Number of items in the bundle.', 'bundlepilot' ),
                'type'        => 'integer',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
            'parent_cart_key' => array(
                'description' => __( 'Parent cart item key.', 'bundlepilot' ),
                'type'        => 'string',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => true,
            ),
        );
    }

    /**
     * Register the block integration for Cart/Checkout blocks.
     *
     * @return void
     */
    public function register_block_integration(): void {

        if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
            return;
        }

        // Define the integration class inline — at this point during
        // `woocommerce_blocks_loaded` the interface is guaranteed available.
        if ( ! class_exists( 'AOP_BB_Block_Script_Integration' ) ) {
            require_once AOP_BB_PLUGIN_PATH . 'includes/Blocks/class-aop-bb-block-script-integration.php';
        }

        add_action(
            'woocommerce_blocks_cart_block_registration',
            function ( $integration_registry ) {
                $integration_registry->register( new \AOP_BB_Block_Script_Integration() );
            }
        );

        add_action(
            'woocommerce_blocks_checkout_block_registration',
            function ( $integration_registry ) {
                $integration_registry->register( new \AOP_BB_Block_Script_Integration() );
            }
        );
    }

    /**
     * Enqueue styles for block-based cart and checkout.
     *
     * @return void
     */
    public function enqueue_block_styles(): void {

        // Only enqueue on pages that might use cart/checkout blocks.
        if ( ! function_exists( 'has_block' ) ) {
            return;
        }

        // We check for the block presence in the content. This covers
        // both the cart and checkout pages when using blocks.
        if ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) || is_cart() || is_checkout() ) {
            wp_enqueue_style(
                'aop-bb-blocks',
                AOP_BB_PLUGIN_URL . 'assets/blocks-cart.css',
                array(),
                AOP_BB_VERSION
            );
        }
    }
}
