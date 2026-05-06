/**
 * Bundle Builder — WooCommerce Blocks Cart/Checkout Frontend
 *
 * Uses the WooCommerce Blocks Checkout Filters API to modify
 * how bundle items appear in the block-based cart and checkout.
 *
 * Filters used:
 * - itemName:              Adds "Bundle" badge to parent, "↳" to children.
 * - subtotalPriceFormat:   Shows "Included" for child items.
 * - cartItemPrice:         Shows "Included" for child items.
 * - cartItemClass:         Adds CSS classes for styling.
 * - showRemoveItemLink:    Hides remove link for child items.
 *
 * @see https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce-blocks/docs/third-party-developers/extensibility/checkout-flow/available-filters.md
 *
 * @package AOP_BundleBuilder
 */

( function () {
    'use strict';

    // Bail if the Checkout Filters API is not available.
    var filters = window.wc?.blocksCheckout;
    if ( ! filters || typeof filters.registerCheckoutFilters !== 'function' ) {
        return;
    }

    var registerCheckoutFilters = filters.registerCheckoutFilters;

    /**
     * Helper: Get bundle extension data from a cart item.
     *
     * @param {Object} cartItem The cart item object.
     * @return {Object|null}
     */
    function getBundleData( cartItem ) {
        return cartItem?.extensions?.[ 'aop-bundle-builder' ] || null;
    }

    /**
     * Filter: itemName
     *
     * - Parent items: append " [Bundle]" badge.
     * - Child items: prepend "↳ " indent.
     */
    registerCheckoutFilters( 'aop-bundle-builder', {
        itemName: function ( value, extensions, args ) {
            var data = getBundleData( args?.cartItem );
            if ( ! data ) {
                return value;
            }

            if ( data.is_bundle ) {
                var count = data.child_count || 0;
                return value + ' <span class="aop-bb-block-badge">Bundle' +
                    ( count > 0 ? ' (' + count + ')' : '' ) +
                    '</span>';
            }

            if ( data.is_child ) {
                return '<span class="aop-bb-block-child-indent">↳</span> ' + value;
            }

            return value;
        },
    } );

    /**
     * Filter: subtotalPriceFormat
     *
     * Child items show "Included" instead of a price subtotal.
     */
    registerCheckoutFilters( 'aop-bundle-builder', {
        subtotalPriceFormat: function ( value, extensions, args ) {
            var data = getBundleData( args?.cartItem );
            if ( data && data.is_child ) {
                return '<price/> <span class="aop-bb-block-included">Included</span>';
            }
            return value;
        },
    } );

    /**
     * Filter: cartItemPrice
     *
     * Child items show "Included" instead of individual unit price.
     */
    registerCheckoutFilters( 'aop-bundle-builder', {
        cartItemPrice: function ( value, extensions, args ) {
            var data = getBundleData( args?.cartItem );
            if ( data && data.is_child ) {
                return '<span class="aop-bb-block-included">Included</span>';
            }
            return value;
        },
    } );

    /**
     * Filter: cartItemClass
     *
     * Add CSS classes for parent and child bundle items.
     */
    registerCheckoutFilters( 'aop-bundle-builder', {
        cartItemClass: function ( value, extensions, args ) {
            var data = getBundleData( args?.cartItem );
            if ( ! data ) {
                return value;
            }

            if ( data.is_bundle ) {
                return value + ' aop-bb-block-parent';
            }

            if ( data.is_child ) {
                return value + ' aop-bb-block-child';
            }

            return value;
        },
    } );

    /**
     * Filter: showRemoveItemLink
     *
     * Hide the remove link for child bundle items.
     * Only the parent can be removed (which triggers server-side sync).
     */
    registerCheckoutFilters( 'aop-bundle-builder', {
        showRemoveItemLink: function ( value, extensions, args ) {
            var data = getBundleData( args?.cartItem );
            if ( data && data.is_child ) {
                return false;
            }
            return value;
        },
    } );

} )();
