<?php
/**
 * License Manager — Plan-aware feature gating.
 *
 * Centralizes all plan checks so feature classes don't need
 * to know about Freemius internals. Supports three modes:
 *
 * 1. Freemius mode (default) — Real plan enforcement via Freemius SDK.
 * 2. Unlocked mode — All features available (used by the WooCommerce
 *    Marketplace build where licensing is handled externally).
 * 3. Stub mode — Used during development before SDK integration.
 *
 * The mode is determined by the `AOP_BB_LICENSE_MODE` constant,
 * which the build pipeline sets per target build:
 *
 * - 'freemius'  → WordPress.org and addoneplugins.com builds.
 * - 'unlocked'  → WooCommerce Marketplace build.
 * - 'stub'      → Default during development (no SDK loaded).
 *
 * Plan hierarchy: Free < Pro < Business
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_License_Manager
 *
 * Provides static helper methods for checking the active plan and
 * gating features accordingly.
 */
class AOP_BB_License_Manager {

    /**
     * Plan slug for the Pro plan.
     *
     * @var string
     */
    const PLAN_PRO = 'pro';

    /**
     * Plan slug for the Business plan.
     *
     * @var string
     */
    const PLAN_BUSINESS = 'business';

    /**
     * License mode: Freemius SDK loaded and gating active.
     *
     * @var string
     */
    const MODE_FREEMIUS = 'freemius';

    /**
     * License mode: All features unlocked (WC Marketplace build).
     *
     * @var string
     */
    const MODE_UNLOCKED = 'unlocked';

    /**
     * License mode: SDK not loaded, treat as free for gating.
     *
     * @var string
     */
    const MODE_STUB = 'stub';

    /**
     * Free plan limits.
     *
     * Used to enforce caps on the free tier (e.g. max bundles, max steps).
     *
     * @var array
     */
    const FREE_LIMITS = array(
        'max_bundles'         => 3,
        'max_steps_per_bundle' => 3,
    );

    /**
     * Get the current license mode.
     *
     * Returns the build-time constant, defaulting to stub for
     * development environments where the constant is not defined.
     *
     * @return string
     */
    public static function mode(): string {

        if ( defined( 'AOP_BB_LICENSE_MODE' ) ) {
            return constant( 'AOP_BB_LICENSE_MODE' );
        }

        return self::MODE_STUB;
    }

    /**
     * Check if the Freemius SDK helper function is available.
     *
     * @return bool
     */
    public static function has_sdk(): bool {

        return function_exists( 'bbfw_fs' );
    }

    /**
     * Get the Freemius instance, if available.
     *
     * @return \Freemius|null
     */
    public static function fs() {

        return self::has_sdk() ? bbfw_fs() : null;
    }

    /**
     * Check if the user is on a paying plan (Pro or higher).
     *
     * @return bool
     */
    public static function is_paying(): bool {

        if ( self::MODE_UNLOCKED === self::mode() ) {
            return true;
        }

        if ( ! self::has_sdk() ) {
            return false;
        }

        return bbfw_fs()->is_paying();
    }

    /**
     * Check if the user is on the free plan.
     *
     * @return bool
     */
    public static function is_free(): bool {

        if ( self::MODE_UNLOCKED === self::mode() ) {
            return false;
        }

        if ( ! self::has_sdk() ) {
            return true;
        }

        return ! bbfw_fs()->is_paying() && ! bbfw_fs()->is_trial();
    }

    /**
     * Check if the user has Pro plan or higher (Pro, Business, or trial).
     *
     * @return bool
     */
    public static function is_pro(): bool {

        if ( self::MODE_UNLOCKED === self::mode() ) {
            return true;
        }

        if ( ! self::has_sdk() ) {
            return false;
        }

        // Premium suffix and paying status are required.
        $fs = bbfw_fs();

        if ( ! $fs->is_premium() ) {
            return false;
        }

        return $fs->is_plan( self::PLAN_PRO, false ) || $fs->is_trial();
    }

    /**
     * Check if the user has the Business plan.
     *
     * @return bool
     */
    public static function is_business(): bool {

        if ( self::MODE_UNLOCKED === self::mode() ) {
            return true;
        }

        if ( ! self::has_sdk() ) {
            return false;
        }

        $fs = bbfw_fs();

        if ( ! $fs->is_premium() ) {
            return false;
        }

        return $fs->is_plan( self::PLAN_BUSINESS, true ) || $fs->is_trial();
    }

    /**
     * Check if the user is currently on a trial.
     *
     * @return bool
     */
    public static function is_trial(): bool {

        if ( ! self::has_sdk() ) {
            return false;
        }

        return bbfw_fs()->is_trial();
    }

    /**
     * Check if the user can use a specific feature.
     *
     * Centralizes the feature-to-plan mapping so gating logic
     * lives in one place. Add new features here, not scattered
     * across the codebase.
     *
     * @param string $feature Feature identifier.
     * @return bool
     */
    public static function can_use( string $feature ): bool {

        // Always-allowed features (Free plan).
        $free_features = array(
            'wizard_ui',
            'pricing_fixed',
            'pricing_sum',
            'category_steps',
            'card_style_bordered',
            'progress_pills',
            'progress_numbered',
            'progress_bar',
            'accent_color',
        );

        if ( in_array( $feature, $free_features, true ) ) {
            return true;
        }

        // Pro features.
        $pro_features = array(
            'unlimited_bundles',
            'unlimited_steps',
            'pricing_tiered',
            'handpicked_steps',
            'optional_steps',
            'min_max_qty',
            'card_style_shadow',
            'card_style_minimal',
        );

        if ( in_array( $feature, $pro_features, true ) ) {
            return self::is_pro();
        }

        // Business features.
        $business_features = array(
            'white_label',
            'bundle_templates',
            'bundle_import_export',
            'role_based_visibility',
            'custom_webhooks',
        );

        if ( in_array( $feature, $business_features, true ) ) {
            return self::is_business();
        }

        // Unknown feature — deny by default for safety.
        return false;
    }

    /**
     * Get the maximum number of bundles allowed for the current plan.
     *
     * @return int Returns 0 for unlimited.
     */
    public static function max_bundles(): int {

        if ( self::is_pro() ) {
            return 0; // Unlimited for Pro and above.
        }

        return self::FREE_LIMITS['max_bundles'];
    }

    /**
     * Get the maximum number of steps allowed per bundle.
     *
     * @return int Returns 0 for unlimited.
     */
    public static function max_steps_per_bundle(): int {

        if ( self::is_pro() ) {
            return 0; // Unlimited for Pro and above.
        }

        return self::FREE_LIMITS['max_steps_per_bundle'];
    }

    /**
     * Check if free user has reached the bundle limit.
     *
     * Counts only published bundle_builder products.
     *
     * @return bool
     */
    public static function bundle_limit_reached(): bool {

        $max = self::max_bundles();

        if ( 0 === $max ) {
            return false; // Unlimited.
        }

        $count = self::count_bundles();

        return $count >= $max;
    }

    /**
     * Count existing bundle_builder products.
     *
     * Cached for the request lifetime.
     *
     * @return int
     */
    public static function count_bundles(): int {

        static $count = null;

        if ( null !== $count ) {
            return $count;
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => 'bundle_builder',
                    ),
                ),
            )
        );

        $count = (int) $query->found_posts > 0 ? count( $query->posts ) : 0;

        return $count;
    }

    /**
     * Get the upgrade URL.
     *
     * Returns the Freemius pricing page URL when SDK is loaded,
     * otherwise returns the addoneplugins.com product URL.
     *
     * @return string
     */
    public static function get_upgrade_url(): string {

        if ( self::has_sdk() ) {
            return bbfw_fs()->get_upgrade_url();
        }

        return 'https://addoneplugins.com/product/bundlepilot/';
    }

    /**
     * Get the trial start URL.
     *
     * @return string
     */
    public static function get_trial_url(): string {

        if ( self::has_sdk() ) {
            return bbfw_fs()->get_trial_url();
        }

        return self::get_upgrade_url();
    }

    /**
     * Get the name of the current active plan.
     *
     * @return string 'free', 'pro', or 'business'
     */
    public static function get_plan_name(): string {

        if ( self::is_business() ) {
            return 'business';
        }

        if ( self::is_pro() ) {
            return 'pro';
        }

        return 'free';
    }

    /**
     * Get the minimum required plan name for a feature.
     *
     * Used in upgrade prompts to tell the user which plan they need.
     *
     * @param string $feature Feature identifier.
     * @return string 'pro' or 'business'
     */
    public static function required_plan_for( string $feature ): string {

        $business_features = array(
            'white_label',
            'bundle_templates',
            'bundle_import_export',
            'role_based_visibility',
            'custom_webhooks',
        );

        if ( in_array( $feature, $business_features, true ) ) {
            return 'business';
        }

        return 'pro';
    }

    /**
     * Render an inline plan badge (e.g. "PRO", "BUSINESS").
     *
     * Used in admin UI to flag features that require a specific plan.
     *
     * @param string $plan Plan slug ('pro' or 'business').
     * @return string Badge HTML.
     */
    public static function badge( string $plan = 'pro' ): string {

        $plan  = in_array( $plan, array( 'pro', 'business' ), true ) ? $plan : 'pro';
        $label = strtoupper( $plan );
        $class = 'aop-bb-plan-badge aop-bb-plan-badge--' . $plan;

        return sprintf(
            '<span class="%1$s">%2$s</span>',
            esc_attr( $class ),
            esc_html( $label )
        );
    }
}
