<?php
/**
 * Fired during plugin deactivation.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Deactivator
 *
 * Handles cleanup tasks that run on plugin deactivation.
 */
class AOP_BB_Deactivator {

    /**
     * Run deactivation tasks.
     *
     * @return void
     */
    public static function deactivate(): void {

        // Clean up the rewrite flush flag.
        delete_option( 'aop_bb_flush_rewrite' );
    }
}
