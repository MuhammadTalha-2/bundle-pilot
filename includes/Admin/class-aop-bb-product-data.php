<?php
/**
 * Admin Product Data — Bundle Builder Configuration
 *
 * Adds a "Bundle Steps" tab to the WooCommerce product edit screen
 * when the product type is set to "Bundle Builder". This tab allows
 * store admins to configure:
 *
 * - Pricing mode: Fixed Price, Sum of Items, or Tiered Discounts.
 * - Fixed price amount (when pricing mode is "fixed").
 * - Tiered discount rules (when pricing mode is "tiered").
 * - Builder steps: each step has a title, product source
 *   (category or hand-picked products), and min/max quantities.
 *
 * All data is saved as post meta via WooCommerce's standard
 * `woocommerce_process_product_meta` hook.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Product_Data
 */
class AOP_BB_Product_Data {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Add the "Bundle Steps" tab.
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_bundle_tab' ) );

        // Render the tab panel contents.
        add_action( 'woocommerce_product_data_panels', array( $this, 'render_bundle_panel' ) );

        // Save meta on product save.
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_bundle_data' ) );

        // Enqueue admin assets on the product edit screen.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Render queued plan-violation notices (transient-driven).
        add_action( 'admin_notices', array( $this, 'maybe_render_plan_notices' ) );
    }

    /* ------------------------------------------------------------------
     |  Tab Registration
     | ------------------------------------------------------------------*/

    /**
     * Add the "Bundle Steps" tab to the product data area.
     *
     * @param array $tabs Existing tabs.
     * @return array Modified tabs.
     */
    public function add_bundle_tab( array $tabs ): array {

        $tabs['bundle_builder'] = array(
            'label'    => __( 'Bundle Steps', 'bundlepilot' ),
            'target'   => 'aop_bb_bundle_data',
            'class'    => array( 'show_if_bundle_builder' ),
            'priority' => 21,
        );

        return $tabs;
    }

    /* ------------------------------------------------------------------
     |  Panel Rendering
     | ------------------------------------------------------------------*/

    /**
     * Render the Bundle Steps panel in the product data meta box.
     *
     * @return void
     */
    public function render_bundle_panel(): void {

        global $post;

        $product_id = $post->ID;

        // Load existing data.
        $pricing_mode     = get_post_meta( $product_id, '_aop_bb_pricing_mode', true ) ?: 'sum';
        $fixed_price      = get_post_meta( $product_id, '_aop_bb_fixed_price', true ) ?: '';
        $tiered_discounts = get_post_meta( $product_id, '_aop_bb_tiered_discounts', true );
        $steps            = get_post_meta( $product_id, '_aop_bb_steps', true );

        if ( ! is_array( $tiered_discounts ) ) {
            $tiered_discounts = array();
        }

        if ( ! is_array( $steps ) ) {
            $steps = array();
        }

        // Get all product categories for the step dropdowns.
        $categories = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        if ( is_wp_error( $categories ) ) {
            $categories = array();
        }

        wp_nonce_field( 'aop_bb_save_bundle_data', 'aop_bb_bundle_nonce' );

        ?>
        <div id="aop_bb_bundle_data" class="panel woocommerce_options_panel hidden">

            <div class="aop-bb-panel-inner">

                <!-- ===================================================
                     SECTION: Pricing Configuration
                     =================================================== -->
                <div class="aop-bb-section">
                    <h3 class="aop-bb-section-title">
                        <?php esc_html_e( 'Pricing Configuration', 'bundlepilot' ); ?>
                    </h3>
                    <p class="aop-bb-section-desc">
                        <?php esc_html_e( 'Choose how the bundle price is calculated for customers.', 'bundlepilot' ); ?>
                    </p>

                    <?php
                    // Tiered pricing is a Pro feature. Show it in the dropdown
                    // but disable selection for Free users (still visible so
                    // they discover it). Server save also forces away from
                    // 'tiered' if the plan can't use it.
                    $can_use_tiered = AOP_BB_License_Manager::can_use( 'pricing_tiered' );
                    ?>
                    <p class="form-field">
                        <label for="aop_bb_pricing_mode">
                            <?php esc_html_e( 'Pricing Mode', 'bundlepilot' ); ?>
                        </label>
                        <select id="aop_bb_pricing_mode" name="aop_bb_pricing_mode" class="select short">
                            <option value="sum" <?php selected( $pricing_mode, 'sum' ); ?>>
                                <?php esc_html_e( 'Sum of Items — Total of selected product prices', 'bundlepilot' ); ?>
                            </option>
                            <option value="fixed" <?php selected( $pricing_mode, 'fixed' ); ?>>
                                <?php esc_html_e( 'Fixed Price — One set price for the entire bundle', 'bundlepilot' ); ?>
                            </option>
                            <option value="tiered"
                                    <?php selected( $pricing_mode, 'tiered' ); ?>
                                    <?php disabled( ! $can_use_tiered ); ?>>
                                <?php
                                if ( $can_use_tiered ) {
                                    esc_html_e( 'Tiered Discounts — Discount increases with quantity', 'bundlepilot' );
                                } else {
                                    esc_html_e( 'Tiered Discounts — Discount increases with quantity (PRO)', 'bundlepilot' );
                                }
                                ?>
                            </option>
                        </select>
                        <?php if ( ! $can_use_tiered ) : ?>
                            <span class="description aop-bb-feature-upsell">
                                <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                                   target="_blank" rel="noopener">
                                    <?php esc_html_e( 'Upgrade to Pro to unlock tiered volume discounts', 'bundlepilot' ); ?> →
                                </a>
                            </span>
                        <?php endif; ?>
                    </p>

                    <!-- Fixed Price Field -->
                    <div id="aop-bb-fixed-price-wrap" class="aop-bb-conditional" style="<?php echo 'fixed' !== $pricing_mode ? 'display:none;' : ''; ?>">
                        <?php
                        woocommerce_wp_text_input(
                            array(
                                'id'                => 'aop_bb_fixed_price',
                                'label'             => __( 'Bundle Price', 'bundlepilot' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                                'type'              => 'text',
                                'data_type'         => 'price',
                                'value'             => $fixed_price,
                                'desc_tip'          => true,
                                'description'       => __( 'The fixed price customers pay for this bundle, regardless of which items they choose.', 'bundlepilot' ),
                                'custom_attributes' => array(
                                    'step' => 'any',
                                    'min'  => '0',
                                ),
                            )
                        );
                        ?>
                    </div>

                    <!-- Tiered Discounts -->
                    <div id="aop-bb-tiered-wrap" class="aop-bb-conditional" style="<?php echo 'tiered' !== $pricing_mode ? 'display:none;' : ''; ?>">
                        <p class="form-field">
                            <label><?php esc_html_e( 'Discount Tiers', 'bundlepilot' ); ?></label>
                            <span class="description">
                                <?php esc_html_e( 'Define quantity thresholds and the percentage discount applied when met. Based on total items across all steps.', 'bundlepilot' ); ?>
                            </span>
                        </p>

                        <table class="widefat aop-bb-tier-table" id="aop-bb-tier-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Min Items', 'bundlepilot' ); ?></th>
                                    <th><?php esc_html_e( 'Discount (%)', 'bundlepilot' ); ?></th>
                                    <th class="aop-bb-col-actions">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody id="aop-bb-tier-rows">
                                <?php if ( ! empty( $tiered_discounts ) ) : ?>
                                    <?php foreach ( $tiered_discounts as $index => $tier ) : ?>
                                        <tr class="aop-bb-tier-row">
                                            <td>
                                                <input type="number"
                                                       name="aop_bb_tier_min_qty[]"
                                                       value="<?php echo esc_attr( $tier['min_qty'] ?? '' ); ?>"
                                                       min="1"
                                                       step="1"
                                                       class="short" />
                                            </td>
                                            <td>
                                                <input type="number"
                                                       name="aop_bb_tier_discount[]"
                                                       value="<?php echo esc_attr( $tier['discount'] ?? '' ); ?>"
                                                       min="0"
                                                       max="100"
                                                       step="0.01"
                                                       class="short" />
                                            </td>
                                            <td class="aop-bb-col-actions">
                                                <button type="button" class="button aop-bb-remove-tier">
                                                    &times;
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">
                                        <button type="button" class="button" id="aop-bb-add-tier">
                                            <?php esc_html_e( '+ Add Tier', 'bundlepilot' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- ===================================================
                     SECTION: Builder Steps
                     =================================================== -->
                <div class="aop-bb-section">
                    <h3 class="aop-bb-section-title">
                        <?php esc_html_e( 'Builder Steps', 'bundlepilot' ); ?>
                    </h3>
                    <p class="aop-bb-section-desc">
                        <?php esc_html_e( 'Define the steps customers follow to build their bundle. Each step presents a selection of products.', 'bundlepilot' ); ?>
                    </p>

                    <div id="aop-bb-steps-container">
                        <?php if ( ! empty( $steps ) ) : ?>
                            <?php foreach ( $steps as $i => $step ) : ?>
                                <?php $this->render_step_row( $i, $step, $categories ); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="aop-bb-add-step-wrap">
                        <button type="button" class="button button-primary" id="aop-bb-add-step">
                            <?php esc_html_e( '+ Add Step', 'bundlepilot' ); ?>
                        </button>
                    </div>
                </div>

                <?php
                /**
                 * Fires once per bundle, after the steps configuration section.
                 *
                 * Used by Business features to inject additional bundle-wide
                 * settings (role-based visibility, future per-bundle options).
                 * Must be inside `render_bundle_panel()` (not `render_step_row()`)
                 * so the listener fires exactly once per page load — not once
                 * per step, and not once for the hidden JS template clone.
                 *
                 * @since 1.0.0
                 */
                do_action( 'aop_bb_product_data_panel_after_steps' );
                ?>

            </div>

            <!-- Step Template (hidden, cloned by JS) -->
            <script type="text/html" id="tmpl-aop-bb-step">
                <?php $this->render_step_row( '{{INDEX}}', array(), $categories ); ?>
            </script>

            <!-- Tier Row Template -->
            <script type="text/html" id="tmpl-aop-bb-tier">
                <tr class="aop-bb-tier-row">
                    <td>
                        <input type="number" name="aop_bb_tier_min_qty[]" value="" min="1" step="1" class="short" />
                    </td>
                    <td>
                        <input type="number" name="aop_bb_tier_discount[]" value="" min="0" max="100" step="0.01" class="short" />
                    </td>
                    <td class="aop-bb-col-actions">
                        <button type="button" class="button aop-bb-remove-tier">&times;</button>
                    </td>
                </tr>
            </script>

        </div>
        <?php
    }

    /**
     * Render a single step configuration row.
     *
     * @param int|string $index      Step index (int or template placeholder).
     * @param array      $step       Saved step data.
     * @param array      $categories Product category terms.
     * @return void
     */
    private function render_step_row( $index, array $step, array $categories ): void {

        $title        = $step['title'] ?? '';
        $source       = $step['source'] ?? 'category';
        $category_ids = $step['category_ids'] ?? array();
        $product_ids  = $step['product_ids'] ?? array();
        $min_qty      = $step['min_qty'] ?? 1;
        $max_qty      = $step['max_qty'] ?? 1;

        // Ensure arrays.
        $category_ids = array_map( 'absint', (array) $category_ids );
        $product_ids  = array_map( 'absint', (array) $product_ids );

        $prefix = "aop_bb_steps[{$index}]";

        ?>
        <div class="aop-bb-step" data-step-index="<?php echo esc_attr( $index ); ?>">
            <div class="aop-bb-step-header">
                <span class="aop-bb-step-handle dashicons dashicons-menu"></span>
                <span class="aop-bb-step-number">
                    <?php
                    printf(
                        /* translators: %s Step number */
                        esc_html__( 'Step %s', 'bundlepilot' ),
                        '<span class="aop-bb-step-idx">' . esc_html( is_numeric( $index ) ? $index + 1 : '#' ) . '</span>'
                    );
                    ?>
                </span>
                <span class="aop-bb-step-title-preview"><?php echo esc_html( $title ); ?></span>
                <button type="button" class="button aop-bb-remove-step" title="<?php esc_attr_e( 'Remove step', 'bundlepilot' ); ?>">
                    &times;
                </button>
                <button type="button" class="button aop-bb-toggle-step dashicons dashicons-arrow-down-alt2" title="<?php esc_attr_e( 'Toggle', 'bundlepilot' ); ?>"></button>
            </div>

            <div class="aop-bb-step-body">

                <!-- Step Title -->
                <p class="form-field">
                    <label><?php esc_html_e( 'Step Title', 'bundlepilot' ); ?></label>
                    <input type="text"
                           name="<?php echo esc_attr( $prefix ); ?>[title]"
                           value="<?php echo esc_attr( $title ); ?>"
                           class="short aop-bb-step-title-input"
                           placeholder="<?php esc_attr_e( 'e.g., Choose a Base', 'bundlepilot' ); ?>" />
                </p>

                <?php
                // Hand-Picked Products is a Pro feature. Visible-but-disabled
                // for Free users so they discover the upgrade path.
                $can_use_handpicked = AOP_BB_License_Manager::can_use( 'handpicked_steps' );
                ?>
                <!-- Product Source -->
                <p class="form-field">
                    <label><?php esc_html_e( 'Product Source', 'bundlepilot' ); ?></label>
                    <select name="<?php echo esc_attr( $prefix ); ?>[source]" class="select short aop-bb-source-select">
                        <option value="category" <?php selected( $source, 'category' ); ?>>
                            <?php esc_html_e( 'By Category', 'bundlepilot' ); ?>
                        </option>
                        <option value="products"
                                <?php selected( $source, 'products' ); ?>
                                <?php disabled( ! $can_use_handpicked ); ?>>
                            <?php
                            if ( $can_use_handpicked ) {
                                esc_html_e( 'Hand-Picked Products', 'bundlepilot' );
                            } else {
                                esc_html_e( 'Hand-Picked Products (PRO)', 'bundlepilot' );
                            }
                            ?>
                        </option>
                    </select>
                </p>

                <!-- Category Selector (shown when source = category) -->
                <div class="aop-bb-source-category form-field" style="<?php echo 'products' === $source ? 'display:none;' : ''; ?>">
                    <label><?php esc_html_e( 'Categories', 'bundlepilot' ); ?></label>
                    <select name="<?php echo esc_attr( $prefix ); ?>[category_ids][]"
                            multiple="multiple"
                            class="wc-enhanced-select short"
                            data-placeholder="<?php esc_attr_e( 'Select categories', 'bundlepilot' ); ?>">
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>"
                                <?php echo in_array( $cat->term_id, $category_ids, true ) ? 'selected' : ''; ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Product Selector (shown when source = products) -->
                <div class="aop-bb-source-products form-field" style="<?php echo 'category' === $source ? 'display:none;' : ''; ?>">
                    <label><?php esc_html_e( 'Products', 'bundlepilot' ); ?></label>
                    <select name="<?php echo esc_attr( $prefix ); ?>[product_ids][]"
                            multiple="multiple"
                            class="wc-product-search short"
                            data-action="woocommerce_json_search_products_and_variations"
                            data-placeholder="<?php esc_attr_e( 'Search for products', 'bundlepilot' ); ?>">
                        <?php
                        foreach ( $product_ids as $pid ) {
                            $p = wc_get_product( $pid );
                            if ( $p ) {
                                printf(
                                    '<option value="%d" selected>%s</option>',
                                    esc_attr( $pid ),
                                    esc_html( wp_strip_all_tags( $p->get_formatted_name() ) )
                                );
                            }
                        }
                        ?>
                    </select>
                </div>

                <?php
                // Min/Max Quantity Rules is a Pro feature in its entirety:
                //   - Free  → both Min and Max are locked to 1 (single-choice
                //              steps only). Each step = "pick exactly one item".
                //   - Pro   → Min can be 0 (optional steps) up to N, Max can
                //              be 1 to N (multi-select / volume steps).
                //
                // Free's locked defaults are visible-but-disabled to surface
                // the Pro upgrade discoverably.
                $can_use_qty_rules = AOP_BB_License_Manager::can_use( 'min_max_qty' );

                // Display values: locked to 1/1 for Free, real values for Pro.
                $min_qty_display = $can_use_qty_rules ? max( 0, (int) $min_qty ) : 1;
                $max_qty_display = $can_use_qty_rules ? max( 1, (int) $max_qty ) : 1;
                $min_qty_floor   = $can_use_qty_rules ? 0 : 1;
                $min_qty_help    = $can_use_qty_rules
                    ? __( 'Minimum items a customer must select. Use 0 to make this step optional.', 'bundlepilot' )
                    : __( 'Locked to 1 on Free plan — each step requires exactly one selection.', 'bundlepilot' );
                $max_qty_help    = $can_use_qty_rules
                    ? __( 'Maximum items a customer can select. Set to 1 for single-choice steps.', 'bundlepilot' )
                    : __( 'Locked to 1 on Free plan — multi-select per step requires Pro.', 'bundlepilot' );
                ?>
                <!-- Min / Max Quantities -->
                <div class="aop-bb-qty-row">
                    <p class="form-field form-field-half">
                        <label>
                            <?php esc_html_e( 'Min Items', 'bundlepilot' ); ?>
                            <?php if ( ! $can_use_qty_rules ) : ?>
                                <span class="aop-bb-plan-badge aop-bb-plan-badge--pro"
                                      title="<?php esc_attr_e( 'Min/Max Quantity Rules require Pro', 'bundlepilot' ); ?>">PRO</span>
                            <?php endif; ?>
                        </label>
                        <input type="number"
                               name="<?php echo esc_attr( $prefix ); ?>[min_qty]"
                               value="<?php echo esc_attr( (string) $min_qty_display ); ?>"
                               min="<?php echo esc_attr( (string) $min_qty_floor ); ?>"
                               step="1"
                               class="short"
                               <?php disabled( ! $can_use_qty_rules ); ?> />
                        <span class="aop-bb-field-hint">
                            <?php echo esc_html( $min_qty_help ); ?>
                        </span>
                    </p>
                    <p class="form-field form-field-half">
                        <label>
                            <?php esc_html_e( 'Max Items', 'bundlepilot' ); ?>
                            <?php if ( ! $can_use_qty_rules ) : ?>
                                <span class="aop-bb-plan-badge aop-bb-plan-badge--pro"
                                      title="<?php esc_attr_e( 'Min/Max Quantity Rules require Pro', 'bundlepilot' ); ?>">PRO</span>
                            <?php endif; ?>
                        </label>
                        <input type="number"
                               name="<?php echo esc_attr( $prefix ); ?>[max_qty]"
                               value="<?php echo esc_attr( (string) $max_qty_display ); ?>"
                               min="1"
                               step="1"
                               class="short"
                               <?php disabled( ! $can_use_qty_rules ); ?> />
                        <span class="aop-bb-field-hint">
                            <?php echo esc_html( $max_qty_help ); ?>
                        </span>
                    </p>
                </div>

                <?php if ( ! $can_use_qty_rules ) : ?>
                    <p class="form-field aop-bb-feature-upsell" style="padding: 0;">
                        <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                           target="_blank" rel="noopener">
                            <?php esc_html_e( 'Upgrade to Pro to unlock Min/Max quantity rules and optional steps', 'bundlepilot' ); ?> →
                        </a>
                    </p>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     |  Save Handler
     | ------------------------------------------------------------------*/

    /**
     * Save bundle configuration when the product is saved.
     *
     * @param int $product_id The product ID being saved.
     * @return void
     */
    public function save_bundle_data( $product_id ): void {

        // Verify nonce.
        if (
            ! isset( $_POST['aop_bb_bundle_nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['aop_bb_bundle_nonce'] ) ),
                'aop_bb_save_bundle_data'
            )
        ) {
            return;
        }

        // Only save for our product type.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Already verified above.
        $product_type = isset( $_POST['product-type'] ) ? sanitize_key( $_POST['product-type'] ) : '';
        if ( 'bundle_builder' !== $product_type ) {
            return;
        }

        // Collect plan-violation messages for a single user-facing notice
        // shown after the redirect. Multiple violations can be recorded
        // and rendered together on the next page load.
        $violations = array();

        // --- Pricing Mode ---
        $pricing_mode = isset( $_POST['aop_bb_pricing_mode'] )
            ? sanitize_key( $_POST['aop_bb_pricing_mode'] )
            : 'sum';

        if ( ! in_array( $pricing_mode, array( 'fixed', 'sum', 'tiered' ), true ) ) {
            $pricing_mode = 'sum';
        }

        // Plan gate: 'tiered' requires Pro. Force back to 'sum' otherwise.
        if ( 'tiered' === $pricing_mode && ! AOP_BB_License_Manager::can_use( 'pricing_tiered' ) ) {
            $pricing_mode = 'sum';
            $violations[] = __( 'Tiered Discount pricing requires the Pro plan. Pricing mode reset to "Sum of Items".', 'bundlepilot' );
        }

        update_post_meta( $product_id, '_aop_bb_pricing_mode', $pricing_mode );

        // --- Fixed Price ---
        $fixed_price = isset( $_POST['aop_bb_fixed_price'] )
            ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['aop_bb_fixed_price'] ) ) )
            : '';

        update_post_meta( $product_id, '_aop_bb_fixed_price', $fixed_price );

        // --- Tiered Discounts ---
        $tiered_discounts = array();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per element.
        $tier_min_qtys  = isset( $_POST['aop_bb_tier_min_qty'] ) ? wp_unslash( $_POST['aop_bb_tier_min_qty'] ) : array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per element.
        $tier_discounts = isset( $_POST['aop_bb_tier_discount'] ) ? wp_unslash( $_POST['aop_bb_tier_discount'] ) : array();

        if ( is_array( $tier_min_qtys ) ) {
            foreach ( $tier_min_qtys as $i => $min_qty ) {
                $qty      = absint( $min_qty );
                $discount = isset( $tier_discounts[ $i ] )
                    ? floatval( sanitize_text_field( $tier_discounts[ $i ] ) )
                    : 0;

                if ( $qty > 0 && $discount > 0 ) {
                    $tiered_discounts[] = array(
                        'min_qty'  => $qty,
                        'discount' => min( $discount, 100 ),
                    );
                }
            }
        }

        // Sort by min_qty ascending.
        usort( $tiered_discounts, function ( array $a, array $b ): int {
            return $a['min_qty'] <=> $b['min_qty'];
        } );

        update_post_meta( $product_id, '_aop_bb_tiered_discounts', $tiered_discounts );

        // --- Bundle Steps ---
        $steps_clean = array();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Deeply sanitized below.
        $steps_raw = isset( $_POST['aop_bb_steps'] ) ? wp_unslash( $_POST['aop_bb_steps'] ) : array();

        // Plan gate: enforce maximum step count for the active plan.
        // Steps beyond the cap are silently dropped before per-step
        // sanitization so the data we persist is always valid.
        $max_steps = AOP_BB_License_Manager::max_steps_per_bundle();
        if ( is_array( $steps_raw ) && $max_steps > 0 && count( $steps_raw ) > $max_steps ) {
            $steps_raw    = array_slice( $steps_raw, 0, $max_steps );
            $violations[] = sprintf(
                /* translators: %d: max steps allowed on the active plan. */
                __( 'Free plan is limited to %d steps per bundle. Extra steps were not saved. Upgrade to Pro for unlimited steps.', 'bundlepilot' ),
                $max_steps
            );
        }

        // Plan gate: handpicked source requires Pro.
        $can_use_handpicked = AOP_BB_License_Manager::can_use( 'handpicked_steps' );
        $handpicked_blocked = false;

        // Plan gate: optional steps (min_qty=0) require Pro.
        $can_use_optional   = AOP_BB_License_Manager::can_use( 'optional_steps' );
        $optional_blocked   = false;

        // Plan gate: Min/Max Quantity Rules (any value other than 1/1) require Pro.
        // For Free, both min_qty and max_qty are forced to 1 regardless of submitted values.
        $can_use_qty_rules  = AOP_BB_License_Manager::can_use( 'min_max_qty' );
        $qty_rules_blocked  = false;

        if ( is_array( $steps_raw ) ) {
            foreach ( $steps_raw as $step_data ) {
                if ( ! is_array( $step_data ) ) {
                    continue;
                }

                $title  = isset( $step_data['title'] )
                    ? sanitize_text_field( $step_data['title'] )
                    : '';
                $source = isset( $step_data['source'] )
                    ? sanitize_key( $step_data['source'] )
                    : 'category';

                if ( ! in_array( $source, array( 'category', 'products' ), true ) ) {
                    $source = 'category';
                }

                // Plan gate: drop handpicked source for non-Pro users.
                if ( 'products' === $source && ! $can_use_handpicked ) {
                    $source             = 'category';
                    $handpicked_blocked = true;
                }

                $category_ids = array();
                if ( isset( $step_data['category_ids'] ) && is_array( $step_data['category_ids'] ) ) {
                    $category_ids = array_map( 'absint', $step_data['category_ids'] );
                    $category_ids = array_filter( $category_ids );
                }

                $product_ids = array();
                if ( isset( $step_data['product_ids'] ) && is_array( $step_data['product_ids'] ) ) {
                    $product_ids = array_map( 'absint', $step_data['product_ids'] );
                    $product_ids = array_filter( $product_ids );
                }

                $min_qty = isset( $step_data['min_qty'] ) ? absint( $step_data['min_qty'] ) : 0;
                $max_qty = isset( $step_data['max_qty'] ) ? absint( $step_data['max_qty'] ) : 1;

                // Plan gate: Min/Max Quantity Rules require Pro. On Free,
                // both inputs are hard-pinned to 1 (single-choice steps).
                // This check runs FIRST so the optional-step gate below
                // never triggers on Free (min already coerced to 1 here).
                if ( ! $can_use_qty_rules ) {
                    if ( 1 !== $min_qty || 1 !== $max_qty ) {
                        $qty_rules_blocked = true;
                    }
                    $min_qty = 1;
                    $max_qty = 1;
                }

                // Plan gate: optional step (min_qty=0) requires Pro.
                if ( 0 === $min_qty && ! $can_use_optional ) {
                    $min_qty          = 1;
                    $optional_blocked = true;
                }

                // Ensure max >= min.
                if ( $max_qty < $min_qty ) {
                    $max_qty = $min_qty;
                }

                $steps_clean[] = array(
                    'title'        => $title,
                    'source'       => $source,
                    'category_ids' => $category_ids,
                    'product_ids'  => $product_ids,
                    'min_qty'      => $min_qty,
                    'max_qty'      => $max_qty,
                );
            }
        }

        if ( $handpicked_blocked ) {
            $violations[] = __( 'Hand-picked product steps require the Pro plan. Affected steps were reverted to category-based selection.', 'bundlepilot' );
        }

        if ( $qty_rules_blocked ) {
            $violations[] = __( 'Min/Max quantity rules require the Pro plan. Affected steps were reset to 1 item per step (single-choice).', 'bundlepilot' );
        }

        // Note: $optional_blocked can no longer trigger on Free because
        // $can_use_qty_rules false already coerces min_qty to 1 above.
        // It still handles edge cases on Pro where optional_steps might
        // be gated separately in the future.
        if ( $optional_blocked ) {
            $violations[] = __( 'Optional steps (Min items = 0) require the Pro plan. Min items was raised to 1 on affected steps.', 'bundlepilot' );
        }

        update_post_meta( $product_id, '_aop_bb_steps', $steps_clean );

        // Queue the user-facing notice so it renders on the next page load.
        if ( ! empty( $violations ) ) {
            self::queue_plan_notice( $violations );
        }
    }

    /* ------------------------------------------------------------------
     |  Admin Notices for Plan-Limit Violations
     | ------------------------------------------------------------------*/

    /**
     * Transient key for queued plan-violation messages.
     *
     * Per-user so notices reach the editor who triggered them, not
     * other admins editing in parallel.
     *
     * @return string
     */
    protected static function notice_transient_key(): string {

        return 'aop_bb_plan_notice_' . get_current_user_id();
    }

    /**
     * Queue plan-violation messages to display on the next admin page load.
     *
     * @param string[] $messages Messages to display.
     * @return void
     */
    public static function queue_plan_notice( array $messages ): void {

        if ( empty( $messages ) || ! get_current_user_id() ) {
            return;
        }

        set_transient( self::notice_transient_key(), array_values( $messages ), 60 );
    }

    /**
     * Render queued plan-violation notices on the next page load.
     *
     * @return void
     */
    public function maybe_render_plan_notices(): void {

        if ( ! get_current_user_id() ) {
            return;
        }

        $messages = get_transient( self::notice_transient_key() );

        if ( empty( $messages ) || ! is_array( $messages ) ) {
            return;
        }

        delete_transient( self::notice_transient_key() );

        $upgrade_url = AOP_BB_License_Manager::get_upgrade_url();

        foreach ( $messages as $message ) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s &mdash; <a href="%s" target="_blank" rel="noopener">%s &rarr;</a></p></div>',
                esc_html__( 'BundlePilot:', 'bundlepilot' ),
                esc_html( $message ),
                esc_url( $upgrade_url ),
                esc_html__( 'Upgrade now', 'bundlepilot' )
            );
        }
    }

    /* ------------------------------------------------------------------
     |  Admin Assets
     | ------------------------------------------------------------------*/

    /**
     * Enqueue admin JS and CSS for the product edit screen.
     *
     * @param string $hook_suffix The current admin page.
     * @return void
     */
    public function enqueue_admin_assets( $hook_suffix ) {

        if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        global $post;

        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        // Register inline CSS.
        wp_register_style( 'aop-bb-admin-product', false );
        wp_enqueue_style( 'aop-bb-admin-product' );
        wp_add_inline_style( 'aop-bb-admin-product', $this->get_admin_css() );

        // Register inline JS.
        wp_register_script( 'aop-bb-admin-product', '', array( 'jquery' ), AOP_BB_VERSION, true );
        wp_enqueue_script( 'aop-bb-admin-product' );

        // Make license/plan limits available to the JS so the +Add Step
        // button (and any future UI gating) can enforce caps client-side.
        wp_localize_script(
            'aop-bb-admin-product',
            'aopBBLicense',
            array(
                'plan'                  => AOP_BB_License_Manager::get_plan_name(),
                'isPro'                 => AOP_BB_License_Manager::is_pro(),
                'isBusiness'            => AOP_BB_License_Manager::is_business(),
                'maxBundles'            => AOP_BB_License_Manager::max_bundles(),
                'maxStepsPerBundle'     => AOP_BB_License_Manager::max_steps_per_bundle(),
                'canUseTieredPricing'   => AOP_BB_License_Manager::can_use( 'pricing_tiered' ),
                'canUseHandpicked'      => AOP_BB_License_Manager::can_use( 'handpicked_steps' ),
                'canUseOptionalSteps'   => AOP_BB_License_Manager::can_use( 'optional_steps' ),
                'upgradeUrl'            => AOP_BB_License_Manager::get_upgrade_url(),
                'i18n'                  => array(
                    'stepLimitReached'  => sprintf(
                        /* translators: %d: max steps allowed */
                        __( 'Free plan is limited to %d steps per bundle. Upgrade to Pro for unlimited steps.', 'bundlepilot' ),
                        AOP_BB_License_Manager::FREE_LIMITS['max_steps_per_bundle']
                    ),
                    'stepLimitBadge'    => __( 'Step limit reached', 'bundlepilot' ),
                    'upgradeToPro'      => __( 'Upgrade to Pro', 'bundlepilot' ),
                ),
            )
        );

        wp_add_inline_script( 'aop-bb-admin-product', $this->get_admin_js() );
    }

    /* ------------------------------------------------------------------
     |  Inline CSS
     | ------------------------------------------------------------------*/

    /**
     * Get admin CSS for the Bundle Steps panel.
     *
     * @return string
     */
    private function get_admin_css(): string {

        return '
        /* Panel layout */
        .aop-bb-panel-inner {
            padding: 12px 12px 0;
        }

        .aop-bb-section {
            margin-bottom: 16px;
        }

        .aop-bb-section-title {
            font-size: 14px;
            font-weight: 600;
            margin: 0 0 4px 0;
        }

        .aop-bb-section-desc {
            color: #646970;
            font-size: 13px;
            margin: 0 0 14px 0;
            font-style: italic;
        }

        /* Override WC panel form field float/padding defaults
           so fields stack vertically inside step cards. */
        #aop_bb_bundle_data .aop-bb-step-body .form-field,
        #aop_bb_bundle_data .aop-bb-step-body .aop-bb-qty-row,
        #aop_bb_bundle_data .aop-bb-step-body .aop-bb-source-category,
        #aop_bb_bundle_data .aop-bb-step-body .aop-bb-source-products {
            padding-left: 0 !important;
            padding-right: 0 !important;
            float: none !important;
            clear: both !important;
        }

        .aop-bb-step-body .form-field label {
            display: block !important;
            float: none !important;
            width: auto !important;
            margin: 0 0 4px 0 !important;
            padding: 0 !important;
        }

        .aop-bb-step-body .form-field input[type="text"],
        .aop-bb-step-body .form-field input[type="number"],
        .aop-bb-step-body .form-field select,
        .aop-bb-step-body .form-field .select2-container {
            width: 100% !important;
            max-width: 100% !important;
            float: none !important;
        }

        .aop-bb-source-category label,
        .aop-bb-source-products label {
            display: block !important;
            float: none !important;
            width: auto !important;
            margin: 0 0 4px 0 !important;
            padding: 0 !important;
        }

        .aop-bb-source-category select,
        .aop-bb-source-products select,
        .aop-bb-source-category .select2-container,
        .aop-bb-source-products .select2-container {
            width: 100% !important;
            max-width: 100% !important;
        }

        .aop-bb-source-category,
        .aop-bb-source-products {
            margin-top: 2px;
            padding: 0 !important;
            float: none !important;
            clear: both !important;
        }

        /* Tier table */
        .aop-bb-tier-table {
            margin: 8px 0;
        }

        .aop-bb-tier-table input {
            width: 100%;
        }

        .aop-bb-col-actions {
            width: 44px;
            text-align: center;
        }

        /* Step cards — standard WP postbox style */
        .aop-bb-step {
            background: #fff;
            border: 1px solid #c3c4c7;
            margin-bottom: 12px;
        }

        .aop-bb-step-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f6f7f7;
            border-bottom: 1px solid #c3c4c7;
            cursor: pointer;
        }

        .aop-bb-step-handle {
            color: #a7aaad;
            cursor: move;
        }

        .aop-bb-step-number {
            font-weight: 600;
            font-size: 12px;
            color: #50575e;
            white-space: nowrap;
        }

        .aop-bb-step-title-preview {
            color: #1d2327;
            font-size: 13px;
            font-weight: 500;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .aop-bb-step-title-preview:empty::after {
            content: "Untitled step";
            color: #a7aaad;
            font-style: italic;
        }

        .aop-bb-remove-step {
            color: #b32d2e !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            min-height: auto !important;
            padding: 2px 6px !important;
        }

        .aop-bb-remove-step:hover {
            color: #a00 !important;
        }

        .aop-bb-toggle-step {
            border: none !important;
            background: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            min-height: auto !important;
            color: #a7aaad !important;
        }

        .aop-bb-step-body {
            padding: 12px 16px;
        }

        /* Collapsed state */
        .aop-bb-step.collapsed .aop-bb-step-body {
            display: none;
        }

        .aop-bb-step.collapsed .aop-bb-step-header {
            border-bottom: none;
        }

        .aop-bb-step.collapsed .aop-bb-toggle-step {
            transform: rotate(-90deg);
        }

        /* Quantity row — flex for min/max side-by-side */
        .aop-bb-qty-row {
            display: flex !important;
            gap: 16px;
            padding: 0 !important;
            margin: 0 !important;
            float: none !important;
            clear: both !important;
        }

        .aop-bb-qty-row .form-field-half {
            flex: 1;
            padding: 0 !important;
            margin: 0 !important;
            float: none !important;
        }

        .aop-bb-qty-row .form-field-half label {
            display: block !important;
            float: none !important;
            width: auto !important;
            margin: 0 0 4px 0 !important;
            padding: 0 !important;
        }

        .aop-bb-qty-row .form-field-half input[type="number"] {
            width: 100% !important;
            max-width: 100% !important;
            float: none !important;
            box-sizing: border-box !important;
        }

        .aop-bb-field-hint {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: #646970;
            font-style: italic;
        }

        /* Sortable placeholder */
        .aop-bb-step-placeholder {
            border: 2px dashed #c3c4c7;
            margin-bottom: 12px;
            min-height: 50px;
        }

        /* Add Step Button — standard WP button */
        .aop-bb-add-step-wrap {
            margin-top: 10px;
            text-align: center;
        }

        /* Empty state */
        #aop-bb-steps-container:empty::after {
            content: "No steps added yet. Click the button below to add your first step.";
            display: block;
            text-align: center;
            padding: 24px 16px;
            color: #646970;
            font-size: 13px;
            border: 2px dashed #c3c4c7;
            font-style: italic;
        }

        /* Tab icon — standard dashicon */
        #woocommerce-product-data ul.wc-tabs li.bundle_builder_options a::before {
            content: "\f160";
            font-family: dashicons;
        }
        ';
    }

    /* ------------------------------------------------------------------
     |  Inline JS
     | ------------------------------------------------------------------*/

    /**
     * Get admin JS for the Bundle Steps panel.
     *
     * @return string
     */
    private function get_admin_js(): string {

        return "
        jQuery(function($) {
            'use strict';

            var stepIndex = $('#aop-bb-steps-container .aop-bb-step').length;

            /* --- Pricing Mode Toggle --- */
            $('#aop_bb_pricing_mode').on('change', function() {
                var mode = $(this).val();
                $('#aop-bb-fixed-price-wrap').toggle(mode === 'fixed');
                $('#aop-bb-tiered-wrap').toggle(mode === 'tiered');
            });

            /* --- Tier Rows --- */
            $('#aop-bb-add-tier').on('click', function() {
                var tmpl = $('#tmpl-aop-bb-tier').html();
                $('#aop-bb-tier-rows').append(tmpl);
            });

            $(document).on('click', '.aop-bb-remove-tier', function() {
                $(this).closest('tr').remove();
            });

            /* --- Step Management --- */
            var license  = window.aopBBLicense || {};
            var maxSteps = parseInt(license.maxStepsPerBundle, 10) || 0; // 0 = unlimited.

            function currentStepCount() {
                return \$('#aop-bb-steps-container .aop-bb-step').length;
            }

            function refreshAddStepState() {
                if (maxSteps === 0) return; // Unlimited.

                var \$btn   = \$('#aop-bb-add-step');
                var \$wrap  = \$btn.closest('.aop-bb-add-step-wrap');
                var atCap  = currentStepCount() >= maxSteps;

                \$btn.prop('disabled', atCap)
                     .toggleClass('aop-bb-add-step--locked', atCap);

                // Show / hide the cap notice.
                var \$notice = \$wrap.find('.aop-bb-step-limit-notice');
                if (atCap) {
                    if (\$notice.length === 0) {
                        \$notice = \$('<p class=\"aop-bb-step-limit-notice\"></p>').appendTo(\$wrap);
                    }
                    var msg = (license.i18n && license.i18n.stepLimitReached) || '';
                    var cta = (license.i18n && license.i18n.upgradeToPro) || 'Upgrade';
                    \$notice.html(
                        '<span class=\"aop-bb-plan-badge aop-bb-plan-badge--pro\">PRO</span> ' +
                        \$('<div/>').text(msg).html() +
                        ' <a href=\"' + (license.upgradeUrl || '#') + '\" target=\"_blank\" rel=\"noopener\">' +
                        \$('<div/>').text(cta).html() + ' →</a>'
                    );
                } else if (\$notice.length) {
                    \$notice.remove();
                }
            }

            \$('#aop-bb-add-step').on('click', function(e) {
                if (maxSteps > 0 && currentStepCount() >= maxSteps) {
                    e.preventDefault();
                    refreshAddStepState();
                    return;
                }

                var tmpl = \$('#tmpl-aop-bb-step').html();
                tmpl = tmpl.replace(/\\{\\{INDEX\\}\\}/g, stepIndex);
                var newStep = \$(tmpl);
                \$('#aop-bb-steps-container').append(newStep);

                // Initialize WooCommerce enhanced selects on the new step.
                newStep.find('.wc-enhanced-select').each(function() {
                    \$(this).selectWoo();
                });

                // Initialize WooCommerce product search on the new step.
                \$(document.body).trigger('wc-enhanced-select-init');

                stepIndex++;
                renumberSteps();
                refreshAddStepState();
            });

            \$(document).on('click', '.aop-bb-remove-step', function(e) {
                e.stopPropagation();
                if (confirm('" . esc_js( __( 'Remove this step?', 'bundlepilot' ) ) . "')) {
                    \$(this).closest('.aop-bb-step').remove();
                    renumberSteps();
                    refreshAddStepState();
                }
            });

            // Run once on page load to set the initial button state.
            refreshAddStepState();

            /* --- Toggle / Collapse --- */
            $(document).on('click', '.aop-bb-toggle-step, .aop-bb-step-header', function(e) {
                if ($(e.target).is('.aop-bb-remove-step')) return;
                $(this).closest('.aop-bb-step').toggleClass('collapsed');
            });

            /* --- Source Switcher --- */
            $(document).on('change', '.aop-bb-source-select', function() {
                var step = $(this).closest('.aop-bb-step');
                var val = $(this).val();
                step.find('.aop-bb-source-category').toggle(val === 'category');
                step.find('.aop-bb-source-products').toggle(val === 'products');
            });

            /* --- Live Title Preview --- */
            $(document).on('input', '.aop-bb-step-title-input', function() {
                $(this).closest('.aop-bb-step')
                    .find('.aop-bb-step-title-preview')
                    .text($(this).val());
            });

            /* --- Renumber Steps --- */
            function renumberSteps() {
                $('#aop-bb-steps-container .aop-bb-step').each(function(i) {
                    $(this).find('.aop-bb-step-idx').text(i + 1);
                });
            }

            /* --- Sortable Steps --- */
            if ($.fn.sortable) {
                $('#aop-bb-steps-container').sortable({
                    handle: '.aop-bb-step-handle',
                    placeholder: 'aop-bb-step-placeholder',
                    update: function() {
                        renumberSteps();
                    }
                });
            }
        });
        ";
    }
}
