<?php
/**
 * Bundle Templates Library (Business Feature)
 *
 * Renders an admin page that lists pre-built bundle templates and
 * lets a Business plan user instantiate one with a single click.
 *
 * The page lives under Products → Bundle Templates so it's
 * discoverable but non-intrusive. Free and Pro users see a
 * locked preview with an upgrade prompt.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Templates
 */
class AOP_BB_Templates {

    /**
     * Admin menu slug.
     *
     * @var string
     */
    const MENU_SLUG = 'aop-bb-templates';

    /**
     * Action slug for the create handler.
     *
     * @var string
     */
    const ACTION = 'aop_bb_create_from_template';

    /**
     * Nonce action key.
     *
     * @var string
     */
    const NONCE_ACTION = 'aop_bb_create_from_template';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Priority 30: ensures Freemius (priority 10/20) has registered the
        // BundlePilot parent menu before we attach this submenu to it.
        add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
        add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_create' ) );
        add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
    }

    /**
     * Add the Templates submenu under WooCommerce → BundlePilot.
     *
     * @return void
     */
    public function register_menu(): void {

        add_submenu_page(
            'bundlepilot',
            __( 'Bundle Templates', 'bundlepilot' ),
            __( 'Templates', 'bundlepilot' ),
            'edit_products',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the templates library page.
     *
     * @return void
     */
    public function render_page(): void {

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'bundlepilot' ), 403 );
        }

        $can_use   = AOP_BB_License_Manager::can_use( 'bundle_templates' );
        $templates = AOP_BB_Template_Registry::all();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Bundle Templates', 'bundlepilot' ); ?>
                <?php
                if ( ! $can_use ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() returns escaped HTML.
                    echo AOP_BB_License_Manager::badge( 'business' );
                }
                ?>
            </h1>

            <p class="description" style="max-width: 720px;">
                <?php esc_html_e( 'Choose a starting template to skip the blank-slate setup. Each template creates a draft bundle with steps and pricing pre-configured — you just need to add your products.', 'bundlepilot' ); ?>
            </p>

            <?php if ( ! $can_use ) : ?>
                <div class="aop-bb-upgrade-notice aop-bb-upgrade-notice--business" style="max-width: 720px; margin-top: 16px;">
                    <h4 class="aop-bb-upgrade-notice__title">
                        <?php esc_html_e( 'Bundle Templates is a Business feature', 'bundlepilot' ); ?>
                    </h4>
                    <p class="aop-bb-upgrade-notice__description">
                        <?php esc_html_e( 'Upgrade to Business to create bundles from any of these pre-built templates with one click. You can preview the structure below.', 'bundlepilot' ); ?>
                    </p>
                    <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                       class="aop-bb-upgrade-notice__cta"
                       target="_blank" rel="noopener">
                        <?php esc_html_e( 'Upgrade to Business', 'bundlepilot' ); ?>
                    </a>
                </div>
            <?php endif; ?>

            <div class="aop-bb-templates-grid">
                <?php foreach ( $templates as $slug => $template ) : ?>
                    <?php $this->render_template_card( $slug, $template, $can_use ); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single template card.
     *
     * @param string $slug     Template slug.
     * @param array  $template Template definition.
     * @param bool   $can_use  Whether the current user can instantiate templates.
     * @return void
     */
    protected function render_template_card( string $slug, array $template, bool $can_use ): void {

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => self::ACTION,
                    'template' => $slug,
                ),
                admin_url( 'admin-post.php' )
            ),
            self::NONCE_ACTION
        );

        ?>
        <div class="aop-bb-template-card">
            <span class="aop-bb-template-card__icon" aria-hidden="true">
                <?php echo esc_html( $template['icon'] ?? '📦' ); ?>
            </span>
            <h3 class="aop-bb-template-card__title">
                <?php echo esc_html( $template['title'] ); ?>
            </h3>
            <p class="aop-bb-template-card__description">
                <?php echo esc_html( $template['description'] ); ?>
            </p>

            <?php if ( ! empty( $template['tags'] ) ) : ?>
                <div class="aop-bb-template-card__meta">
                    <?php foreach ( $template['tags'] as $tag ) : ?>
                        <span class="aop-bb-template-card__meta-pill">
                            <?php echo esc_html( $tag ); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="aop-bb-template-card__action">
                <?php if ( $can_use ) : ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Use This Template', 'bundlepilot' ); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                       class="button"
                       target="_blank" rel="noopener">
                        <?php esc_html_e( 'Unlock with Business', 'bundlepilot' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle the "Create from template" request.
     *
     * @return void
     */
    public function handle_create(): void {

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( esc_html__( 'You do not have permission to create bundles.', 'bundlepilot' ), 403 );
        }

        check_admin_referer( self::NONCE_ACTION );

        $slug = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';

        if ( empty( $slug ) ) {
            $this->redirect_with_error( __( 'Missing template identifier.', 'bundlepilot' ) );
        }

        $template = AOP_BB_Template_Registry::get( $slug );

        if ( ! $template ) {
            $this->redirect_with_error( __( 'Template not found.', 'bundlepilot' ) );
        }

        if ( ! AOP_BB_License_Manager::can_use( 'bundle_templates' ) ) {
            $this->redirect_with_error( __( 'Bundle Templates require the Business plan.', 'bundlepilot' ) );
        }

        $product_data = $template['product'] ?? array();
        $meta         = $template['meta'] ?? array();

        $new_id = $this->create_bundle_from_template( $product_data, $meta );

        if ( is_wp_error( $new_id ) ) {
            $this->redirect_with_error( $new_id->get_error_message() );
        }

        // Redirect to the new bundle's edit screen.
        wp_safe_redirect(
            add_query_arg(
                array(
                    'post'                  => $new_id,
                    'action'                => 'edit',
                    'aop_bb_template_used'  => '1',
                ),
                admin_url( 'post.php' )
            )
        );
        exit;
    }

    /**
     * Create a new bundle product from a template.
     *
     * @param array $product_data Post data overrides.
     * @param array $meta         Post meta to set on the new bundle.
     * @return int|WP_Error
     */
    protected function create_bundle_from_template( array $product_data, array $meta ) {

        $defaults = array(
            'post_type'   => 'product',
            'post_status' => 'draft',
            'post_title'  => __( 'Untitled Bundle', 'bundlepilot' ),
            'post_author' => get_current_user_id(),
        );

        $post_data = wp_parse_args( $product_data, $defaults );

        $new_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        // Set the product type taxonomy term.
        wp_set_object_terms( $new_id, 'bundle_builder', 'product_type' );

        // Apply template meta.
        foreach ( $meta as $meta_key => $meta_value ) {
            update_post_meta( $new_id, $meta_key, $meta_value );
        }

        /**
         * Fires after a bundle has been created from a template.
         *
         * @param int    $new_id   New bundle ID.
         * @param string $template Template slug (passed via $product_data context).
         */
        do_action( 'aop_bb_bundle_created_from_template', $new_id, $product_data['_template_slug'] ?? '' );

        return $new_id;
    }

    /**
     * Redirect to the templates page with an error.
     *
     * @param string $message Error message.
     * @return void
     */
    protected function redirect_with_error( string $message ): void {

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                  => self::MENU_SLUG,
                    'aop_bb_template_error' => rawurlencode( $message ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Render notices on the templates page or new bundle edit screen.
     *
     * @return void
     */
    public function maybe_render_notice(): void {

        // Success notice on the new bundle edit screen.
        if ( ! empty( $_GET['aop_bb_template_used'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Bundle created from template.', 'bundlepilot' ); ?></strong>
                    <?php esc_html_e( 'Add your products to each step and publish when ready.', 'bundlepilot' ); ?>
                </p>
            </div>
            <?php
            return;
        }

        // Error notice on the templates page.
        if ( ! empty( $_GET['aop_bb_template_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['aop_bb_template_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }
}
