<?php
/**
 * Single Product Template — Bundle Builder Override
 *
 * Replaces the default WooCommerce add-to-cart form with a
 * React mount point (`#aop-bb-builder-root`) when viewing a
 * `bundle_builder` product page.
 *
 * This approach uses WooCommerce's action hook system rather
 * than template overrides, ensuring maximum theme compatibility.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Single_Product
 */
class AOP_BB_Single_Product {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Remove the default add-to-cart button for bundle_builder products.
        add_action( 'woocommerce_bundle_builder_add_to_cart', array( $this, 'render_builder_container' ) );

        // Also handle the standard single product summary hook as fallback.
        add_filter( 'woocommerce_product_tabs', array( $this, 'maybe_add_builder_tab' ) );

        // Replace the static `$0.00` price for bundles in dynamic pricing
        // modes (Sum / Tiered) with a helpful placeholder. The wizard
        // shows the live total as the customer makes selections, so a
        // static zero in the product summary is misleading.
        add_filter( 'woocommerce_get_price_html', array( $this, 'maybe_replace_bundle_price_html' ), 10, 2 );
    }

    /**
     * Replace the price HTML for dynamic-pricing bundles.
     *
     * Affects every place WC renders a product's price via wc_price() →
     * single product page, shop loops, related products, widgets, REST,
     * etc. — without touching the underlying stored price (so the cart
     * and checkout still get the calculated total from our cart handler).
     *
     * Behavior:
     *   - Fixed mode    → unchanged (the displayed price IS the bundle price).
     *   - Sum mode      → "Build your bundle to see the price".
     *   - Tiered mode   → "Build your bundle to see the price".
     *
     * The label is filterable via `aop_bb_dynamic_pricing_label`.
     *
     * @param string     $price_html The original price HTML.
     * @param WC_Product $product    The product being rendered.
     * @return string
     */
    public function maybe_replace_bundle_price_html( $price_html, $product ) {

        if ( ! $product instanceof WC_Product ) {
            return $price_html;
        }

        if ( 'bundle_builder' !== $product->get_type() ) {
            return $price_html;
        }

        $pricing_mode = get_post_meta( $product->get_id(), '_aop_bb_pricing_mode', true );

        // Fixed mode shows the real price — leave it alone.
        if ( ! in_array( $pricing_mode, array( 'sum', 'tiered' ), true ) ) {
            return $price_html;
        }

        // Inline styles ensure the placeholder reads as small and italic
        // regardless of the active theme's price typography (themes often
        // style `.price` as a large bold heading, which would otherwise
        // make a soft hint phrase look like a shouted title).
        $inline_style = 'font-size:14px;font-style:italic;font-weight:400;color:#64748b;line-height:1.4;';

        $label = sprintf(
            '<span class="aop-bb-dynamic-price" style="%1$s">%2$s</span>',
            esc_attr( $inline_style ),
            esc_html__( 'Build your bundle to see the price', 'bundlepilot' )
        );

        /**
         * Filter the placeholder shown in place of the dynamic-bundle price.
         *
         * @since 1.0.0
         *
         * @param string     $label   Default placeholder HTML.
         * @param WC_Product $product The bundle product.
         */
        return apply_filters( 'aop_bb_dynamic_pricing_label', $label, $product );
    }

    /**
     * Render the React builder container.
     *
     * This is called by WooCommerce's `woocommerce_{product_type}_add_to_cart`
     * action, which automatically fires for custom product types.
     *
     * @return void
     */
    public function render_builder_container(): void {

        global $product;

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return;
        }

        // Render a noscript fallback and the React mount point.
        ?>
        <div id="aop-bb-builder-root"
             class="aop-bb-builder-root"
             data-bundle-id="<?php echo esc_attr( $product->get_id() ); ?>"
             data-bundle-name="<?php echo esc_attr( $product->get_name() ); ?>">

            <noscript>
                <div class="woocommerce-info">
                    <?php esc_html_e( 'JavaScript is required to use BundlePilot. Please enable JavaScript in your browser.', 'bundlepilot' ); ?>
                </div>
            </noscript>

            <!-- React builder loads here. Skeleton shown while loading. -->
            <div class="aop-bb-loading-skeleton" aria-label="<?php esc_attr_e( 'Loading bundle builder...', 'bundlepilot' ); ?>">
                <div class="aop-bb-skeleton-bar"></div>
                <div class="aop-bb-skeleton-grid">
                    <div class="aop-bb-skeleton-card"></div>
                    <div class="aop-bb-skeleton-card"></div>
                    <div class="aop-bb-skeleton-card"></div>
                </div>
            </div>
        </div>

        <?php
        /**
         * Fires immediately after the bundle builder container is rendered.
         *
         * Used by:
         * - White-label feature (Powered by footer)
         * - Future extensions (testimonials, badges, etc.)
         *
         * @since 1.0.0
         */
        do_action( 'aop_bb_after_builder_container' );
        ?>

        <style>
            .aop-bb-loading-skeleton {
                padding: 20px 0;
            }
            .aop-bb-skeleton-bar {
                height: 12px;
                background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
                background-size: 200% 100%;
                animation: aop-bb-shimmer 1.5s ease-in-out infinite;
                border-radius: 6px;
                margin-bottom: 20px;
                max-width: 300px;
            }
            .aop-bb-skeleton-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 16px;
            }
            .aop-bb-skeleton-card {
                height: 200px;
                background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
                background-size: 200% 100%;
                animation: aop-bb-shimmer 1.5s ease-in-out infinite;
                border-radius: 12px;
            }
            @keyframes aop-bb-shimmer {
                0% { background-position: -200% 0; }
                100% { background-position: 200% 0; }
            }
            /* Hide skeleton once React mounts */
            .aop-bb-builder-root.aop-bb-loaded .aop-bb-loading-skeleton {
                display: none;
            }
        </style>
        <?php
    }

    /**
     * Optionally add a "Build Your Bundle" tab to the product tabs
     * if the theme does not support the custom add-to-cart action.
     *
     * @param array $tabs Existing product tabs.
     * @return array Modified tabs.
     */
    public function maybe_add_builder_tab( array $tabs ): array {

        global $product;

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return $tabs;
        }

        // Only add the tab if the builder root is NOT already on the page.
        // Most themes will render the add-to-cart action, which includes
        // our builder container. This tab is a safety net.
        // We set high priority so it appears first.
        $tabs['bundle_builder'] = array(
            'title'    => __( 'Build Your Bundle', 'bundlepilot' ),
            'priority' => 5,
            'callback' => array( $this, 'render_builder_tab_content' ),
        );

        return $tabs;
    }

    /**
     * Render builder tab content.
     *
     * @return void
     */
    public function render_builder_tab_content(): void {

        // Only render if the main container wasn't already rendered.
        // Check is done client-side by the React app.
        echo '<div id="aop-bb-builder-tab-root" class="aop-bb-builder-tab-fallback"></div>';
    }
}
