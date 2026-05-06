<?php
/**
 * Plugin Name: BundlePilot
 * Plugin URI:  https://addoneplugins.com/product/bundlepilot/
 * Description: Step-by-step product bundle wizard for WooCommerce. Guide customers through curated bundles with fixed, summed, or tiered-discount pricing.
 * Version:     1.0.0
 * Author:      Add One Plugins
 * Author URI:  https://addoneplugins.com/
 * Developer:   Add One Plugins
 * Developer URI: https://addoneplugins.com/
 * Requires at least: 6.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 10.5.2
 * Requires PHP: 7.4
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bundlepilot
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================================
 * Plugin Constants
 * ========================================================================= */

define( 'AOP_BB_VERSION', '1.0.0' );
define( 'AOP_BB_PLUGIN_FILE', __FILE__ );
define( 'AOP_BB_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'AOP_BB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AOP_BB_REQUIRED_WC_VERSION', '6.0' );

/* =========================================================================
 * Freemius SDK Initialization
 *
 * Must run before `plugins_loaded` so Freemius can hook into early
 * lifecycle events (activation, license sync, opt-in flow, etc.).
 *
 * The build pipeline sets `AOP_BB_LICENSE_MODE` per target build:
 *   - 'freemius'  → WP.org / addoneplugins.com builds (default).
 *   - 'unlocked'  → WooCommerce Marketplace build (skips SDK entirely).
 *   - 'stub'      → Development builds with no SDK.
 *
 * When mode is 'unlocked' or the SDK files are missing, we silently
 * skip initialization and rely on LicenseManager's mode-aware fallbacks.
 * ========================================================================= */

if ( ! defined( 'AOP_BB_LICENSE_MODE' ) ) {
    define( 'AOP_BB_LICENSE_MODE', 'freemius' );
}

if ( 'freemius' === AOP_BB_LICENSE_MODE && file_exists( AOP_BB_PLUGIN_PATH . 'freemius/start.php' ) ) {

    if ( ! function_exists( 'bbfw_fs' ) ) {

        /**
         * Freemius helper — single global SDK instance.
         *
         * Used by AOP_BB_License_Manager to query plan and trial state.
         * Function name and slugs are tied to the BundlePilot Freemius product
         * (free slug "bundlepilot", premium slug "bundle-pilot-pro").
         *
         * @return \Freemius
         */
        function bbfw_fs() {

            global $bbfw_fs;

            if ( ! isset( $bbfw_fs ) ) {

                // Include Freemius SDK.
                require_once AOP_BB_PLUGIN_PATH . 'freemius/start.php';

                $bbfw_fs = fs_dynamic_init( array(
                    'id'                  => '28573',
                    'slug'                => 'bundlepilot',
                    'premium_slug'        => 'bundle-pilot-pro',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_757d3566922adddef7ede7bcbf661',
                    'is_premium'          => true,
                    'has_premium_version' => true,
                    'has_addons'          => false,
                    'has_paid_plans'      => true,
                    'is_org_compliant'    => true,
                    // Automatically removed in the free version when Freemius
                    // generates the wp.org build.
                    'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                    'trial'               => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'                => array(
                        // The slug matches AOP_BB_Admin_Page::MENU_SLUG. We
                        // own the top-level menu (via add_menu_page); Freemius
                        // attaches its Account / Pricing pages as submenus
                        // under it by setting parent.slug to our slug. This
                        // produces a single, clean BundlePilot menu group
                        // instead of competing top-level items.
                        'slug'       => 'bundlepilot',
                        'first-path' => 'admin.php?page=bundlepilot',
                        'parent'     => array(
                            'slug' => 'bundlepilot',
                        ),
                        // Explicit visibility so we don't depend on SDK defaults.
                        'account'    => true,    // "Account" — license + sites (after opt-in).
                        'pricing'    => true,    // "Upgrade" — Freemius checkout.
                        'contact'    => false,   // "Contact Us" — own support channel.
                        'support'    => false,   // "Support" — same as above.
                        'addons'     => false,   // We have no add-ons.
                        'affiliation' => false,  // Affiliate program disabled by default.
                    ),
                ) );
            }

            return $bbfw_fs;
        }

        // Init Freemius.
        bbfw_fs();

        // Signal that SDK was initiated.
        do_action( 'bbfw_fs_loaded' );

        // Register cleanup with Freemius's uninstall hook.
        // This fires regardless of how the plugin is removed (admin or Freemius UI).
        bbfw_fs()->add_action( 'after_uninstall', 'aop_bb_freemius_cleanup' );

        /**
         * Cleanup callback fired by Freemius after uninstall.
         *
         * Respects the user's "Delete data on uninstall" setting from
         * the Advanced settings tab. If they haven't opted in, we leave
         * everything alone so reinstalling preserves their configuration.
         *
         * @return void
         */
        function aop_bb_freemius_cleanup(): void {

            $delete_data = get_option( 'aop_bb_delete_data_on_uninstall', 'no' );

            if ( 'yes' !== $delete_data ) {
                return;
            }

            // Reuse the same cleanup pass as uninstall.php to keep one source of truth.
            // We need to define the WP_UNINSTALL_PLUGIN constant so uninstall.php proceeds.
            if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
                define( 'WP_UNINSTALL_PLUGIN', AOP_BB_PLUGIN_FILE );
            }
            require_once AOP_BB_PLUGIN_PATH . 'uninstall.php';
        }
    }
}

/* =========================================================================
 * Activation / Deactivation
 * ========================================================================= */

require_once AOP_BB_PLUGIN_PATH . 'includes/class-aop-bb-activator.php';
require_once AOP_BB_PLUGIN_PATH . 'includes/class-aop-bb-deactivator.php';

register_activation_hook( __FILE__, array( 'AOP_BB_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AOP_BB_Deactivator', 'deactivate' ) );

/* =========================================================================
 * Declare WooCommerce Feature Compatibility
 * ========================================================================= */

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
} );

/* =========================================================================
 * Initialize Plugin After WooCommerce is Loaded
 * ========================================================================= */

add_action( 'plugins_loaded', 'aop_bb_init_plugin', 20 );

/**
 * Bootstrap the plugin once WooCommerce is confirmed active.
 *
 * @return void
 */
function aop_bb_init_plugin(): void {

    // Verify WooCommerce is active.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'aop_bb_woocommerce_missing_notice' );
        return;
    }

    // Verify WooCommerce minimum version.
    if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, AOP_BB_REQUIRED_WC_VERSION, '<' ) ) {
        add_action( 'admin_notices', 'aop_bb_woocommerce_version_notice' );
        return;
    }

    // Load text domain.
    load_plugin_textdomain(
        'bundlepilot',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );

    // Load all plugin features.
    require_once AOP_BB_PLUGIN_PATH . 'includes/class-aop-bb-loader.php';
    $loader = new AOP_BB_Loader();
    $loader->run();
}

/* =========================================================================
 * Admin Notices
 * ========================================================================= */

/**
 * Show notice when WooCommerce is not installed.
 *
 * @return void
 */
function aop_bb_woocommerce_missing_notice(): void {

    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    printf(
        /* translators: %1$s Plugin name, %2$s WooCommerce link */
        esc_html__( '%1$s requires %2$s to be installed and activated.', 'bundlepilot' ),
        '<strong>' . esc_html__( 'BundlePilot', 'bundlepilot' ) . '</strong>',
        '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">' . esc_html__( 'WooCommerce', 'bundlepilot' ) . '</a>'
    );
    echo '</p></div>';
}

/**
 * Show notice when WooCommerce version is too old.
 *
 * @return void
 */
function aop_bb_woocommerce_version_notice(): void {

    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    printf(
        /* translators: %1$s Plugin name, %2$s Required version, %3$s Current version */
        esc_html__( '%1$s requires WooCommerce %2$s or higher. You are running WooCommerce %3$s.', 'bundlepilot' ),
        '<strong>' . esc_html__( 'BundlePilot', 'bundlepilot' ) . '</strong>',
        esc_html( AOP_BB_REQUIRED_WC_VERSION ),
        esc_html( WC_VERSION )
    );
    echo '</p></div>';
}

/* =========================================================================
 * Plugin Row Meta
 * ========================================================================= */

add_filter( 'plugin_row_meta', 'aop_bb_plugin_row_meta', 10, 2 );

/**
 * Add documentation and support links to the plugins list.
 *
 * @param array  $links Existing row meta links.
 * @param string $file  Plugin file path.
 * @return array Modified links.
 */
function aop_bb_plugin_row_meta( array $links, string $file ): array {

    if ( plugin_basename( __FILE__ ) === $file ) {
        $row_meta = array(
            'docs'    => '<a href="https://addoneplugins.com/docs/bundle-builder/" aria-label="' . esc_attr__( 'View documentation', 'bundlepilot' ) . '">' . esc_html__( 'Documentation', 'bundlepilot' ) . '</a>',
            'support' => '<a href="https://addoneplugins.com/contact/" aria-label="' . esc_attr__( 'Get support', 'bundlepilot' ) . '">' . esc_html__( 'Support', 'bundlepilot' ) . '</a>',
        );
        return array_merge( $links, $row_meta );
    }

    return $links;
}
