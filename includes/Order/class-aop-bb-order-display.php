<?php
/**
 * Order Display — Bundle Metadata on Order Admin Screens
 *
 * Enhances the WooCommerce order admin to show bundle context:
 *
 * 1. Groups parent + child items visually in the order items table.
 * 2. Adds "Bundle" badge to parent item names.
 * 3. Shows "Bundled in: X" for child items.
 * 4. Adds a bundle summary metabox with pricing breakdown.
 * 5. Hides internal meta keys from the raw display.
 *
 * Works with both classic orders and HPOS.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Order_Display
 */
class AOP_BB_Order_Display {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Style order items in admin.
        add_action( 'woocommerce_admin_order_item_headers', array( $this, 'order_item_headers' ) );
        add_action( 'woocommerce_admin_order_item_values', array( $this, 'order_item_values' ), 10, 3 );

        // Hide internal meta keys from raw display.
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_meta' ) );

        // Display readable meta instead of raw keys.
        add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'format_meta_key' ), 10, 3 );
        add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'format_meta_value' ), 10, 3 );

        // Add inline CSS for order admin.
        add_action( 'admin_head', array( $this, 'admin_order_css' ) );

        // Add bundle info to order item class.
        add_filter( 'woocommerce_admin_html_order_item_class', array( $this, 'admin_item_class' ), 10, 3 );

        // Display bundle summary below item name in order admin.
        add_action( 'woocommerce_after_order_itemmeta', array( $this, 'display_bundle_summary' ), 10, 3 );

        // Email integration: optionally hide child line items and show bundle summary.
        add_filter( 'woocommerce_order_item_visible', array( $this, 'email_hide_child_items' ), 10, 2 );
        add_action( 'woocommerce_order_item_meta_end', array( $this, 'email_bundle_summary' ), 10, 4 );
    }

    /**
     * Hide child bundle line items in customer-facing order emails.
     *
     * @param bool           $visible Whether the item is visible.
     * @param \WC_Order_Item $item    The order item.
     * @return bool
     */
    public function email_hide_child_items( $visible, $item ) {

        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return $visible;
        }

        if ( $item->get_meta( '_aop_bb_is_child' ) === 'yes' ) {
            if ( 'yes' === AOP_BB_Settings_Page::get_setting( 'hide_child_items_emails', 'no' ) ) {
                return false;
            }
        }

        return $visible;
    }

    /**
     * Show a bundled items summary below the parent item in emails.
     *
     * @param int       $item_id The item ID.
     * @param \WC_Order_Item $item The order item.
     * @param \WC_Order $order   The order.
     * @param bool      $plain_text Whether plain text email.
     * @return void
     */
    public function email_bundle_summary( $item_id, $item, $order, $plain_text = false ) {

        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return;
        }

        if ( $item->get_meta( '_aop_bb_is_bundle' ) !== 'yes' ) {
            return;
        }

        if ( 'yes' !== AOP_BB_Settings_Page::get_setting( 'show_bundle_in_emails', 'yes' ) ) {
            return;
        }

        $bundled_items = $item->get_meta( '_aop_bb_bundled_items' );

        if ( ! is_array( $bundled_items ) || empty( $bundled_items ) ) {
            return;
        }

        if ( $plain_text ) {
            echo "\n" . esc_html__( 'Bundled items:', 'bundlepilot' ) . "\n";
            foreach ( $bundled_items as $child ) {
                echo '  - ' . esc_html( $child['name'] ?? '' ) . ' x ' . esc_html( absint( $child['quantity'] ?? 0 ) ) . "\n";
            }
        } else {
            echo '<div style="font-size:12px;color:#636363;margin-top:6px;">';
            echo '<strong>' . esc_html__( 'Bundled items:', 'bundlepilot' ) . '</strong>';
            echo '<ul style="margin:4px 0 0 16px;padding:0;list-style:disc;">';
            foreach ( $bundled_items as $child ) {
                echo '<li style="margin:2px 0;">' . esc_html( $child['name'] ?? '' ) . ' &times; ' . esc_html( absint( $child['quantity'] ?? 0 ) ) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    /**
     * Add CSS class to order item rows in admin.
     *
     * @param string                $class The existing class.
     * @param \WC_Order_Item        $item  The order item.
     * @param \WC_Order             $order The order.
     * @return string
     */
    public function admin_item_class( $class, $item, $order ) {

        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return $class;
        }

        if ( $item->get_meta( '_aop_bb_is_bundle' ) === 'yes' ) {
            $class .= ' aop-bb-order-parent';
        }

        if ( $item->get_meta( '_aop_bb_is_child' ) === 'yes' ) {
            $class .= ' aop-bb-order-child';
        }

        return $class;
    }

    /**
     * Inject order item headers. Intentionally empty — we use CSS
     * to style existing columns rather than adding new ones.
     *
     * @param \WC_Order $order The order.
     * @return void
     */
    public function order_item_headers( $order ): void {
        // No additional columns needed.
    }

    /**
     * Inject order item values. Intentionally empty — we use CSS
     * and the after_order_itemmeta hook instead.
     *
     * @param \WC_Product              $product The product.
     * @param \WC_Order_Item_Product   $item    The order item.
     * @param int                      $item_id The item ID.
     * @return void
     */
    public function order_item_values( $product, $item, $item_id ): void {
        // Handled via display_bundle_summary and CSS classes.
    }

    /**
     * Display a visual bundle summary below the parent item name.
     *
     * @param int                    $item_id The order item ID.
     * @param \WC_Order_Item         $item    The order item.
     * @param \WC_Product|null       $product The product.
     * @return void
     */
    public function display_bundle_summary( $item_id, $item, $product ) {

        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return;
        }

        // Parent item — show bundle badge and bundled items list.
        if ( $item->get_meta( '_aop_bb_is_bundle' ) === 'yes' ) {
            $bundled_items = $item->get_meta( '_aop_bb_bundled_items' );
            $pricing_mode  = $item->get_meta( '_aop_bb_pricing_mode' );
            $discount      = (float) $item->get_meta( '_aop_bb_discount' );

            echo '<div class="aop-bb-order-bundle-info">';
            echo '<span class="aop-bb-order-badge">' . esc_html__( 'Bundle', 'bundlepilot' ) . '</span>';

            if ( $pricing_mode ) {
                echo '<span class="aop-bb-order-mode">';
                echo esc_html( ucfirst( $pricing_mode ) ) . ' ' . esc_html__( 'pricing', 'bundlepilot' );
                echo '</span>';
            }

            if ( $discount > 0 ) {
                echo '<span class="aop-bb-order-discount">';
                /* translators: %s Discount amount */
                printf( esc_html__( 'Discount: %s', 'bundlepilot' ), wp_kses_post( wc_price( $discount ) ) );
                echo '</span>';
            }

            if ( is_array( $bundled_items ) && ! empty( $bundled_items ) ) {
                echo '<div class="aop-bb-order-children-summary">';
                echo '<strong>' . esc_html__( 'Bundled items:', 'bundlepilot' ) . '</strong>';
                echo '<ul class="aop-bb-order-children-list">';
                foreach ( $bundled_items as $child ) {
                    echo '<li>' . esc_html( $child['name'] ?? '' ) . ' &times; ' . esc_html( absint( $child['quantity'] ?? 0 ) ) . '</li>';
                }
                echo '</ul>';
                echo '</div>';
            }

            echo '</div>';
        }

        // Child item — show "bundled in" indicator.
        if ( $item->get_meta( '_aop_bb_is_child' ) === 'yes' ) {
            $bundle_id = absint( $item->get_meta( '_aop_bb_bundle_id' ) );
            $bundle    = $bundle_id ? wc_get_product( $bundle_id ) : null;

            echo '<div class="aop-bb-order-child-indicator">';
            echo '<span class="aop-bb-order-child-arrow">↳</span> ';
            /* translators: %s Bundle product name */
            printf(
                esc_html__( 'Bundled in: %s', 'bundlepilot' ),
                '<strong>' . esc_html( $bundle ? $bundle->get_name() : '#' . $bundle_id ) . '</strong>'
            );
            echo '</div>';
        }
    }

    /**
     * Hide internal bundle meta keys from the raw order item meta display.
     *
     * @param array $hidden Existing hidden keys.
     * @return array Modified hidden keys.
     */
    public function hide_internal_meta( array $hidden ): array {

        $bundle_keys = array(
            '_aop_bb_is_bundle',
            '_aop_bb_is_child',
            '_aop_bb_group_key',
            '_aop_bb_bundle_id',
            '_aop_bb_pricing_mode',
            '_aop_bb_discount',
            '_aop_bb_bundled_items',
        );

        return array_merge( $hidden, $bundle_keys );
    }

    /**
     * Format meta key for any bundle keys that are still visible.
     *
     * @param string         $display_key The display key.
     * @param \WC_Meta_Data  $meta        The meta data object.
     * @param \WC_Order_Item $item        The order item.
     * @return string
     */
    public function format_meta_key( $display_key, $meta, $item ) {

        $key_map = array(
            '_aop_bb_is_bundle'    => __( 'Bundle Parent', 'bundlepilot' ),
            '_aop_bb_is_child'     => __( 'Bundled Item', 'bundlepilot' ),
            '_aop_bb_group_key'    => __( 'Bundle Group', 'bundlepilot' ),
            '_aop_bb_pricing_mode' => __( 'Pricing Mode', 'bundlepilot' ),
            '_aop_bb_discount'     => __( 'Bundle Discount', 'bundlepilot' ),
        );

        $raw_key = $meta->key ?? '';

        return $key_map[ $raw_key ] ?? $display_key;
    }

    /**
     * Format meta value for bundle meta.
     *
     * @param string         $display_value The display value.
     * @param \WC_Meta_Data  $meta          The meta data object.
     * @param \WC_Order_Item $item          The order item.
     * @return string
     */
    public function format_meta_value( $display_value, $meta, $item ) {

        $raw_key = $meta->key ?? '';

        if ( '_aop_bb_discount' === $raw_key ) {
            $amount = (float) ( $meta->value ?? 0 );
            if ( $amount > 0 ) {
                return wp_strip_all_tags( wc_price( $amount ) );
            }
        }

        if ( '_aop_bb_pricing_mode' === $raw_key ) {
            return ucfirst( $meta->value ?? '' );
        }

        return $display_value;
    }

    /**
     * Inject inline CSS for the order admin page.
     *
     * @return void
     */
    public function admin_order_css(): void {

        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        // Match both classic orders (shop_order) and HPOS (woocommerce_page_wc-orders).
        $order_screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

        if ( ! in_array( $screen->id, $order_screens, true ) ) {
            return;
        }

        ?>
        <style>
            /* Parent bundle row */
            .aop-bb-order-parent {
                border-bottom: none !important;
            }

            /* Child bundle row */
            .aop-bb-order-child {
                background: #fafafa !important;
            }

            .aop-bb-order-child .wc-order-item-name {
                padding-left: 24px !important;
            }

            /* Bundle badge */
            .aop-bb-order-badge {
                display: inline-block;
                background: #FF4D00;
                color: #fff;
                font-size: 10px;
                font-weight: 600;
                padding: 2px 8px;
                border-radius: 10px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                vertical-align: middle;
            }

            .aop-bb-order-bundle-info {
                margin-top: 8px;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
            }

            .aop-bb-order-mode {
                color: #6b7280;
                font-size: 12px;
            }

            .aop-bb-order-discount {
                color: #16a34a;
                font-size: 12px;
                font-weight: 600;
            }

            .aop-bb-order-children-summary {
                width: 100%;
                margin-top: 6px;
                font-size: 12px;
                color: #6b7280;
            }

            .aop-bb-order-children-list {
                margin: 4px 0 0 16px;
                padding: 0;
                list-style: disc;
            }

            .aop-bb-order-children-list li {
                margin: 2px 0;
            }

            .aop-bb-order-child-indicator {
                margin-top: 4px;
                font-size: 12px;
                color: #6b7280;
            }

            .aop-bb-order-child-arrow {
                color: #9ca3af;
            }
        </style>
        <?php
    }
}
