<?php
/**
 * Block Script Integration for WooCommerce Cart/Checkout Blocks.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Block_Script_Integration
 */
class AOP_BB_Block_Script_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

    /**
     * @return string
     */
    public function get_name() {
        return 'bundlepilot';
    }

    /**
     * @return void
     */
    public function initialize() {
        $script_path = AOP_BB_PLUGIN_PATH . 'assets/blocks-cart-frontend.js';
        if ( file_exists( $script_path ) ) {
            wp_register_script(
                'aop-bb-blocks-frontend',
                AOP_BB_PLUGIN_URL . 'assets/blocks-cart-frontend.js',
                array(),
                AOP_BB_VERSION,
                true
            );
        }
    }

    /**
     * @return string[]
     */
    public function get_script_handles() {
        return array( 'aop-bb-blocks-frontend' );
    }

    /**
     * @return string[]
     */
    public function get_editor_script_handles() {
        return array();
    }

    /**
     * @return array
     */
    public function get_script_data() {
        return array(
            'namespace' => 'bundlepilot',
            'i18n'      => array(
                'bundle'   => __( 'Bundle', 'bundlepilot' ),
                'included' => __( 'Included', 'bundlepilot' ),
                'items'    => __( 'items', 'bundlepilot' ),
                'youSave'  => __( 'You save', 'bundlepilot' ),
            ),
        );
    }
}
