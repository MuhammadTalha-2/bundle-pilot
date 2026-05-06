<?php
/**
 * Cart Handler — Bundle Cart Integration
 *
 * Manages how bundle products behave in the WooCommerce cart:
 *
 * 1. **Price Override**: Sets the parent item price to the calculated
 *    bundle total and child items to $0 (to avoid double-counting).
 * 2. **Cart Display**: Nests child items visually under the parent
 *    and shows a bundle summary.
 * 3. **Removal Sync**: When the parent is removed, all child items
 *    are removed too (and vice versa).
 * 4. **Quantity Sync**: Prevents individual child quantity changes.
 * 5. **Order Items**: Adds bundle metadata to order line items.
 * 6. **Stock**: Child items handle stock deduction natively since
 *    they are real cart items with quantities.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Cart_Handler
 */
class AOP_BB_Cart_Handler {

    /**
     * Register all cart-related hooks.
     *
     * @return void
     */
    public function register(): void {

        // Set prices when cart is loaded from session.
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_prices' ), 20, 1 );

        // Cart item display — add bundle context.
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_bundle_info' ), 10, 2 );

        // Cart item name — indent child items.
        add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name' ), 10, 3 );

        // Cart item class — add CSS classes.
        add_filter( 'woocommerce_cart_item_class', array( $this, 'cart_item_class' ), 10, 3 );

        // Prevent child items from being individually removable.
        add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'maybe_hide_remove_link' ), 10, 2 );

        // Prevent child item quantity changes.
        add_filter( 'woocommerce_cart_item_quantity', array( $this, 'maybe_lock_quantity' ), 10, 3 );

        // Sync removal: remove children when parent is removed.
        add_action( 'woocommerce_remove_cart_item', array( $this, 'sync_removal' ), 10, 2 );

        // Restore children when parent is restored.
        add_action( 'woocommerce_restore_cart_item', array( $this, 'sync_restore' ), 10, 2 );

        // Persist bundle data in cart session.
        add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_session_data' ), 10, 3 );

        // Add bundle metadata to order line items.
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );

        // Cart item count — count bundles as 1 item, not N+1.
        add_filter( 'woocommerce_cart_contents_count', array( $this, 'adjust_cart_count' ) );

        // Cart item price display.
        add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price_display' ), 10, 3 );

        // Cart item subtotal display.
        add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'cart_item_subtotal_display' ), 10, 3 );

        // Conditionally hide child items based on settings.
        add_filter( 'woocommerce_cart_item_visible', array( $this, 'maybe_hide_child_item' ), 10, 3 );
    }

    /* ------------------------------------------------------------------
     |  Price Override
     | ------------------------------------------------------------------*/

    /**
     * Set cart prices before totals are calculated.
     *
     * - Parent items: set to the calculated bundle total.
     * - Child items: set to $0 (price is on the parent).
     *
     * @param \WC_Cart $cart The WooCommerce cart.
     * @return void
     */
    public function set_cart_prices( $cart ) {

        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        // Prevent running multiple times.
        if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_key => $cart_item ) {

            // Parent bundle item — set the bundle total as the price.
            if ( ! empty( $cart_item['aop_bb_is_bundle'] ) && isset( $cart_item['aop_bb_bundle_total'] ) ) {
                $cart_item['data']->set_price( (float) $cart_item['aop_bb_bundle_total'] );
                continue;
            }

            // Child bundle item — zero price (included in parent).
            if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
                $cart_item['data']->set_price( 0 );
            }
        }
    }

    /* ------------------------------------------------------------------
     |  Cart Display
     | ------------------------------------------------------------------*/

    /**
     * Add bundle info to the cart item data display.
     *
     * For parent items, shows a summary of bundled products.
     * For child items, notes that the item is part of a bundle.
     *
     * @param array $item_data Existing item data.
     * @param array $cart_item The cart item.
     * @return array Modified item data.
     */
    public function display_bundle_info( $item_data, $cart_item ) {

        if ( ! empty( $cart_item['aop_bb_is_bundle'] ) ) {
            $child_items = $cart_item['aop_bb_child_items'] ?? array();
            $count       = 0;

            foreach ( $child_items as $child ) {
                $count += absint( $child['quantity'] ?? 0 );
            }

            $item_data[] = array(
                'key'   => __( 'Bundle', 'bundlepilot' ),
                'value' => sprintf(
                    /* translators: %d Number of items in the bundle */
                    _n( '%d item', '%d items', $count, 'bundlepilot' ),
                    $count
                ),
            );

            // Show discount if applicable.
            $discount = $cart_item['aop_bb_discount'] ?? 0;
            if ( $discount > 0 ) {
                $item_data[] = array(
                    'key'   => __( 'Bundle Discount', 'bundlepilot' ),
                    'value' => wc_price( $discount ),
                );
            }
        }

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            $bundle_id = $cart_item['aop_bb_bundle_id'] ?? 0;
            $bundle    = $bundle_id ? wc_get_product( $bundle_id ) : null;

            $item_data[] = array(
                'key'   => __( 'Bundled in', 'bundlepilot' ),
                'value' => $bundle ? $bundle->get_name() : __( 'Bundle', 'bundlepilot' ),
            );
        }

        return $item_data;
    }

    /**
     * Indent child item names in the cart.
     *
     * @param string $name     The item name HTML.
     * @param array  $cart_item The cart item.
     * @param string $cart_item_key The cart item key.
     * @return string
     */
    public function cart_item_name( $name, $cart_item, $cart_item_key ) {

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            $name = '<span class="aop-bb-child-indent">&nbsp;&nbsp;↳&nbsp;</span>' . $name;
        }

        if ( ! empty( $cart_item['aop_bb_is_bundle'] ) ) {
            $name .= ' <span class="aop-bb-bundle-badge">' . esc_html__( 'Bundle', 'bundlepilot' ) . '</span>';
        }

        return $name;
    }

    /**
     * Add CSS classes to cart item rows for styling.
     *
     * @param string $class         Existing class string.
     * @param array  $cart_item     The cart item.
     * @param string $cart_item_key The cart item key.
     * @return string
     */
    public function cart_item_class( $class, $cart_item, $cart_item_key ) {

        if ( ! empty( $cart_item['aop_bb_is_bundle'] ) ) {
            $class .= ' aop-bb-cart-parent';
        }

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            $class .= ' aop-bb-cart-child';
        }

        return $class;
    }

    /**
     * Hide the remove link for child items (they follow the parent).
     *
     * @param string $link     The remove link HTML.
     * @param string $cart_item_key The cart item key.
     * @return string
     */
    public function maybe_hide_remove_link( $link, $cart_item_key ) {

        $cart = WC()->cart;
        if ( ! $cart ) {
            return $link;
        }

        $cart_item = $cart->get_cart_item( $cart_item_key );

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            return '';
        }

        return $link;
    }

    /**
     * Lock quantity input for child and parent bundle items.
     *
     * @param string $quantity      The quantity HTML input.
     * @param string $cart_item_key The cart item key.
     * @param array  $cart_item     The cart item data.
     * @return string
     */
    public function maybe_lock_quantity( $quantity, $cart_item_key, $cart_item ) {

        // Child items: show quantity as text, not editable.
        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            return sprintf(
                '<span class="aop-bb-qty-locked">%d</span>',
                absint( $cart_item['quantity'] )
            );
        }

        // Parent items: also lock to 1 (rebuild to change contents).
        if ( ! empty( $cart_item['aop_bb_is_bundle'] ) ) {
            return sprintf(
                '<span class="aop-bb-qty-locked">%d</span>',
                absint( $cart_item['quantity'] )
            );
        }

        return $quantity;
    }

    /**
     * Conditionally hide child cart item rows when the setting is enabled.
     *
     * @param bool   $visible  Whether the item should be visible.
     * @param array  $cart_item The cart item.
     * @param string $cart_item_key The cart item key.
     * @return bool
     */
    public function maybe_hide_child_item( $visible, $cart_item, $cart_item_key ) {

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            if ( 'yes' === AOP_BB_Settings_Page::get_setting( 'hide_child_items_cart', 'no' ) ) {
                return false;
            }
        }

        return $visible;
    }

    /* ------------------------------------------------------------------
     |  Cart Item Price Display
     | ------------------------------------------------------------------*/

    /**
     * Show the bundle price for parent, "Included" for children.
     *
     * @param string $price         The price HTML.
     * @param array  $cart_item     The cart item.
     * @param string $cart_item_key The cart item key.
     * @return string
     */
    public function cart_item_price_display( $price, $cart_item, $cart_item_key ) {

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            if ( 'yes' === AOP_BB_Settings_Page::get_setting( 'show_child_price_label', 'yes' ) ) {
                $label_key = AOP_BB_Settings_Page::get_setting( 'cart_child_label', 'included' );
                $labels    = array(
                    'included'       => __( 'Included', 'bundlepilot' ),
                    'bundled'        => __( 'Bundled', 'bundlepilot' ),
                    'part_of_bundle' => __( 'Part of bundle', 'bundlepilot' ),
                );
                $label_text = $labels[ $label_key ] ?? $labels['included'];
                return '<span class="aop-bb-included">' . esc_html( $label_text ) . '</span>';
            }
            return wc_price( 0 );
        }

        return $price;
    }

    /**
     * Show the bundle subtotal for parent, empty for children.
     *
     * @param string $subtotal      The subtotal HTML.
     * @param array  $cart_item     The cart item.
     * @param string $cart_item_key The cart item key.
     * @return string
     */
    public function cart_item_subtotal_display( $subtotal, $cart_item, $cart_item_key ) {

        if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
            if ( 'yes' === AOP_BB_Settings_Page::get_setting( 'show_child_price_label', 'yes' ) ) {
                $label_key = AOP_BB_Settings_Page::get_setting( 'cart_child_label', 'included' );
                $labels    = array(
                    'included'       => __( 'Included', 'bundlepilot' ),
                    'bundled'        => __( 'Bundled', 'bundlepilot' ),
                    'part_of_bundle' => __( 'Part of bundle', 'bundlepilot' ),
                );
                $label_text = $labels[ $label_key ] ?? $labels['included'];
                return '<span class="aop-bb-included">' . esc_html( $label_text ) . '</span>';
            }
            return wc_price( 0 );
        }

        return $subtotal;
    }

    /* ------------------------------------------------------------------
     |  Removal / Restore Sync
     | ------------------------------------------------------------------*/

    /**
     * Whether a sync removal is currently in progress.
     *
     * Prevents infinite recursion when removing parent triggers child
     * removal which would re-trigger parent removal, etc.
     *
     * @var bool
     */
    private static bool $syncing_removal = false;

    /**
     * When a parent bundle is removed, remove all its children.
     * When a child is removed, remove the entire bundle.
     *
     * Uses a static flag to prevent infinite recursion.
     *
     * @param string   $cart_item_key The removed cart item key.
     * @param \WC_Cart $cart          The cart instance.
     * @return void
     */
    public function sync_removal( $cart_item_key, $cart ) {

        // Guard against re-entrancy to prevent infinite loops.
        if ( self::$syncing_removal ) {
            return;
        }

        $removed_item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

        if ( ! $removed_item ) {
            return;
        }

        $is_bundle = ! empty( $removed_item['aop_bb_is_bundle'] );
        $is_child  = ! empty( $removed_item['aop_bb_is_child'] );

        if ( ! $is_bundle && ! $is_child ) {
            return;
        }

        $group_key = $removed_item['aop_bb_group_key'] ?? '';

        if ( empty( $group_key ) ) {
            return;
        }

        self::$syncing_removal = true;

        try {
            // Collect all cart keys in the same group that need removal.
            $keys_to_remove = array();

            foreach ( $cart->get_cart() as $key => $item ) {
                $item_group = $item['aop_bb_group_key'] ?? '';

                if ( $item_group !== $group_key ) {
                    continue;
                }

                // If parent was removed, remove children.
                // If child was removed, remove everything in the group.
                if ( $is_bundle && ! empty( $item['aop_bb_is_child'] ) ) {
                    $keys_to_remove[] = $key;
                } elseif ( $is_child ) {
                    $keys_to_remove[] = $key;
                }
            }

            foreach ( $keys_to_remove as $key ) {
                $cart->remove_cart_item( $key );
            }
        } finally {
            self::$syncing_removal = false;
        }
    }

    /**
     * When a parent bundle is restored, restore all its children.
     *
     * @param string   $cart_item_key The restored cart item key.
     * @param \WC_Cart $cart          The cart instance.
     * @return void
     */
    public function sync_restore( $cart_item_key, $cart ) {

        $restored_item = $cart->cart_contents[ $cart_item_key ] ?? null;

        if ( ! $restored_item || empty( $restored_item['aop_bb_is_bundle'] ) ) {
            return;
        }

        $group_key = $restored_item['aop_bb_group_key'] ?? '';

        if ( empty( $group_key ) ) {
            return;
        }

        // Restore child items from removed_cart_contents.
        foreach ( $cart->removed_cart_contents as $key => $item ) {
            if (
                ! empty( $item['aop_bb_is_child'] )
                && ( $item['aop_bb_group_key'] ?? '' ) === $group_key
            ) {
                $cart->restore_cart_item( $key );
            }
        }
    }

    /* ------------------------------------------------------------------
     |  Session Persistence
     | ------------------------------------------------------------------*/

    /**
     * Restore bundle cart item data from the session.
     *
     * @param array  $cart_item     The cart item.
     * @param array  $session_data  The stored session values.
     * @param string $cart_item_key The cart item key.
     * @return array Modified cart item.
     */
    public function restore_session_data( $cart_item, $session_data, $cart_item_key ) {

        $keys_to_restore = array(
            'aop_bb_is_bundle',
            'aop_bb_bundle_id',
            'aop_bb_group_key',
            'aop_bb_child_items',
            'aop_bb_child_cart_keys',
            'aop_bb_pricing_mode',
            'aop_bb_bundle_total',
            'aop_bb_discount',
            'aop_bb_is_child',
            'aop_bb_parent_cart_key',
            'aop_bb_child_price',
        );

        foreach ( $keys_to_restore as $key ) {
            if ( isset( $session_data[ $key ] ) ) {
                $cart_item[ $key ] = $session_data[ $key ];
            }
        }

        return $cart_item;
    }

    /* ------------------------------------------------------------------
     |  Order Line Items
     | ------------------------------------------------------------------*/

    /**
     * Add bundle metadata to WooCommerce order line items.
     *
     * @param \WC_Order_Item_Product $item          The order item.
     * @param string                 $cart_item_key  The cart item key.
     * @param array                  $values         The cart item data.
     * @param \WC_Order              $order          The order.
     * @return void
     */
    public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {

        if ( ! empty( $values['aop_bb_is_bundle'] ) ) {
            $item->add_meta_data( '_aop_bb_is_bundle', 'yes', true );
            $item->add_meta_data( '_aop_bb_group_key', $values['aop_bb_group_key'] ?? '', true );
            $item->add_meta_data( '_aop_bb_pricing_mode', $values['aop_bb_pricing_mode'] ?? 'sum', true );

            $discount = $values['aop_bb_discount'] ?? 0;
            if ( $discount > 0 ) {
                $item->add_meta_data( '_aop_bb_discount', $discount, true );
            }

            // Store child item summary for order display.
            $child_items = $values['aop_bb_child_items'] ?? array();
            if ( ! empty( $child_items ) ) {
                $summary = array();
                foreach ( $child_items as $child ) {
                    $child_product = wc_get_product( $child['product_id'] ?? 0 );
                    $summary[]     = array(
                        'product_id' => $child['product_id'] ?? 0,
                        'name'       => $child_product ? $child_product->get_name() : '#' . ( $child['product_id'] ?? 0 ),
                        'quantity'   => $child['quantity'] ?? 0,
                    );
                }
                $item->add_meta_data( '_aop_bb_bundled_items', $summary, true );
            }
        }

        if ( ! empty( $values['aop_bb_is_child'] ) ) {
            $item->add_meta_data( '_aop_bb_is_child', 'yes', true );
            $item->add_meta_data( '_aop_bb_group_key', $values['aop_bb_group_key'] ?? '', true );
            $item->add_meta_data( '_aop_bb_bundle_id', $values['aop_bb_bundle_id'] ?? 0, true );
        }
    }

    /* ------------------------------------------------------------------
     |  Cart Count Adjustment
     | ------------------------------------------------------------------*/

    /**
     * Adjust cart item count so each bundle counts as 1 item,
     * not 1 parent + N children.
     *
     * @param int $count The current cart item count.
     * @return int Adjusted count.
     */
    public function adjust_cart_count( $count ) {

        $cart = WC()->cart;

        if ( ! $cart ) {
            return $count;
        }

        $child_count = 0;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['aop_bb_is_child'] ) ) {
                $child_count += $cart_item['quantity'];
            }
        }

        return max( 0, $count - $child_count );
    }
}
