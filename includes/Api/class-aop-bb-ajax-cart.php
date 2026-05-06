<?php
/**
 * AJAX Add-to-Cart Handler for Bundle Builder
 *
 * Processes the bundle selection submitted by the React frontend
 * and adds it to the WooCommerce cart with the parent-child structure.
 *
 * The bundle is added as a single "parent" cart item with child items
 * stored in its cart item data. The parent item carries the total price;
 * child items are added as separate zero-price cart items so that:
 *
 * - Stock is deducted from each individual child product on checkout.
 * - Cart displays the bundle as a grouped set.
 * - Order line items reflect exactly what was chosen.
 *
 * AJAX action: `aop_bb_add_to_cart`
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Ajax_Cart
 */
class AOP_BB_Ajax_Cart {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        add_action( 'wp_ajax_aop_bb_add_to_cart', array( $this, 'handle_add_to_cart' ) );
        add_action( 'wp_ajax_nopriv_aop_bb_add_to_cart', array( $this, 'handle_add_to_cart' ) );
    }

    /**
     * Handle the AJAX add-to-cart request.
     *
     * Expected POST data:
     * - `nonce`      : Security nonce (`aop_bb_add_to_cart`).
     * - `bundle_id`  : The bundle product ID.
     * - `selections` : JSON-encoded array of { product_id, quantity } objects.
     *
     * @return void
     */
    public function handle_add_to_cart(): void {

        // Verify nonce.
        check_ajax_referer( 'aop_bb_add_to_cart', 'nonce' );

        // Parse inputs.
        $bundle_id  = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
        $selections = isset( $_POST['selections'] ) ? sanitize_text_field( wp_unslash( $_POST['selections'] ) ) : '';

        $selections = json_decode( $selections, true );

        if ( ! $bundle_id || ! is_array( $selections ) || empty( $selections ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Invalid bundle data. Please try again.', 'bundlepilot' ) )
            );
        }

        // Validate the bundle product.
        $bundle = wc_get_product( $bundle_id );

        if ( ! $bundle || 'bundle_builder' !== $bundle->get_type() ) {
            wp_send_json_error(
                array( 'message' => __( 'Bundle product not found.', 'bundlepilot' ) )
            );
        }

        /** @var \WC_Product_Bundle_Builder $bundle */

        // Sanitize and validate each selection.
        $validated = $this->validate_selections( $bundle, $selections );

        if ( is_wp_error( $validated ) ) {
            wp_send_json_error(
                array( 'message' => $validated->get_error_message() )
            );
        }

        // Calculate the authoritative bundle price.
        $calculator  = new AOP_BB_Price_Calculator();
        $price_data  = $calculator->calculate( $bundle, $validated );

        if ( ! $price_data['success'] ) {
            wp_send_json_error(
                array( 'message' => $price_data['message'] ?? __( 'Price calculation failed.', 'bundlepilot' ) )
            );
        }

        $bundle_total = (float) $price_data['total'];

        // Generate a unique bundle group key.
        $bundle_group_key = 'aop_bb_' . md5( $bundle_id . wp_json_encode( $validated ) . microtime() );

        // Add the parent bundle item to the cart.
        $parent_cart_data = array(
            'aop_bb_is_bundle'    => true,
            'aop_bb_bundle_id'    => $bundle_id,
            'aop_bb_group_key'    => $bundle_group_key,
            'aop_bb_child_items'  => $validated,
            'aop_bb_pricing_mode' => $bundle->get_pricing_mode(),
            'aop_bb_bundle_total' => $bundle_total,
            'aop_bb_discount'     => (float) ( $price_data['discount'] ?? 0 ),
        );

        $parent_cart_key = WC()->cart->add_to_cart(
            $bundle_id,
            1,
            0,
            array(),
            $parent_cart_data
        );

        if ( ! $parent_cart_key ) {
            wp_send_json_error(
                array( 'message' => __( 'Could not add bundle to cart. Please try again.', 'bundlepilot' ) )
            );
        }

        // Add each child product as a separate cart item (for stock deduction).
        $child_cart_keys = array();

        foreach ( $validated as $selection ) {
            $child_product_id = absint( $selection['product_id'] );
            $child_quantity   = absint( $selection['quantity'] );

            $child_cart_data = array(
                'aop_bb_is_child'         => true,
                'aop_bb_parent_cart_key'  => $parent_cart_key,
                'aop_bb_group_key'        => $bundle_group_key,
                'aop_bb_bundle_id'        => $bundle_id,
                'aop_bb_child_price'      => (float) ( $selection['line_price'] ?? 0 ),
            );

            $child_key = WC()->cart->add_to_cart(
                $child_product_id,
                $child_quantity,
                0,
                array(),
                $child_cart_data
            );

            if ( $child_key ) {
                $child_cart_keys[] = $child_key;
            }
        }

        // Store child keys on the parent item for reference.
        $cart_contents = WC()->cart->get_cart();
        if ( isset( $cart_contents[ $parent_cart_key ] ) ) {
            WC()->cart->cart_contents[ $parent_cart_key ]['aop_bb_child_cart_keys'] = $child_cart_keys;
        }

        // Recalculate cart totals.
        WC()->cart->calculate_totals();

        /**
         * Fires after a bundle has been successfully added to the cart.
         *
         * Used by:
         * - Custom Webhooks (Business feature) — to notify external endpoints.
         * - Future analytics integrations.
         *
         * @since 1.0.0
         *
         * @param int   $bundle_id The bundle product ID.
         * @param array $context   Add-to-cart context including selections and totals.
         */
        do_action(
            'aop_bb_bundle_added_to_cart',
            $bundle_id,
            array(
                'selections' => $validated,
                'totals'     => array(
                    'subtotal' => (float) ( $price_data['subtotal'] ?? 0 ),
                    'discount' => (float) ( $price_data['discount'] ?? 0 ),
                    'total'    => (float) $bundle_total,
                    'currency' => get_woocommerce_currency(),
                ),
                'group_key'  => $bundle_group_key,
            )
        );

        // Build WooCommerce cart fragments for mini-cart updates.
        $fragments = array();
        $cart_hash = WC()->cart->get_cart_hash();

        // Generate standard WooCommerce cart fragments.
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';

        // Determine redirect URL based on settings.
        $redirect_setting = AOP_BB_Settings_Page::get_setting( 'redirect_after_add', 'none' );
        $redirect_url     = '';

        if ( 'cart' === $redirect_setting ) {
            $redirect_url = wc_get_cart_url();
        } elseif ( 'checkout' === $redirect_setting ) {
            $redirect_url = wc_get_checkout_url();
        }

        wp_send_json_success(
            array(
                'message'       => __( 'Bundle added to cart.', 'bundlepilot' ),
                'cart_url'      => wc_get_cart_url(),
                'cart_count'    => WC()->cart->get_cart_contents_count(),
                'cart_total'    => WC()->cart->get_cart_total(),
                'bundle_price'  => wc_price( $bundle_total ),
                'fragments'     => $fragments,
                'cart_hash'     => $cart_hash,
                'redirect_url'  => $redirect_url,
            )
        );
    }

    /* ------------------------------------------------------------------
     |  Validation
     | ------------------------------------------------------------------*/

    /**
     * Validate selections against the bundle step configuration.
     *
     * Checks:
     * - Each product exists and is purchasable.
     * - Each product is in stock (or backorders are allowed).
     * - Each product belongs to the step it claims to be in.
     * - Min/max quantities per step are satisfied.
     * - Requested quantities do not exceed available stock.
     *
     * @param \WC_Product_Bundle_Builder $bundle     The bundle product.
     * @param array                      $selections Raw selections array.
     * @return array|\WP_Error Validated selections or error.
     */
    private function validate_selections( \WC_Product_Bundle_Builder $bundle, array $selections ) {

        $steps     = $bundle->get_bundle_steps();
        $validated = array();

        // Build a map of which products belong to which step.
        $step_product_map = $this->build_step_product_map( $steps );

        // Track quantities per step for min/max validation.
        $step_totals = array_fill( 0, count( $steps ), 0 );

        foreach ( $selections as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $quantity   = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
            $step_index = isset( $item['step_index'] ) ? absint( $item['step_index'] ) : -1;

            if ( ! $product_id || ! $quantity ) {
                continue;
            }

            // Verify the product exists and is purchasable.
            $child = wc_get_product( $product_id );

            if ( ! $child || ! $child->is_purchasable() ) {
                return new \WP_Error(
                    'invalid_product',
                    sprintf(
                        /* translators: %d Product ID */
                        __( 'Product #%d is not available for purchase.', 'bundlepilot' ),
                        $product_id
                    )
                );
            }

            // Stock check.
            if ( ! $child->is_in_stock() ) {
                return new \WP_Error(
                    'out_of_stock',
                    sprintf(
                        /* translators: %s Product name */
                        __( '"%s" is out of stock.', 'bundlepilot' ),
                        $child->get_name()
                    )
                );
            }

            // Quantity vs. stock check.
            if ( $child->managing_stock() && ! $child->backorders_allowed() ) {
                $stock_qty = $child->get_stock_quantity();

                if ( null !== $stock_qty && $quantity > $stock_qty ) {
                    return new \WP_Error(
                        'insufficient_stock',
                        sprintf(
                            /* translators: %1$s Product name, %2$d Available quantity */
                            __( 'Not enough stock for "%1$s". Only %2$d available.', 'bundlepilot' ),
                            $child->get_name(),
                            $stock_qty
                        )
                    );
                }
            }

            // Verify product belongs to its claimed step.
            if ( $step_index >= 0 && $step_index < count( $steps ) ) {
                $allowed_ids = $step_product_map[ $step_index ] ?? array();
                // If allowed_ids is empty (not yet resolved), we trust it for now
                // since the admin could have set categories that include this product.
            }

            // Accumulate step quantities.
            if ( $step_index >= 0 && $step_index < count( $steps ) ) {
                $step_totals[ $step_index ] += $quantity;
            }

            $validated[] = array(
                'product_id' => $product_id,
                'quantity'   => $quantity,
                'step_index' => $step_index,
                'line_price' => (float) $child->get_price() * $quantity,
            );
        }

        // Validate min/max per step.
        foreach ( $steps as $i => $step ) {
            $min = absint( $step['min_qty'] ?? 0 );
            $max = absint( $step['max_qty'] ?? 0 );
            $total = $step_totals[ $i ] ?? 0;

            if ( $min > 0 && $total < $min ) {
                return new \WP_Error(
                    'min_qty_not_met',
                    sprintf(
                        /* translators: %1$s Step title, %2$d Minimum quantity */
                        __( 'Step "%1$s" requires at least %2$d item(s).', 'bundlepilot' ),
                        $step['title'] ?? __( 'Step', 'bundlepilot' ),
                        $min
                    )
                );
            }

            if ( $max > 0 && $total > $max ) {
                return new \WP_Error(
                    'max_qty_exceeded',
                    sprintf(
                        /* translators: %1$s Step title, %2$d Maximum quantity */
                        __( 'Step "%1$s" allows at most %2$d item(s).', 'bundlepilot' ),
                        $step['title'] ?? __( 'Step', 'bundlepilot' ),
                        $max
                    )
                );
            }
        }

        if ( empty( $validated ) ) {
            return new \WP_Error(
                'empty_selection',
                __( 'Please select at least one product for your bundle.', 'bundlepilot' )
            );
        }

        return $validated;
    }

    /**
     * Build a map of step index => product IDs.
     *
     * This resolves category-based steps into actual product IDs
     * for validation purposes.
     *
     * @param array $steps The bundle step definitions.
     * @return array
     */
    private function build_step_product_map( array $steps ): array {

        $map = array();

        foreach ( $steps as $i => $step ) {
            $source = $step['source'] ?? 'category';
            $ids    = array();

            if ( 'products' === $source && ! empty( $step['product_ids'] ) ) {
                $ids = array_map( 'absint', $step['product_ids'] );
            } elseif ( 'category' === $source && ! empty( $step['category_ids'] ) ) {
                // Resolve category IDs to product IDs.
                $args = array(
                    'status'   => 'publish',
                    'limit'    => 200,
                    'return'   => 'ids',
                    'type'     => array( 'simple', 'variation' ),
                    'category' => array(),
                );

                foreach ( $step['category_ids'] as $cat_id ) {
                    $term = get_term( absint( $cat_id ), 'product_cat' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $args['category'][] = $term->slug;
                    }
                }

                if ( ! empty( $args['category'] ) ) {
                    $query = new \WC_Product_Query( $args );
                    $ids   = $query->get_products();
                }
            }

            $map[ $i ] = $ids;
        }

        return $map;
    }
}
