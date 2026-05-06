<?php
/**
 * White-Label Option (Business Feature)
 *
 * Renders a "Powered by BundlePilot" footer on the wizard by default.
 * Business plan users can hide it via the appearance settings.
 *
 * The footer is a small, dismissible attribution that doubles as
 * organic marketing — every wizard impression is a brand impression.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_White_Label
 */
class AOP_BB_White_Label {

    /**
     * Option key for the white-label setting.
     *
     * @var string
     */
    const OPTION_KEY = 'aop_bb_hide_branding';

    /**
     * Branded footer URL.
     *
     * @var string
     */
    const BRAND_URL = 'https://addoneplugins.com/product/bundlepilot/?utm_source=wizard&utm_medium=footer';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Render the "Powered by" footer after the wizard.
        add_action( 'aop_bb_after_builder_container', array( $this, 'render_footer' ), 10 );

        // Add the toggle to the settings page.
        add_filter( 'aop_bb_appearance_settings_fields', array( $this, 'add_settings_field' ) );
    }

    /**
     * Render the "Powered by BundlePilot" footer.
     *
     * Only suppressed when the Business plan is active AND the
     * "Hide branding" setting is enabled. Free and Pro users
     * always see the footer (organic marketing).
     *
     * @return void
     */
    public function render_footer(): void {

        if ( $this->should_hide_branding() ) {
            return;
        }

        printf(
            '<div class="aop-bb-powered-by">%s <a href="%s" target="_blank" rel="noopener">BundlePilot</a></div>',
            esc_html__( 'Powered by', 'bundlepilot' ),
            esc_url( self::BRAND_URL )
        );
    }

    /**
     * Determine whether to suppress the branding footer.
     *
     * @return bool
     */
    public function should_hide_branding(): bool {

        // Branding can only be hidden by Business plan customers.
        if ( ! AOP_BB_License_Manager::can_use( 'white_label' ) ) {
            return false;
        }

        return 'yes' === get_option( self::OPTION_KEY, 'no' );
    }

    /**
     * Add the white-label toggle to the appearance settings tab.
     *
     * The toggle is rendered for everyone via the custom
     * `aop_bb_gated_checkbox` field type so that:
     *
     * - Free / Pro users see the option but it's disabled and labelled
     *   with an inline "BUSINESS" badge plus an upgrade link.
     * - Business users see a normal, fully-functional checkbox.
     *
     * @param array $fields Existing settings fields.
     * @return array Modified fields.
     */
    public function add_settings_field( array $fields ): array {

        $is_business = AOP_BB_License_Manager::can_use( 'white_label' );

        $fields[] = array(
            'type'  => 'title',
            'title' => __( 'White Label', 'bundlepilot' ),
            'id'    => 'aop_bb_white_label_section',
        );

        $fields[] = array(
            'type'          => 'aop_bb_gated_checkbox',
            'id'            => self::OPTION_KEY,
            'title'         => __( 'Hide BundlePilot Branding', 'bundlepilot' ),
            'desc'          => __( 'Remove the "Powered by BundlePilot" footer from the wizard.', 'bundlepilot' ),
            'default'       => 'no',
            'locked'        => ! $is_business,
            'required_plan' => 'business',
            'upgrade_text'  => __( 'Upgrade to Business to unlock', 'bundlepilot' ),
        );

        $fields[] = array(
            'type' => 'sectionend',
            'id'   => 'aop_bb_white_label_section',
        );

        return $fields;
    }
}
