<?php
/**
 * Price Calculator — Bundle Pricing Engine
 *
 * Calculates the final bundle price based on the configured pricing mode.
 * Supports three strategies:
 *
 * - **Fixed**: A flat price regardless of which items are selected.
 * - **Sum**: The total of all individual child product prices.
 * - **Tiered**: Sum of items with a percentage discount applied
 *   when the total quantity meets a tier threshold.
 *
 * This class is stateless and can be instantiated anywhere.
 * It does NOT interact with wp_options or the database directly.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Price_Calculator
 */
class AOP_BB_Price_Calculator {

    /**
     * Calculate the bundle price for a given set of selections.
     *
     * @param \WC_Product_Bundle_Builder $bundle     The bundle product.
     * @param array                      $selections Array of validated selections.
     *                                               Each: { product_id, quantity, step_index?, line_price? }
     * @return array{
     *     success: bool,
     *     total: float,
     *     subtotal: float,
     *     discount: float,
     *     discount_percent: float,
     *     pricing_mode: string,
     *     total_items: int,
     *     line_items: array,
     *     formatted_total: string,
     *     formatted_subtotal: string,
     *     formatted_discount: string,
     *     message: string,
     * }
     */
    public function calculate( \WC_Product_Bundle_Builder $bundle, array $selections ): array {

        $pricing_mode = $bundle->get_pricing_mode();
        $line_items   = array();
        $subtotal     = 0.0;
        $total_items  = 0;

        // Build line items with prices.
        foreach ( $selections as $selection ) {
            $product_id = absint( $selection['product_id'] ?? 0 );
            $quantity   = absint( $selection['quantity'] ?? 0 );

            if ( ! $product_id || ! $quantity ) {
                continue;
            }

            $child = wc_get_product( $product_id );

            if ( ! $child ) {
                continue;
            }

            $unit_price = (float) $child->get_price();
            $line_total = $unit_price * $quantity;

            $line_items[] = array(
                'product_id' => $product_id,
                'name'       => $child->get_name(),
                'quantity'   => $quantity,
                'unit_price' => $unit_price,
                'line_total' => $line_total,
            );

            $subtotal    += $line_total;
            $total_items += $quantity;
        }

        if ( empty( $line_items ) ) {
            return array(
                'success'             => false,
                'total'               => 0,
                'subtotal'            => 0,
                'discount'            => 0,
                'discount_percent'    => 0,
                'pricing_mode'        => $pricing_mode,
                'total_items'         => 0,
                'line_items'          => array(),
                'formatted_total'     => wc_price( 0 ),
                'formatted_subtotal'  => wc_price( 0 ),
                'formatted_discount'  => wc_price( 0 ),
                'message'             => __( 'No items selected.', 'bundlepilot' ),
            );
        }

        // Calculate based on pricing mode.
        $total            = 0.0;
        $discount         = 0.0;
        $discount_percent = 0.0;

        switch ( $pricing_mode ) {

            case 'fixed':
                $fixed = (float) $bundle->get_fixed_price();
                $total = $fixed > 0 ? $fixed : $subtotal;
                $discount = max( 0, $subtotal - $total );
                $discount_percent = $subtotal > 0 ? round( ( $discount / $subtotal ) * 100, 2 ) : 0;
                break;

            case 'tiered':
                $tiers = $bundle->get_tiered_discounts();
                $applicable_discount = $this->resolve_tiered_discount( $tiers, $total_items );
                $discount_percent = $applicable_discount;
                $discount = round( $subtotal * ( $applicable_discount / 100 ), wc_get_price_decimals() );
                $total = $subtotal - $discount;
                break;

            case 'sum':
            default:
                $total = $subtotal;
                break;
        }

        // Round to WooCommerce price decimals.
        $decimals = wc_get_price_decimals();
        $total    = round( $total, $decimals );
        $subtotal = round( $subtotal, $decimals );
        $discount = round( $discount, $decimals );

        /**
         * Filter the calculated bundle price.
         *
         * @param array                      $result  The price calculation result.
         * @param \WC_Product_Bundle_Builder  $bundle  The bundle product.
         * @param array                      $selections The selections.
         */
        $result = apply_filters(
            'aop_bb_calculated_price',
            array(
                'success'             => true,
                'total'               => $total,
                'subtotal'            => $subtotal,
                'discount'            => $discount,
                'discount_percent'    => $discount_percent,
                'pricing_mode'        => $pricing_mode,
                'total_items'         => $total_items,
                'line_items'          => $line_items,
                'formatted_total'     => wc_price( $total ),
                'formatted_subtotal'  => wc_price( $subtotal ),
                'formatted_discount'  => $discount > 0 ? wc_price( $discount ) : '',
                'message'             => __( 'Price calculated successfully.', 'bundlepilot' ),
            ),
            $bundle,
            $selections
        );

        return $result;
    }

    /**
     * Resolve the applicable tiered discount percentage.
     *
     * Iterates through tiers (sorted ascending by `min_qty`)
     * and returns the highest applicable discount for the
     * given total quantity.
     *
     * @param array $tiers       Array of { min_qty, discount } tiers.
     * @param int   $total_items Total item count.
     * @return float The discount percentage (0-100).
     */
    private function resolve_tiered_discount( array $tiers, int $total_items ): float {

        $applicable = 0.0;

        // Tiers should be sorted ascending by min_qty from Phase 1 save handler,
        // but sort again for safety.
        usort( $tiers, function ( array $a, array $b ): int {
            return ( $a['min_qty'] ?? 0 ) <=> ( $b['min_qty'] ?? 0 );
        } );

        foreach ( $tiers as $tier ) {
            $min_qty  = absint( $tier['min_qty'] ?? 0 );
            $discount = (float) ( $tier['discount'] ?? 0 );

            if ( $total_items >= $min_qty && $discount > $applicable ) {
                $applicable = $discount;
            }
        }

        return min( $applicable, 100 );
    }
}
