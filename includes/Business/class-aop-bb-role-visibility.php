<?php
/**
 * Role-Based Visibility (Business Feature)
 *
 * Allows bundle products to be restricted to specific user roles.
 * Useful for B2B / wholesale stores that want to expose certain
 * bundles only to logged-in wholesale customers, members, etc.
 *
 * Storage:
 * - Per-bundle meta key `_aop_bb_visible_roles` stores either:
 *   - An empty array / 'everyone' → visible to everyone (default).
 *   - An array of role slugs      → visible only to those roles
 *     (plus administrators, who always see everything).
 *
 * Enforcement:
 * - Hides bundles from product queries (shop, search, taxonomy, REST).
 * - Returns a friendly notice if a logged-out user tries to access
 *   a restricted bundle directly via URL.
 * - Allows admins to always view restricted bundles for moderation.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Role_Visibility
 */
class AOP_BB_Role_Visibility {

    /**
     * Meta key storing the visible roles for a bundle.
     *
     * @var string
     */
    const META_KEY = '_aop_bb_visible_roles';

    /**
     * Sentinel value indicating no restriction.
     *
     * @var string
     */
    const VALUE_EVERYONE = 'everyone';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Add the per-bundle setting to the product data panel.
        add_action( 'aop_bb_product_data_panel_after_steps', array( $this, 'render_visibility_field' ) );

        // Save the setting on product save.
        add_action( 'woocommerce_process_product_meta_bundle_builder', array( $this, 'save_visibility_field' ) );

        // Filter out hidden bundles from product queries (shop/search).
        add_action( 'pre_get_posts', array( $this, 'filter_product_queries' ) );

        // Block direct access to restricted bundles.
        add_action( 'template_redirect', array( $this, 'maybe_block_direct_access' ) );

        // Filter REST API responses (Store API products endpoint).
        add_filter( 'woocommerce_rest_product_object_query', array( $this, 'filter_rest_query' ), 10, 2 );
    }

    /* ------------------------------------------------------------------
     |  Admin UI
     | ------------------------------------------------------------------*/

    /**
     * Render the visibility field in the bundle product data panel.
     *
     * @return void
     */
    public function render_visibility_field(): void {

        global $post;

        if ( ! $post ) {
            return;
        }

        $can_use   = AOP_BB_License_Manager::can_use( 'role_based_visibility' );
        $current   = $this->get_visible_roles( $post->ID );
        $all_roles = $this->get_assignable_roles();

        // UX: a single checklist of roles. Empty = "visible to everyone";
        // any check = "restricted to selected roles" (administrators always
        // have access regardless). Removing the mode dropdown eliminates a
        // step from the workflow and avoids the rendering issues caused
        // by nested labels inside WooCommerce's options_group panel.
        $group_class = 'options_group aop-bb-role-visibility';
        if ( ! $can_use ) {
            $group_class .= ' aop-bb-role-visibility--locked';
        }
        ?>
        <div class="<?php echo esc_attr( $group_class ); ?>">

            <h4 class="aop-bb-section-title">
                <?php esc_html_e( 'Visibility', 'bundlepilot' ); ?>
                <?php
                if ( ! $can_use ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() returns escaped HTML.
                    echo AOP_BB_License_Manager::badge( 'business' );
                }
                ?>
            </h4>

            <div class="aop-bb-role-visibility__body">
                <p class="aop-bb-role-visibility__intro">
                    <?php esc_html_e( 'Check specific roles to restrict who can see and purchase this bundle. Leave all unchecked to make it visible to everyone.', 'bundlepilot' ); ?>
                </p>

                <?php if ( empty( $all_roles ) ) : ?>
                    <p class="aop-bb-role-visibility__empty">
                        <em><?php esc_html_e( 'No assignable user roles found on this site.', 'bundlepilot' ); ?></em>
                    </p>
                <?php else : ?>
                    <ul class="aop-bb-role-visibility__list">
                        <?php
                        // Inline overrides: WooCommerce's options_group panel CSS
                        // ( `.woocommerce_options_panel label { float: left;
                        //   width: 150px; margin: 4px 0 0 -150px; }` )
                        // floats every nested label off-screen. We force-reset
                        // those styles inline so the role name reliably renders
                        // next to its checkbox regardless of stylesheet load order.
                        $label_inline_style = 'float:none !important;width:auto !important;margin:0 !important;padding:0 !important;display:inline !important;cursor:pointer;font-weight:normal;color:#1e293b;line-height:1.4;';
                        ?>
                        <?php foreach ( $all_roles as $role_key => $role_label ) : ?>
                            <?php $input_id = 'aop_bb_role_' . sanitize_key( $role_key ); ?>
                            <li class="aop-bb-role-visibility__item">
                                <input type="checkbox"
                                       id="<?php echo esc_attr( $input_id ); ?>"
                                       name="aop_bb_visible_roles[]"
                                       value="<?php echo esc_attr( $role_key ); ?>"
                                       <?php checked( in_array( $role_key, $current, true ) ); ?>
                                       <?php disabled( ! $can_use ); ?> />
                                <label for="<?php echo esc_attr( $input_id ); ?>"
                                       style="<?php echo esc_attr( $label_inline_style ); ?>">
                                    <?php echo esc_html( $role_label ); ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="aop-bb-role-visibility__footnote">
                    <?php esc_html_e( 'Administrators always have access regardless of selection.', 'bundlepilot' ); ?>
                </p>
            </div>

            <?php if ( ! $can_use ) : ?>
                <p class="aop-bb-role-visibility__upgrade">
                    <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                       target="_blank" rel="noopener">
                        <?php esc_html_e( 'Upgrade to Business to unlock role-based visibility', 'bundlepilot' ); ?> →
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save the visibility field on product save.
     *
     * @param int $product_id Product ID.
     * @return void
     */
    public function save_visibility_field( int $product_id ): void {

        // Capability check.
        if ( ! current_user_can( 'edit_product', $product_id ) ) {
            return;
        }

        // Plan check (server-side enforcement).
        if ( ! AOP_BB_License_Manager::can_use( 'role_based_visibility' ) ) {
            return;
        }

        // Note: nonce verification is handled by WooCommerce
        // before `woocommerce_process_product_meta` fires.
        //
        // The UI is now a single checklist (no mode dropdown):
        //   - No checkboxes ticked → meta deleted → bundle visible to everyone.
        //   - One or more ticked   → meta saved   → bundle restricted to those roles.

        $submitted = isset( $_POST['aop_bb_visible_roles'] ) ? (array) wp_unslash( $_POST['aop_bb_visible_roles'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $assignable = array_keys( $this->get_assignable_roles() );
        $sanitized  = array();

        foreach ( $submitted as $role ) {
            $role = sanitize_key( $role );
            if ( in_array( $role, $assignable, true ) ) {
                $sanitized[] = $role;
            }
        }

        $sanitized = array_values( array_unique( $sanitized ) );

        if ( empty( $sanitized ) ) {
            delete_post_meta( $product_id, self::META_KEY );
            return;
        }

        update_post_meta( $product_id, self::META_KEY, $sanitized );
    }

    /* ------------------------------------------------------------------
     |  Visibility Enforcement
     | ------------------------------------------------------------------*/

    /**
     * Hide restricted bundles from public product queries.
     *
     * @param WP_Query $query The query object.
     * @return void
     */
    public function filter_product_queries( WP_Query $query ): void {

        // Skip in admin / single product views — those are handled separately.
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // Only on product-related queries.
        if ( ! is_shop() && ! is_product_taxonomy() && ! is_search() ) {
            return;
        }

        $excluded = $this->get_excluded_bundle_ids();

        if ( empty( $excluded ) ) {
            return;
        }

        $existing = (array) $query->get( 'post__not_in' );
        $query->set( 'post__not_in', array_merge( $existing, $excluded ) );
    }

    /**
     * Block direct URL access to restricted bundles.
     *
     * @return void
     */
    public function maybe_block_direct_access(): void {

        if ( ! is_singular( 'product' ) ) {
            return;
        }

        global $post;

        if ( ! $post ) {
            return;
        }

        $product = wc_get_product( $post->ID );

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return;
        }

        if ( $this->user_can_view_bundle( $post->ID ) ) {
            return;
        }

        // 404 the bundle so it's indistinguishable from non-existent.
        global $wp_query;

        $wp_query->set_404();
        status_header( 404 );

        // Render the theme's 404 template.
        $template = get_404_template();

        if ( $template ) {
            include $template;
            exit;
        }
    }

    /**
     * Filter the WooCommerce REST products query to exclude restricted bundles.
     *
     * @param array           $args    Query args.
     * @param WP_REST_Request $request REST request object.
     * @return array Modified query args.
     */
    public function filter_rest_query( array $args, $request ): array {

        $excluded = $this->get_excluded_bundle_ids();

        if ( empty( $excluded ) ) {
            return $args;
        }

        $existing = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
        $args['post__not_in'] = array_merge( $existing, $excluded );

        return $args;
    }

    /**
     * Check whether the current user can view a specific bundle.
     *
     * @param int $bundle_id Bundle product ID.
     * @return bool
     */
    public function user_can_view_bundle( int $bundle_id ): bool {

        $allowed_roles = $this->get_visible_roles( $bundle_id );

        // No restriction set — everyone can view.
        if ( empty( $allowed_roles ) ) {
            return true;
        }

        // Administrators always see everything.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Logged-out users can never see role-restricted bundles.
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();

        if ( empty( $user->roles ) ) {
            return false;
        }

        return ! empty( array_intersect( (array) $user->roles, $allowed_roles ) );
    }

    /**
     * Get a list of bundle IDs the current user cannot view.
     *
     * Used to filter them out of product queries efficiently.
     * Cached per-request.
     *
     * @return int[]
     */
    protected function get_excluded_bundle_ids(): array {

        static $cache = null;

        if ( null !== $cache ) {
            return $cache;
        }

        // Admins see everything — no exclusions needed.
        if ( current_user_can( 'manage_options' ) ) {
            $cache = array();
            return $cache;
        }

        // Find all bundles that have any role restriction set.
        $query = new WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => self::META_KEY,
                        'compare' => 'EXISTS',
                    ),
                ),
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => 'bundle_builder',
                    ),
                ),
            )
        );

        $excluded = array();

        foreach ( $query->posts as $bundle_id ) {
            if ( ! $this->user_can_view_bundle( (int) $bundle_id ) ) {
                $excluded[] = (int) $bundle_id;
            }
        }

        $cache = $excluded;

        return $cache;
    }

    /* ------------------------------------------------------------------
     |  Helpers
     | ------------------------------------------------------------------*/

    /**
     * Get the visible roles configured for a bundle.
     *
     * @param int $bundle_id Bundle product ID.
     * @return string[] Empty array means "everyone".
     */
    public function get_visible_roles( int $bundle_id ): array {

        $value = get_post_meta( $bundle_id, self::META_KEY, true );

        if ( ! is_array( $value ) ) {
            return array();
        }

        return array_filter( $value );
    }

    /**
     * Get the list of roles assignable to bundles.
     *
     * Excludes administrator (always allowed) and super_admin
     * (multisite-only, also always allowed).
     *
     * @return array Role slug => Role display name.
     */
    public function get_assignable_roles(): array {

        $roles = wp_roles()->get_names();

        // Filter out administrator.
        unset( $roles['administrator'] );

        /**
         * Filter the assignable roles list.
         *
         * @param array $roles Role slug => display name.
         */
        return apply_filters( 'aop_bb_role_visibility_assignable_roles', $roles );
    }
}
