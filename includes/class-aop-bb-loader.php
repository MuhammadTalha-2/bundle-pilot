<?php
/**
 * Core plugin loader.
 *
 * Registers all hooks and loads all classes required for the plugin
 * to function when the license is active.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Loader
 */
class AOP_BB_Loader {

    /**
     * Run the loader — register all hooks.
     *
     * @return void
     */
    public function run(): void {

        $this->load_dependencies();
        $this->register_product_type();
        $this->register_admin_hooks();
        $this->register_api();
        $this->register_cart();
        $this->register_frontend();
        $this->register_blocks();
        $this->register_order_display();
        $this->register_compat();
        $this->register_security();
        $this->register_business_features();
    }

    /**
     * Load all required class files.
     *
     * @return void
     */
    private function load_dependencies(): void {

        // Phase 1: Product type.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Product/class-wc-product-bundle-builder.php';
        require_once AOP_BB_PLUGIN_PATH . 'includes/Product/class-aop-bb-product-type.php';

        // Phase 1: Admin settings panel on the product edit screen.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Admin/class-aop-bb-product-data.php';

        // Settings helpers — always loaded for static get_setting() access.
        // The WC_Settings_Page extension is handled via woocommerce_get_settings_pages filter.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Admin/class-aop-bb-settings-page.php';

        // Phase 2: Pricing engine (shared service).
        require_once AOP_BB_PLUGIN_PATH . 'includes/Pricing/class-aop-bb-price-calculator.php';

        // Phase 2: REST API controller.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Api/class-aop-bb-rest-controller.php';

        // Phase 2: AJAX add-to-cart handler.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Api/class-aop-bb-ajax-cart.php';

        // Phase 2: Cart handler (parent-child logic).
        require_once AOP_BB_PLUGIN_PATH . 'includes/Cart/class-aop-bb-cart-handler.php';

        // Phase 2: Frontend asset enqueuing.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Cart/class-aop-bb-frontend-assets.php';

        // Phase 2: Single product template override.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Cart/class-aop-bb-single-product.php';

        // Phase 4: WooCommerce Blocks integration.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Blocks/class-aop-bb-blocks-integration.php';

        // Phase 4: Order admin display.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Order/class-aop-bb-order-display.php';

        // Phase 4: Theme compatibility.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Compat/class-aop-bb-theme-compat.php';

        // Phase 4: Security hardening.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Compat/class-aop-bb-security.php';

        // Phase 5: License manager (Freemius-aware feature gating).
        require_once AOP_BB_PLUGIN_PATH . 'includes/class-aop-bb-license-manager.php';

        // Phase 5: Business-tier features. Each class is self-gating —
        // it inspects the LicenseManager and only wires hooks when
        // the active plan permits the feature.
        require_once AOP_BB_PLUGIN_PATH . 'includes/Business/class-aop-bb-white-label.php';
        require_once AOP_BB_PLUGIN_PATH . 'includes/Business/class-aop-bb-role-visibility.php';
        require_once AOP_BB_PLUGIN_PATH . 'includes/Business/class-aop-bb-template-registry.php';
        require_once AOP_BB_PLUGIN_PATH . 'includes/Business/class-aop-bb-templates.php';
        require_once AOP_BB_PLUGIN_PATH . 'includes/Business/class-aop-bb-import-export.php';
        require_once AOP_BB_PLUGIN_PATH . 'includes/Business/class-aop-bb-webhooks.php';
    }

    /**
     * Register the custom product type with WooCommerce.
     *
     * @return void
     */
    private function register_product_type(): void {

        $product_type = new AOP_BB_Product_Type();
        $product_type->register();
    }

    /**
     * Register admin hooks for product data panels and settings.
     *
     * @return void
     */
    private function register_admin_hooks(): void {

        $product_data = new AOP_BB_Product_Data();
        $product_data->register();

        // Register the standalone BundlePilot settings page that lives
        // under the WooCommerce → BundlePilot menu (created by Freemius).
        require_once AOP_BB_PLUGIN_PATH . 'includes/Admin/class-aop-bb-admin-page.php';
        ( new AOP_BB_Admin_Page() )->register();
    }

    /**
     * Register REST API and AJAX endpoints.
     *
     * @return void
     */
    private function register_api(): void {

        $rest = new AOP_BB_Rest_Controller();
        $rest->register();

        $ajax_cart = new AOP_BB_Ajax_Cart();
        $ajax_cart->register();
    }

    /**
     * Register cart handler hooks.
     *
     * @return void
     */
    private function register_cart(): void {

        $cart_handler = new AOP_BB_Cart_Handler();
        $cart_handler->register();
    }

    /**
     * Register frontend assets and template hooks.
     *
     * @return void
     */
    private function register_frontend(): void {

        $frontend_assets = new AOP_BB_Frontend_Assets();
        $frontend_assets->register();

        $single_product = new AOP_BB_Single_Product();
        $single_product->register();
    }

    /**
     * Register WooCommerce Blocks integration.
     *
     * @return void
     */
    private function register_blocks(): void {

        $blocks = new AOP_BB_Blocks_Integration();
        $blocks->register();
    }

    /**
     * Register order admin display hooks.
     *
     * @return void
     */
    private function register_order_display(): void {

        $order_display = new AOP_BB_Order_Display();
        $order_display->register();
    }

    /**
     * Register theme compatibility layer.
     *
     * @return void
     */
    private function register_compat(): void {

        $theme_compat = new AOP_BB_Theme_Compat();
        $theme_compat->register();
    }

    /**
     * Register security hardening hooks.
     *
     * @return void
     */
    private function register_security(): void {

        $security = new AOP_BB_Security();
        $security->register();
    }

    /**
     * Register Business-tier features.
     *
     * Each feature class checks the LicenseManager internally and
     * registers only the hooks it's allowed to (or only the upgrade
     * UI when the plan doesn't permit the feature). This means we
     * always instantiate them — they're cheap when locked.
     *
     * Order is important:
     * - White Label registers before frontend rendering.
     * - Templates and Import/Export register admin menus together.
     * - Webhooks register last so all dispatching events exist first.
     *
     * @return void
     */
    private function register_business_features(): void {

        ( new AOP_BB_White_Label() )->register();
        ( new AOP_BB_Role_Visibility() )->register();
        ( new AOP_BB_Templates() )->register();
        ( new AOP_BB_Import_Export() )->register();
        ( new AOP_BB_Webhooks() )->register();

        // Enqueue Business-tier admin styles globally on admin screens.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_business_admin_assets' ) );
    }

    /**
     * Enqueue admin styles used by Business feature UIs.
     *
     * @return void
     */
    public function enqueue_business_admin_assets(): void {

        wp_enqueue_style(
            'aop-bb-admin-business',
            AOP_BB_PLUGIN_URL . 'assets/admin-business.css',
            array(),
            AOP_BB_VERSION
        );
    }
}
