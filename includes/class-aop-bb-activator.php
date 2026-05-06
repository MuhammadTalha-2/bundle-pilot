<?php
/**
 * Fired during plugin activation.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Activator
 *
 * Handles tasks that run once on plugin activation, such as
 * setting default options and flushing rewrite rules.
 */
class AOP_BB_Activator {

    /**
     * Run activation tasks.
     *
     * @return void
     */
    public static function activate(): void {

        // Set a flag so we can flush rewrite rules on next admin load.
        update_option( 'aop_bb_flush_rewrite', 'yes' );

        // Store plugin version for future migrations.
        update_option( 'aop_bb_version', AOP_BB_VERSION );
    }
}
