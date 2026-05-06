<?php
/**
 * WooCommerce Product: Bundle Builder
 *
 * Extends WC_Product to define the `bundle_builder` product type.
 * Stores all bundle configuration as structured product meta:
 * - Pricing logic (fixed / sum / tiered)
 * - Step definitions (categories, min/max counts)
 * - Tiered discount rules
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Product_Bundle_Builder
 */
class WC_Product_Bundle_Builder extends \WC_Product {

    /**
     * Return the product type slug.
     *
     * @return string
     */
    public function get_type() {

        return 'bundle_builder';
    }

    /* ------------------------------------------------------------------
     |  Pricing Logic
     | ------------------------------------------------------------------*/

    /**
     * Get the bundle pricing mode.
     *
     * @param string $context View or edit context.
     * @return string One of: fixed, sum, tiered.
     */
    public function get_pricing_mode( string $context = 'view' ): string {

        $mode = $this->get_meta( '_aop_bb_pricing_mode', true );

        return in_array( $mode, array( 'fixed', 'sum', 'tiered' ), true ) ? $mode : 'sum';
    }

    /**
     * Get the fixed bundle price (used when pricing mode is "fixed").
     *
     * @param string $context View or edit context.
     * @return string
     */
    public function get_fixed_price( string $context = 'view' ): string {

        return (string) $this->get_meta( '_aop_bb_fixed_price', true );
    }

    /**
     * Get tiered discount rules.
     *
     * Expected structure:
     * [
     *     [ 'min_qty' => 3, 'discount' => 5 ],
     *     [ 'min_qty' => 5, 'discount' => 10 ],
     * ]
     *
     * @param string $context View or edit context.
     * @return array
     */
    public function get_tiered_discounts( string $context = 'view' ): array {

        $tiers = $this->get_meta( '_aop_bb_tiered_discounts', true );

        return is_array( $tiers ) ? $tiers : array();
    }

    /* ------------------------------------------------------------------
     |  Step Configuration
     | ------------------------------------------------------------------*/

    /**
     * Get the builder step definitions.
     *
     * Expected structure:
     * [
     *     [
     *         'title'        => 'Choose a Base',
     *         'category_ids' => [ 15, 22 ],
     *         'product_ids'  => [],
     *         'min_qty'      => 1,
     *         'max_qty'      => 1,
     *     ],
     *     ...
     * ]
     *
     * @param string $context View or edit context.
     * @return array
     */
    public function get_bundle_steps( string $context = 'view' ): array {

        $steps = $this->get_meta( '_aop_bb_steps', true );

        return is_array( $steps ) ? $steps : array();
    }

    /**
     * Get the total minimum items required across all steps.
     *
     * @return int
     */
    public function get_total_min_items(): int {

        $total = 0;

        foreach ( $this->get_bundle_steps() as $step ) {
            $total += absint( $step['min_qty'] ?? 0 );
        }

        return $total;
    }

    /**
     * Get the total maximum items allowed across all steps.
     *
     * @return int
     */
    public function get_total_max_items(): int {

        $total = 0;

        foreach ( $this->get_bundle_steps() as $step ) {
            $total += absint( $step['max_qty'] ?? 0 );
        }

        return $total;
    }

    /* ------------------------------------------------------------------
     |  WooCommerce Overrides
     | ------------------------------------------------------------------*/

    /**
     * Bundle builders are always virtual (no individual shipping per child).
     * Shipping is handled at the bundle level.
     *
     * @return bool
     */
    public function is_virtual() {

        return (bool) $this->get_meta( '_virtual', true );
    }

    /**
     * Bundles are purchasable when they have at least one step defined.
     *
     * @return bool
     */
    public function is_purchasable() {

        return parent::is_purchasable() && ! empty( $this->get_bundle_steps() );
    }

    /**
     * Override get_price to return the fixed price when in fixed mode.
     *
     * For "sum" and "tiered" modes, the price is calculated dynamically
     * on the frontend, but we return 0 as a base so WooCommerce does not
     * block the add-to-cart flow.
     *
     * @param string $context View or edit context.
     * @return string
     */
    public function get_price( $context = 'view' ) {

        $mode = $this->get_pricing_mode();

        if ( 'fixed' === $mode ) {
            $price = $this->get_fixed_price();
            return '' !== $price ? $price : parent::get_price( $context );
        }

        // For sum/tiered, the real price is computed when adding to cart.
        // Return the WC regular price if set (for display), otherwise 0.
        $base = parent::get_price( $context );

        return '' !== $base ? $base : '0';
    }

    /**
     * Ensure add-to-cart always redirects to the product page
     * so the builder UI is shown.
     *
     * @return string
     */
    public function add_to_cart_url() {

        return get_permalink( $this->get_id() );
    }

    /**
     * Use a descriptive add-to-cart button text on archive pages.
     *
     * @return string
     */
    public function add_to_cart_text() {

        return __( 'Build Your Bundle', 'bundlepilot' );
    }

    /**
     * Ensure single add-to-cart button text is also descriptive.
     *
     * @return string
     */
    public function single_add_to_cart_text() {

        return __( 'Add Bundle to Cart', 'bundlepilot' );
    }
}
