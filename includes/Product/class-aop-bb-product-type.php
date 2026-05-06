<?php
/**
 * Registers the bundle_builder product type with WooCommerce.
 *
 * Handles:
 * - Adding the type to the Product Data dropdown.
 * - Loading the WC_Product subclass via the class map filter.
 * - Showing/hiding the correct WooCommerce data tabs.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Product_Type
 */
class AOP_BB_Product_Type {

    /**
     * Register all hooks.
     *
     * @return void
     */
    public function register(): void {

        // Add product type to the dropdown selector.
        add_filter( 'product_type_selector', array( $this, 'add_product_type' ) );

        // Map the type string to our WC_Product subclass.
        add_filter( 'woocommerce_product_class', array( $this, 'map_product_class' ), 10, 2 );

        // Control which WooCommerce data tabs are visible.
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'configure_data_tabs' ) );

        // Show General and Inventory panels for our type (only on product edit screens).
        add_action( 'admin_footer-post.php', array( $this, 'show_data_panels' ) );
        add_action( 'admin_footer-post-new.php', array( $this, 'show_data_panels' ) );

        // Plan gate: enforce the bundle-count cap (3 for Free).
        add_action( 'pre_post_update', array( $this, 'enforce_bundle_limit_on_save' ), 10, 2 );

        // Display warning notice on the products list when the cap has been hit.
        add_action( 'admin_notices', array( $this, 'maybe_render_bundle_limit_notice' ) );
    }

    /**
     * Add "BundlePilot Bundle" to the product type selector dropdown.
     *
     * Note: the type *slug* (`bundle_builder`) stays unchanged so that
     * existing bundle products are not orphaned by the rebrand. Only the
     * display label is updated.
     *
     * @param array $types Existing product types.
     * @return array Modified product types.
     */
    public function add_product_type( array $types ): array {

        $types['bundle_builder'] = __( 'BundlePilot Bundle', 'bundlepilot' );

        return $types;
    }

    /**
     * Map the bundle_builder type string to the WC_Product subclass.
     *
     * @param string $classname The default WC_Product class name.
     * @param string $type      The product type slug.
     * @return string The resolved class name.
     */
    public function map_product_class( $classname, $type ) {

        if ( 'bundle_builder' === $type ) {
            return 'WC_Product_Bundle_Builder';
        }

        return $classname;
    }

    /**
     * Configure which standard WooCommerce data tabs are shown for our type.
     *
     * We show General (for the fixed price field) and Inventory.
     * Shipping, Linked Products, and Attributes remain available.
     * The "Bundle Steps" tab is added by AOP_BB_Product_Data.
     *
     * @param array $tabs Existing data tabs.
     * @return array Modified data tabs.
     */
    public function configure_data_tabs( array $tabs ): array {

        // Show General tab for bundle_builder.
        if ( isset( $tabs['general'] ) ) {
            $tabs['general']['class'][] = 'show_if_bundle_builder';
        }

        // Show Inventory tab.
        if ( isset( $tabs['inventory'] ) ) {
            $tabs['inventory']['class'][] = 'show_if_bundle_builder';
        }

        // Show Shipping tab (bundle-level shipping).
        if ( isset( $tabs['shipping'] ) ) {
            $tabs['shipping']['class'][] = 'show_if_bundle_builder';
        }

        return $tabs;
    }

    /**
     * Inject JS to show the pricing fields inside the General tab
     * when "Bundle Builder" is selected in the product type dropdown.
     *
     * Important: This ONLY adds show rules for bundle_builder.
     * It never touches visibility for other product types - that is
     * handled entirely by WooCommerce core JS.
     *
     * @return void
     */
    public function show_data_panels(): void {

        global $post;

        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline admin JS.
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            /**
             * When the product type changes, only act if the new type
             * is bundle_builder. For every other type, do nothing -
             * let WooCommerce core handle its own visibility rules.
             */
            $('select#product-type').on('change.aop_bb', function() {
                if ( $(this).val() !== 'bundle_builder' ) {
                    return;
                }

                // Show pricing fields inside the General tab for our type.
                $('#general_product_data .pricing').show();
            });

            // On page load, if the product is already bundle_builder, show pricing.
            if ( $('select#product-type').val() === 'bundle_builder' ) {
                $('#general_product_data .pricing').show();
            }
        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     |  Bundle Limit Enforcement
     | ------------------------------------------------------------------*/

    /**
     * Block creation/conversion of a 4th bundle on the Free plan.
     *
     * Strategy:
     * - Allow saves to existing bundles (so editing always works).
     * - For NEW posts where the user has selected the bundle_builder
     *   product type, count current bundles. If at the cap, force the
     *   product type to 'simple' and queue an admin notice.
     *
     * Hook: pre_post_update (fires before wp_update_post / wp_insert_post
     * commits the row, so we can mutate $_POST in time for downstream
     * filters and saved meta.)
     *
     * @param int   $post_id Post being saved.
     * @param array $data    Sanitized post data.
     * @return void
     */
    public function enforce_bundle_limit_on_save( $post_id, $data ): void {

        // Only act on product saves.
        if ( ! isset( $data['post_type'] ) || 'product' !== $data['post_type'] ) {
            return;
        }

        // Only act when the user is choosing 'bundle_builder' as the type.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies its own nonce on product save.
        $submitted_type = isset( $_POST['product-type'] ) ? sanitize_key( wp_unslash( $_POST['product-type'] ) ) : '';
        if ( 'bundle_builder' !== $submitted_type ) {
            return;
        }

        // If this product is ALREADY a bundle, allow the save (we don't
        // retro-punish existing data).
        $existing = wc_get_product( $post_id );
        if ( $existing && 'bundle_builder' === $existing->get_type() ) {
            return;
        }

        // The cap doesn't apply on Pro+.
        $max = AOP_BB_License_Manager::max_bundles();
        if ( $max <= 0 ) {
            return;
        }

        // Count existing bundles excluding the current post.
        if ( AOP_BB_License_Manager::count_bundles() >= $max ) {

            // Force the product type back to 'simple' for this save so
            // the rest of the save pipeline doesn't create a 4th bundle.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
            $_POST['product-type'] = 'simple';

            // Queue a user-facing notice for the next page render.
            AOP_BB_Product_Data::queue_plan_notice(
                array(
                    sprintf(
                        /* translators: %d: max bundles allowed on the active plan. */
                        __( 'Free plan is limited to %d bundles. This product was saved as a Simple product instead. Upgrade to Pro for unlimited bundles.', 'bundlepilot' ),
                        $max
                    ),
                )
            );
        }
    }

    /**
     * Render an admin-wide notice when the bundle cap is reached.
     *
     * Shown only on the Products list and the Add-New-Product screen,
     * so it's discoverable without being annoying everywhere.
     *
     * @return void
     */
    public function maybe_render_bundle_limit_notice(): void {

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen || ! in_array( $screen->id, array( 'edit-product', 'product' ), true ) ) {
            return;
        }

        $max = AOP_BB_License_Manager::max_bundles();
        if ( $max <= 0 ) {
            return;
        }

        $count = AOP_BB_License_Manager::count_bundles();
        if ( $count < $max ) {
            return;
        }

        printf(
            '<div class="notice notice-info"><p><strong>%s</strong> %s &mdash; <a href="%s" target="_blank" rel="noopener">%s &rarr;</a></p></div>',
            esc_html__( 'BundlePilot:', 'bundlepilot' ),
            sprintf(
                /* translators: %1$d: bundles created, %2$d: max bundles. */
                esc_html__( 'You have created %1$d of %2$d bundles allowed on the Free plan.', 'bundlepilot' ),
                (int) $count,
                (int) $max
            ),
            esc_url( AOP_BB_License_Manager::get_upgrade_url() ),
            esc_html__( 'Upgrade to Pro for unlimited bundles', 'bundlepilot' )
        );
    }
}
