<?php
/**
 * Security Hardening — Rate Limiting and Input Sanitization
 *
 * Adds additional security layers to the Bundle Builder:
 *
 * 1. Rate limiting for AJAX add-to-cart requests (transient-based).
 * 2. Rate limiting for REST API stock/price check endpoints.
 * 3. Additional input sanitization enforcement.
 * 4. Prevents direct file access across all plugin files.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Security
 */
class AOP_BB_Security {

    /**
     * Maximum AJAX add-to-cart attempts per minute per user.
     *
     * @var int
     */
    private int $cart_rate_limit = 10;

    /**
     * Maximum REST stock/price check requests per minute per IP.
     *
     * @var int
     */
    private int $api_rate_limit = 30;

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Rate limit AJAX add-to-cart.
        add_action( 'wp_ajax_aop_bb_add_to_cart', array( $this, 'check_cart_rate_limit' ), 5 );
        add_action( 'wp_ajax_nopriv_aop_bb_add_to_cart', array( $this, 'check_cart_rate_limit' ), 5 );

        // Rate limit REST endpoints.
        add_filter( 'rest_pre_dispatch', array( $this, 'check_rest_rate_limit' ), 10, 3 );

        // Sanitize REST request body.
        add_filter( 'rest_pre_dispatch', array( $this, 'sanitize_rest_input' ), 5, 3 );
    }

    /**
     * Check the AJAX add-to-cart rate limit.
     *
     * Uses WordPress transients keyed by the user's session or IP.
     * Fires at priority 5 (before the main handler at priority 10).
     *
     * @return void
     */
    public function check_cart_rate_limit(): void {

        $key = $this->get_rate_limit_key( 'cart' );

        $count = (int) get_transient( $key );

        if ( $count >= $this->cart_rate_limit ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Too many requests. Please wait a moment and try again.', 'bundlepilot' ),
                    'code'    => 'rate_limited',
                ),
                429
            );
        }

        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
    }

    /**
     * Check REST API rate limit for bundle endpoints.
     *
     * @param mixed            $result  Pre-dispatch result.
     * @param \WP_REST_Server  $server  REST server.
     * @param \WP_REST_Request $request REST request.
     * @return mixed
     */
    public function check_rest_rate_limit( $result, $server, $request ) {

        $route = $request->get_route();

        // Only rate limit our own endpoints.
        if ( strpos( $route, 'aop-bb/v1/bundle/' ) === false ) {
            return $result;
        }

        // Skip rate limiting for GET requests to /steps (initial load).
        if ( 'GET' === $request->get_method() && strpos( $route, '/steps' ) !== false ) {
            return $result;
        }

        $key   = $this->get_rate_limit_key( 'api' );
        $count = (int) get_transient( $key );

        if ( $count >= $this->api_rate_limit ) {
            return new \WP_Error(
                'aop_bb_rate_limited',
                __( 'Too many requests. Please wait a moment and try again.', 'bundlepilot' ),
                array( 'status' => 429 )
            );
        }

        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * Sanitize REST request body for bundle endpoints.
     *
     * Ensures that product_ids and selections arrays contain
     * only integers, preventing injection via the REST body.
     *
     * @param mixed            $result  Pre-dispatch result.
     * @param \WP_REST_Server  $server  REST server.
     * @param \WP_REST_Request $request REST request.
     * @return mixed
     */
    public function sanitize_rest_input( $result, $server, $request ) {

        $route = $request->get_route();

        if ( strpos( $route, 'aop-bb/v1/bundle/' ) === false ) {
            return $result;
        }

        // Sanitize product_ids array (stock endpoint).
        $product_ids = $request->get_param( 'product_ids' );
        if ( is_array( $product_ids ) ) {
            $request->set_param(
                'product_ids',
                array_values( array_filter( array_map( 'absint', $product_ids ) ) )
            );
        }

        // Sanitize selections array (price endpoint).
        $selections = $request->get_param( 'selections' );
        if ( is_array( $selections ) ) {
            $clean = array();
            foreach ( $selections as $sel ) {
                if ( ! is_array( $sel ) ) {
                    continue;
                }
                $clean[] = array(
                    'product_id' => absint( $sel['product_id'] ?? 0 ),
                    'quantity'   => absint( $sel['quantity'] ?? 0 ),
                );
            }
            $request->set_param( 'selections', $clean );
        }

        return $result;
    }

    /**
     * Generate a rate limit transient key.
     *
     * For logged-in users: uses the user ID.
     * For guests: uses a hash of the IP address.
     *
     * @param string $context The rate limit context ('cart' or 'api').
     * @return string Transient key.
     */
    private function get_rate_limit_key( string $context ): string {

        if ( is_user_logged_in() ) {
            $identifier = 'u' . get_current_user_id();
        } else {
            // Hash the IP to keep transient keys short and avoid storing raw IPs.
            $ip         = $this->get_client_ip();
            $identifier = 'ip' . substr( md5( $ip ), 0, 12 );
        }

        return 'aop_bb_rl_' . $context . '_' . $identifier;
    }

    /**
     * Get the client IP address.
     *
     * Checks common proxy headers before falling back to REMOTE_ADDR.
     *
     * @return string
     */
    private function get_client_ip(): string {

        $headers = array(
            'HTTP_CF_CONNECTING_IP',  // Cloudflare.
            'HTTP_X_FORWARDED_FOR',   // Proxies.
            'HTTP_X_REAL_IP',         // Nginx.
            'REMOTE_ADDR',            // Direct connection.
        );

        foreach ( $headers as $header ) {
            $value = isset( $_SERVER[ $header ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) : '';

            if ( ! empty( $value ) ) {
                // X-Forwarded-For may contain multiple IPs — take the first.
                $ips = explode( ',', $value );
                $ip  = trim( $ips[0] );

                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
