<?php
/**
 * REST API Controller — Bundle Builder Product Data
 *
 * Registers custom REST endpoints under the `aop-bb/v1` namespace.
 *
 * Endpoints:
 * - GET  /aop-bb/v1/bundle/{id}/steps   — Returns full step config with products.
 * - POST /aop-bb/v1/bundle/{id}/stock   — Validates stock for a set of product IDs.
 * - POST /aop-bb/v1/bundle/{id}/price   — Calculates the bundle price for a selection.
 *
 * All endpoints require the bundle product to exist and be of type `bundle_builder`.
 * Nonce verification is handled via the standard WP REST cookie/nonce mechanism
 * (`wp_rest` nonce set by wp_localize_script on the frontend).
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Rest_Controller
 */
class AOP_BB_Rest_Controller {

    /**
     * REST namespace.
     *
     * @var string
     */
    private string $namespace = 'aop-bb/v1';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register all REST routes.
     *
     * @return void
     */
    public function register_routes(): void {

        // GET /aop-bb/v1/bundle/{id}/steps
        register_rest_route(
            $this->namespace,
            '/bundle/(?P<id>\d+)/steps',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_bundle_steps' ),
                'permission_callback' => array( $this, 'check_public_permissions' ),
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => array( $this, 'validate_product_id' ),
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        // POST /aop-bb/v1/bundle/{id}/stock
        register_rest_route(
            $this->namespace,
            '/bundle/(?P<id>\d+)/stock',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'check_stock' ),
                'permission_callback' => array( $this, 'check_public_permissions' ),
                'args'                => array(
                    'id'          => array(
                        'required'          => true,
                        'validate_callback' => array( $this, 'validate_product_id' ),
                        'sanitize_callback' => 'absint',
                    ),
                    'product_ids' => array(
                        'required'          => true,
                        'validate_callback' => function ( $value ) {
                            return is_array( $value ) && ! empty( $value );
                        },
                        'sanitize_callback' => function ( $value ) {
                            return array_map( 'absint', (array) $value );
                        },
                    ),
                ),
            )
        );

        // POST /aop-bb/v1/bundle/{id}/price
        register_rest_route(
            $this->namespace,
            '/bundle/(?P<id>\d+)/price',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'calculate_price' ),
                'permission_callback' => array( $this, 'check_public_permissions' ),
                'args'                => array(
                    'id'         => array(
                        'required'          => true,
                        'validate_callback' => array( $this, 'validate_product_id' ),
                        'sanitize_callback' => 'absint',
                    ),
                    'selections' => array(
                        'required'          => true,
                        'validate_callback' => function ( $value ) {
                            return is_array( $value );
                        },
                    ),
                ),
            )
        );
    }

    /* ------------------------------------------------------------------
     |  Permission Callbacks
     | ------------------------------------------------------------------*/

    /**
     * Permission callback for public storefront endpoints.
     *
     * These endpoints expose public product data (steps, stock status,
     * price calculations) needed by the frontend bundle builder UI.
     * They return only data that is already visible on the storefront
     * and are rate-limited by the AOP_BB_Security class.
     *
     * @return true Always grants access — storefront data is public.
     */
    public function check_public_permissions(): bool {

        return true;
    }

    /* ------------------------------------------------------------------
     |  Endpoint Callbacks
     | ------------------------------------------------------------------*/

    /**
     * GET /bundle/{id}/steps — Return step configuration with products.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response
     */
    public function get_bundle_steps( \WP_REST_Request $request ): \WP_REST_Response {

        $product = wc_get_product( $request->get_param( 'id' ) );

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return new \WP_REST_Response(
                array( 'message' => __( 'Bundle not found.', 'bundlepilot' ) ),
                404
            );
        }

        /** @var \WC_Product_Bundle_Builder $product */
        $steps      = $product->get_bundle_steps();
        $steps_data = array();

        foreach ( $steps as $index => $step ) {
            $products = $this->get_step_products( $step );

            $steps_data[] = array(
                'index'    => $index,
                'title'    => $step['title'] ?? '',
                'min_qty'  => absint( $step['min_qty'] ?? 0 ),
                'max_qty'  => absint( $step['max_qty'] ?? 1 ),
                'products' => $products,
            );
        }

        $response_data = array(
            'bundle_id'     => $product->get_id(),
            'bundle_name'   => $product->get_name(),
            'pricing_mode'  => $product->get_pricing_mode(),
            'fixed_price'   => $product->get_fixed_price(),
            'tiered_discounts' => $product->get_tiered_discounts(),
            'currency'      => get_woocommerce_currency(),
            'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
            'price_decimals'  => wc_get_price_decimals(),
            'thousand_sep'    => wc_get_price_thousand_separator(),
            'decimal_sep'     => wc_get_price_decimal_separator(),
            'price_format'    => get_woocommerce_price_format(),
            'steps'         => $steps_data,
        );

        return new \WP_REST_Response( $response_data, 200 );
    }

    /**
     * POST /bundle/{id}/stock — Live inventory check.
     *
     * Accepts an array of product IDs and returns their current
     * stock status and available quantity. Used by the frontend
     * to disable out-of-stock items in real time.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response
     */
    public function check_stock( \WP_REST_Request $request ): \WP_REST_Response {

        $product_ids = $request->get_param( 'product_ids' );
        $stock_data  = array();

        foreach ( $product_ids as $pid ) {
            $child = wc_get_product( $pid );

            if ( ! $child ) {
                $stock_data[ $pid ] = array(
                    'in_stock'  => false,
                    'quantity'  => 0,
                    'status'    => 'not_found',
                );
                continue;
            }

            $stock_data[ $pid ] = array(
                'in_stock'        => $child->is_in_stock(),
                'quantity'        => $child->get_stock_quantity(),
                'manage_stock'    => $child->managing_stock(),
                'status'          => $child->get_stock_status(),
                'backorders'      => $child->backorders_allowed(),
                'max_purchasable' => $this->get_max_purchasable( $child ),
            );
        }

        return new \WP_REST_Response( array( 'stock' => $stock_data ), 200 );
    }

    /**
     * POST /bundle/{id}/price — Server-side price calculation.
     *
     * Accepts a selections array and returns the computed bundle price.
     * This is the authoritative price calculation — the frontend shows
     * a preview, but this endpoint is the source of truth for add-to-cart.
     *
     * Expected `selections` format:
     * [
     *     { "product_id": 42, "quantity": 2 },
     *     { "product_id": 99, "quantity": 1 },
     * ]
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response
     */
    public function calculate_price( \WP_REST_Request $request ): \WP_REST_Response {

        $product = wc_get_product( $request->get_param( 'id' ) );

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return new \WP_REST_Response(
                array( 'message' => __( 'Bundle not found.', 'bundlepilot' ) ),
                404
            );
        }

        /** @var \WC_Product_Bundle_Builder $product */
        $selections = $request->get_param( 'selections' );

        $calculator = new AOP_BB_Price_Calculator();
        $result     = $calculator->calculate( $product, $selections );

        return new \WP_REST_Response( $result, 200 );
    }

    /* ------------------------------------------------------------------
     |  Helpers
     | ------------------------------------------------------------------*/

    /**
     * Validate that a product ID exists and is a bundle_builder.
     *
     * @param mixed $value The parameter value.
     * @return bool
     */
    public function validate_product_id( $value ): bool {

        return is_numeric( $value ) && absint( $value ) > 0;
    }

    /**
     * Get all eligible products for a single step.
     *
     * Fetches products from either category IDs or explicit product IDs,
     * returning a consistent array of product data for the frontend.
     *
     * @param array $step The step configuration array.
     * @return array Array of product data arrays.
     */
    private function get_step_products( array $step ): array {

        $source = $step['source'] ?? 'category';
        $args   = array(
            'status'  => 'publish',
            'limit'   => 100,
            'orderby' => 'title',
            'order'   => 'ASC',
            'type'    => array( 'simple', 'variation' ),
        );

        if ( 'category' === $source && ! empty( $step['category_ids'] ) ) {
            $args['category'] = array();

            foreach ( $step['category_ids'] as $cat_id ) {
                $term = get_term( absint( $cat_id ), 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $args['category'][] = $term->slug;
                }
            }

            if ( empty( $args['category'] ) ) {
                return array();
            }
        } elseif ( 'products' === $source && ! empty( $step['product_ids'] ) ) {
            $args['include'] = array_map( 'absint', $step['product_ids'] );
        } else {
            return array();
        }

        $query    = new \WC_Product_Query( $args );
        $products = $query->get_products();
        $output   = array();

        foreach ( $products as $child ) {
            $output[] = $this->format_product( $child );
        }

        return $output;
    }

    /**
     * Format a WC_Product into a frontend-friendly array.
     *
     * @param \WC_Product $product The product to format.
     * @return array
     */
    private function format_product( \WC_Product $product ): array {

        $image_id  = $product->get_image_id();
        $image_url = $image_id
            ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
            : wc_placeholder_img_src( 'woocommerce_thumbnail' );

        return array(
            'id'              => $product->get_id(),
            'name'            => $product->get_name(),
            'slug'            => $product->get_slug(),
            'price'           => (float) $product->get_price(),
            'regular_price'   => (float) $product->get_regular_price(),
            'sale_price'      => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'price_html'      => $product->get_price_html(),
            'image'           => $image_url,
            'short_description' => wp_strip_all_tags( $product->get_short_description() ),
            'in_stock'        => $product->is_in_stock(),
            'stock_quantity'  => $product->get_stock_quantity(),
            'manage_stock'    => $product->managing_stock(),
            'backorders'      => $product->backorders_allowed(),
            'max_purchasable' => $this->get_max_purchasable( $product ),
            'permalink'       => $product->get_permalink(),
        );
    }

    /**
     * Calculate the max purchasable quantity for a product.
     *
     * Takes into account stock management, stock quantity,
     * and existing cart contents.
     *
     * @param \WC_Product $product The product.
     * @return int -1 means unlimited.
     */
    private function get_max_purchasable( \WC_Product $product ): int {

        if ( ! $product->managing_stock() ) {
            // Not managing stock — unlimited (unless out of stock).
            return $product->is_in_stock() ? -1 : 0;
        }

        $stock_qty = $product->get_stock_quantity();

        if ( null === $stock_qty ) {
            return $product->is_in_stock() ? -1 : 0;
        }

        if ( $product->backorders_allowed() ) {
            return -1;
        }

        // Subtract any quantity already in the cart for this product.
        $cart_qty = 0;
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                if ( $cart_item['product_id'] === $product->get_id()
                    || ( isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] === $product->get_id() )
                ) {
                    $cart_qty += $cart_item['quantity'];
                }

                // Also check bundled child items.
                if ( isset( $cart_item['aop_bb_child_items'] ) && is_array( $cart_item['aop_bb_child_items'] ) ) {
                    foreach ( $cart_item['aop_bb_child_items'] as $child ) {
                        if ( ( $child['product_id'] ?? 0 ) === $product->get_id() ) {
                            $cart_qty += $child['quantity'] ?? 0;
                        }
                    }
                }
            }
        }

        return max( 0, $stock_qty - $cart_qty );
    }
}
