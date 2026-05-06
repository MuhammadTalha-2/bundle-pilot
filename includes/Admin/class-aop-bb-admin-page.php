<?php
/**
 * BundlePilot Admin Settings Page
 *
 * Renders the main BundlePilot settings as a standalone admin page
 * nested under the WooCommerce → BundlePilot menu (created by the
 * Freemius SDK with `parent.slug = 'woocommerce'`).
 *
 * This replaces the old WooCommerce → Settings → Bundle Builder tab.
 * It re-uses {@see AOP_BB_Settings_Page::get_fields_for_section()}
 * so field definitions remain in one place — only the chrome
 * (header, tabs, save flow) lives here.
 *
 * Section navigation is implemented as in-page tabs (?section=…).
 * Saving is handled by WooCommerce's WC_Admin_Settings::save_fields()
 * to keep behaviour identical to the prior WC tab implementation.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Admin_Page
 */
class AOP_BB_Admin_Page {

    /**
     * Top-level menu slug.
     *
     * Matches the Freemius menu.slug so `admin.php?page=bundlepilot`
     * always resolves here — both as the default landing for the
     * top-level menu and as the URL Freemius redirects to after opt-in.
     *
     * BundlePilot is a TOP-LEVEL menu (registered via add_menu_page)
     * rather than a submenu of WooCommerce, because WordPress only
     * renders two menu levels — top-level + direct submenus. To group
     * Settings, Templates, Import/Export, Webhooks etc. under a single
     * navigable parent, that parent has to be top-level.
     *
     * @var string
     */
    const MENU_SLUG = 'bundlepilot';

    /**
     * Menu position.
     *
     * 56 places it directly after WooCommerce (which is at 55), so the
     * plugin reads as a WC extension while still owning its menu hierarchy.
     *
     * @var int
     */
    const MENU_POSITION = 56;

    /**
     * Custom SVG icon for the BundlePilot top-level menu item.
     *
     * Base64-encoded inline SVG — gives a crisp, premium-looking icon
     * that inherits the WP admin colour scheme automatically and ships
     * with no external file dependency. The shape is an isometric
     * package/bundle: instantly readable as "container of items"
     * even at the admin menu's ~20px render size.
     *
     * Edit the SVG markup in {@see self::get_menu_icon_uri()} if you
     * ever want to change the look.
     *
     * @return string Data URI suitable for add_menu_page().
     */
    public static function get_menu_icon_uri(): string {

        // viewBox 0 0 24 24, fill "black" — WP admin overrides the
        // colour at runtime to match the menu state (default / active).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="black">'
             . '<path d="M12 2L3 6.5v11L12 22l9-4.5v-11L12 2zm0 2.236L18.236 7.5 12 10.764 5.764 7.5 12 4.236zM5 9.118l6 3v6.764l-6-3V9.118zm14 0v6.764l-6 3v-6.764l6-3z"/>'
             . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    /**
     * Nonce key for the save handler.
     *
     * @var string
     */
    const NONCE = 'bundlepilot_save_settings';

    /**
     * Section definitions: slug => label.
     *
     * Mirrors the WC tab sections from the previous implementation.
     *
     * @return array
     */
    protected function get_sections(): array {

        return array(
            ''           => __( 'General', 'bundlepilot' ),
            'appearance' => __( 'Appearance', 'bundlepilot' ),
            'emails'     => __( 'Orders & Emails', 'bundlepilot' ),
            'advanced'   => __( 'Advanced', 'bundlepilot' ),
        );
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Priority 9: parent menu must exist BEFORE Business feature
        // classes try to attach their submenus at priority 30.
        add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
        add_action( 'admin_init', array( $this, 'maybe_save' ) );

        // Custom WC settings field types used by Business feature classes.
        add_action( 'woocommerce_admin_field_aop_bb_upgrade_notice', array( $this, 'render_upgrade_notice_field' ) );
        add_action( 'woocommerce_admin_field_aop_bb_gated_checkbox', array( $this, 'render_gated_checkbox_field' ) );
        add_action( 'woocommerce_admin_field_aop_bb_gated_select', array( $this, 'render_gated_select_field' ) );

        // Plan-gate the Card Style option. Even if a user manipulates the
        // dropdown via DevTools and submits "shadow" or "minimal", the
        // pre_update_option filter rewrites it back to "bordered" and queues
        // an admin notice explaining why.
        add_filter( 'pre_update_option_aop_bb_card_style', array( $this, 'enforce_card_style_plan' ), 10, 2 );
    }

    /**
     * Server-side gate for the Card Style setting.
     *
     * If the user submits a Pro-only style ("shadow" or "minimal") while
     * on the Free plan, force the value back to "bordered" and queue a
     * notice to be shown on the next page load.
     *
     * @param mixed $new_value Submitted value.
     * @param mixed $old_value Currently saved value.
     * @return mixed
     */
    public function enforce_card_style_plan( $new_value, $old_value ) {

        if ( in_array( $new_value, array( 'shadow', 'minimal' ), true )
            && ! AOP_BB_License_Manager::can_use( 'card_style_shadow' )
        ) {
            AOP_BB_Product_Data::queue_plan_notice(
                array(
                    __( 'Shadow and Minimal card styles require the Pro plan. Card style was reset to Bordered.', 'bundlepilot' ),
                )
            );
            return 'bordered';
        }

        return $new_value;
    }

    /**
     * Render a "gated" select — a normal-looking dropdown where specific
     * <option> values are disabled because the active plan can't use them.
     *
     * Field args:
     *   id            string  Required. Option key.
     *   title         string  Required. Field label.
     *   desc          string  Optional. Description below the field.
     *   default       string  Optional. Default value.
     *   options       array   Required. value => label.
     *   locked        array   Required. Array of option values that are locked.
     *   required_plan string  Optional. 'pro' or 'business'. Defaults to 'pro'.
     *
     * @param array $value Field args.
     * @return void
     */
    public function render_gated_select_field( $value ): void {

        $id            = $value['id'] ?? '';
        $title         = $value['title'] ?? '';
        $description   = $value['desc'] ?? '';
        $default       = $value['default'] ?? '';
        $options       = isset( $value['options'] ) && is_array( $value['options'] ) ? $value['options'] : array();
        $locked        = isset( $value['locked'] ) && is_array( $value['locked'] ) ? $value['locked'] : array();
        $required_plan = isset( $value['required_plan'] ) && 'business' === $value['required_plan'] ? 'business' : 'pro';
        $option_value  = get_option( $id, $default );

        // If the saved value is locked (e.g. user downgraded), display the
        // default instead so the dropdown reads consistently with what the
        // server will enforce on the next save.
        if ( in_array( $option_value, $locked, true ) ) {
            $option_value = $default;
        }

        $upsell_label = 'business' === $required_plan
            ? __( 'Upgrade to Business to unlock more options', 'bundlepilot' )
            : __( 'Upgrade to Pro to unlock more options', 'bundlepilot' );

        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $id ); ?>">
                    <?php echo esc_html( $title ); ?>
                </label>
            </th>
            <td class="forminp forminp-select">
                <select name="<?php echo esc_attr( $id ); ?>"
                        id="<?php echo esc_attr( $id ); ?>"
                        class="select short">
                    <?php foreach ( $options as $key => $label ) :
                        $is_locked = in_array( $key, $locked, true );
                        $suffix    = $is_locked ? ' (' . strtoupper( $required_plan ) . ')' : '';
                        ?>
                        <option value="<?php echo esc_attr( $key ); ?>"
                                <?php selected( $option_value, $key ); ?>
                                <?php disabled( $is_locked ); ?>>
                            <?php echo esc_html( $label . $suffix ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $description ) : ?>
                    <p class="description"><?php echo wp_kses_post( $description ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $locked ) ) : ?>
                    <p class="description aop-bb-feature-upsell">
                        <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                           target="_blank" rel="noopener">
                            <?php echo esc_html( $upsell_label ); ?> →
                        </a>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render a "gated" checkbox — a normal-looking checkbox that is
     * disabled when the customer's plan does not include the feature,
     * with an inline plan badge and an upgrade link below.
     *
     * Field args:
     *   id            string  Required. Option key.
     *   title         string  Required. Field label.
     *   desc          string  Optional. Inline description (HTML allowed).
     *   default       string  Optional. 'yes' or 'no'. Defaults to 'no'.
     *   locked        bool    Required. True when the current plan can't use it.
     *   required_plan string  Optional. 'pro' or 'business'. Defaults to 'business'.
     *   upgrade_text  string  Optional. Text for the upgrade link.
     *
     * @param array $value Field args.
     * @return void
     */
    public function render_gated_checkbox_field( $value ): void {

        $id            = $value['id'] ?? '';
        $title         = $value['title'] ?? '';
        $description   = $value['desc'] ?? '';
        $default       = $value['default'] ?? 'no';
        $locked        = ! empty( $value['locked'] );
        $required_plan = isset( $value['required_plan'] ) && 'pro' === $value['required_plan'] ? 'pro' : 'business';
        $upgrade_text  = $value['upgrade_text'] ?? __( 'Upgrade to unlock', 'bundlepilot' );

        $option_value = $locked ? $default : get_option( $id, $default );
        $row_class    = $locked ? 'aop-bb-gated-row aop-bb-gated-row--locked' : 'aop-bb-gated-row';

        ?>
        <tr valign="top" class="<?php echo esc_attr( $row_class ); ?>">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $id ); ?>">
                    <?php echo esc_html( $title ); ?>
                    <?php if ( $locked ) : ?>
                        <span class="aop-bb-plan-badge aop-bb-plan-badge--<?php echo esc_attr( $required_plan ); ?>">
                            <?php echo esc_html( strtoupper( $required_plan ) ); ?>
                        </span>
                    <?php endif; ?>
                </label>
            </th>
            <td class="forminp forminp-checkbox">
                <fieldset>
                    <label for="<?php echo esc_attr( $id ); ?>">
                        <input
                            name="<?php echo esc_attr( $id ); ?>"
                            id="<?php echo esc_attr( $id ); ?>"
                            type="checkbox"
                            value="yes"
                            <?php checked( $option_value, 'yes' ); ?>
                            <?php disabled( $locked ); ?>
                        />
                        <?php echo wp_kses_post( $description ); ?>
                    </label>
                    <?php if ( $locked ) : ?>
                        <p class="description aop-bb-gated-row__upgrade">
                            <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                               target="_blank" rel="noopener">
                                <?php echo esc_html( $upgrade_text ); ?> →
                            </a>
                        </p>
                    <?php endif; ?>
                </fieldset>
            </td>
        </tr>
        <?php
    }

    /**
     * Render the custom "upgrade notice" pseudo field type.
     *
     * Used by Business feature classes to inject upgrade CTAs into
     * settings sections via the {@see WC_Admin_Settings::output_fields()} pipeline.
     *
     * @param array $value Field args.
     * @return void
     */
    public function render_upgrade_notice_field( $value ): void {

        $plan        = isset( $value['plan'] ) && 'business' === $value['plan'] ? 'business' : 'pro';
        $title       = isset( $value['title'] ) ? $value['title'] : '';
        $description = isset( $value['desc'] ) ? $value['desc'] : '';
        $upgrade_url = AOP_BB_License_Manager::get_upgrade_url();

        $modifier = 'business' === $plan ? ' aop-bb-upgrade-notice--business' : '';

        ?>
        <tr valign="top">
            <td colspan="2" style="padding: 10px 0;">
                <div class="aop-bb-upgrade-notice<?php echo esc_attr( $modifier ); ?>">
                    <?php if ( $title ) : ?>
                        <h4 class="aop-bb-upgrade-notice__title"><?php echo esc_html( $title ); ?></h4>
                    <?php endif; ?>
                    <?php if ( $description ) : ?>
                        <p class="aop-bb-upgrade-notice__description"><?php echo esc_html( $description ); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>"
                       class="aop-bb-upgrade-notice__cta"
                       target="_blank" rel="noopener">
                        <?php
                        echo esc_html(
                            'business' === $plan
                                ? __( 'Upgrade to Business', 'bundlepilot' )
                                : __( 'Upgrade to Pro', 'bundlepilot' )
                        );
                        ?>
                    </a>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Register the BundlePilot top-level menu and its first submenu.
     *
     * The top-level item's default landing page is the Settings screen
     * (we use add_submenu_page with the same slug to override the
     * auto-generated submenu label so it reads "Settings", not the
     * top-level "BundlePilot").
     *
     * Priority 9 (BEFORE the standard 10) ensures the parent menu
     * exists before Freemius and our other Business feature classes
     * register their submenus on the same slug at priority 30.
     *
     * @return void
     */
    public function register_menu(): void {

        // Top-level menu item.
        add_menu_page(
            __( 'BundlePilot Settings', 'bundlepilot' ),
            __( 'BundlePilot', 'bundlepilot' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_page' ),
            self::get_menu_icon_uri(),
            self::MENU_POSITION
        );

        // Override the auto-generated first submenu so it reads "Settings".
        add_submenu_page(
            self::MENU_SLUG,
            __( 'BundlePilot Settings', 'bundlepilot' ),
            __( 'Settings', 'bundlepilot' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Save handler. Runs early so any redirects/notices land properly.
     *
     * @return void
     */
    public function maybe_save(): void {

        // Only act on POST submissions to our page.
        if ( empty( $_POST['_bundlepilot_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        check_admin_referer( self::NONCE );

        if ( ! class_exists( 'WC_Admin_Settings' ) ) {
            return;
        }

        $section = isset( $_POST['_bundlepilot_section'] ) ? sanitize_key( wp_unslash( $_POST['_bundlepilot_section'] ) ) : '';
        $fields  = AOP_BB_Settings_Page::get_fields_for_section( $section );

        // WC_Admin_Settings::save_fields reads from $_POST and writes to options
        // using each field's `id`. It mirrors what WC's own settings tabs do.
        WC_Admin_Settings::save_fields( $fields );

        // Redirect back to the same section to clear the POST.
        $redirect = add_query_arg(
            array(
                'page'             => self::MENU_SLUG,
                'section'          => $section,
                'settings-updated' => 'true',
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render_page(): void {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'bundlepilot' ), 403 );
        }

        if ( ! class_exists( 'WC_Admin_Settings' ) ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'BundlePilot', 'bundlepilot' ) . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is required to render BundlePilot settings.', 'bundlepilot' ) . '</p></div></div>';
            return;
        }

        $sections        = $this->get_sections();
        $current_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_section = array_key_exists( $current_section, $sections ) ? $current_section : '';
        $fields          = AOP_BB_Settings_Page::get_fields_for_section( $current_section );

        ?>
        <div class="wrap bundlepilot-settings-wrap">

            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'BundlePilot Settings', 'bundlepilot' ); ?>
            </h1>

            <hr class="wp-header-end" />

            <?php $this->render_section_nav( $sections, $current_section ); ?>

            <?php if ( ! empty( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Settings saved.', 'bundlepilot' ); ?></strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="_bundlepilot_save" value="1" />
                <input type="hidden" name="_bundlepilot_section" value="<?php echo esc_attr( $current_section ); ?>" />

                <?php
                // Re-use WC's renderer for full parity with native WC settings.
                WC_Admin_Settings::output_fields( $fields );
                submit_button( __( 'Save Changes', 'bundlepilot' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the in-page section nav (looks like WP nav-tab-wrapper).
     *
     * @param array  $sections        Section slug => label.
     * @param string $current_section Current section slug.
     * @return void
     */
    protected function render_section_nav( array $sections, string $current_section ): void {

        echo '<h2 class="nav-tab-wrapper" style="margin-top: 20px;">';

        foreach ( $sections as $slug => $label ) {

            $url = add_query_arg(
                array_filter(
                    array(
                        'page'    => self::MENU_SLUG,
                        'section' => $slug,
                    )
                ),
                admin_url( 'admin.php' )
            );

            $class = ( $current_section === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';

            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_html( $label )
            );
        }

        echo '</h2>';
    }
}
