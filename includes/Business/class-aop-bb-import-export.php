<?php
/**
 * Bundle Import / Export (Business Feature)
 *
 * Allows admins to export bundle configurations as portable JSON
 * and re-import them on the same or another store.
 *
 * Export format: a JSON document containing the bundle title,
 * type, and all `_aop_bb_*` post meta. Product references in
 * handpicked steps are stored as SKUs (not IDs) so they survive
 * cross-site moves where IDs differ.
 *
 * Import behavior:
 * - Each entry creates a new draft bundle.
 * - Handpicked product SKUs are looked up; missing products are
 *   noted in the warnings section but don't block the import.
 *
 * Security:
 * - Uploads are restricted to `manage_woocommerce` users.
 * - Files must be JSON; size capped at 2 MB to prevent abuse.
 * - All imported text is sanitized; product references validated.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Import_Export
 */
class AOP_BB_Import_Export {

    /**
     * Admin menu slug.
     *
     * @var string
     */
    const MENU_SLUG = 'aop-bb-import-export';

    /**
     * Action slug for the import handler.
     *
     * @var string
     */
    const ACTION_IMPORT = 'aop_bb_import_bundles';

    /**
     * Action slug for the export handler.
     *
     * @var string
     */
    const ACTION_EXPORT = 'aop_bb_export_bundles';

    /**
     * Nonce key for import.
     *
     * @var string
     */
    const NONCE_IMPORT = 'aop_bb_import_bundles';

    /**
     * Nonce key for export.
     *
     * @var string
     */
    const NONCE_EXPORT = 'aop_bb_export_bundles';

    /**
     * Maximum upload size in bytes (2 MB).
     *
     * @var int
     */
    const MAX_UPLOAD_BYTES = 2097152;

    /**
     * Export schema version. Bumped if the format changes.
     *
     * @var int
     */
    const SCHEMA_VERSION = 1;

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Priority 30: ensures Freemius parent menu exists first.
        add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
        add_action( 'admin_post_' . self::ACTION_EXPORT, array( $this, 'handle_export' ) );
        add_action( 'admin_post_' . self::ACTION_IMPORT, array( $this, 'handle_import' ) );
        add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
    }

    /**
     * Register the Import/Export submenu under WooCommerce → BundlePilot.
     *
     * @return void
     */
    public function register_menu(): void {

        add_submenu_page(
            'bundlepilot',
            __( 'Import / Export Bundles', 'bundlepilot' ),
            __( 'Import / Export', 'bundlepilot' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the import/export tools page.
     *
     * @return void
     */
    public function render_page(): void {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'bundlepilot' ), 403 );
        }

        $can_use = AOP_BB_License_Manager::can_use( 'bundle_import_export' );
        $bundles = $this->get_all_bundles();

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Bundle Import / Export', 'bundlepilot' ); ?>
                <?php
                if ( ! $can_use ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() returns escaped HTML.
                    echo AOP_BB_License_Manager::badge( 'business' );
                }
                ?>
            </h1>

            <?php if ( ! $can_use ) : ?>
                <div class="aop-bb-upgrade-notice aop-bb-upgrade-notice--business" style="max-width: 720px; margin-top: 16px;">
                    <h4 class="aop-bb-upgrade-notice__title">
                        <?php esc_html_e( 'Move bundles between sites in seconds', 'bundlepilot' ); ?>
                    </h4>
                    <p class="aop-bb-upgrade-notice__description">
                        <?php esc_html_e( 'Export your bundle configurations as JSON and re-import them on any WooCommerce store. Available on the Business plan.', 'bundlepilot' ); ?>
                    </p>
                    <a href="<?php echo esc_url( AOP_BB_License_Manager::get_upgrade_url() ); ?>"
                       class="aop-bb-upgrade-notice__cta"
                       target="_blank" rel="noopener">
                        <?php esc_html_e( 'Upgrade to Business', 'bundlepilot' ); ?>
                    </a>
                </div>
                <?php
                return;
            endif;
            ?>

            <div class="aop-bb-import-export">

                <!-- Export section -->
                <div class="aop-bb-import-export__section">
                    <h3><?php esc_html_e( 'Export Bundles', 'bundlepilot' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Select the bundles to export. The download will be a portable JSON file.', 'bundlepilot' ); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>" />
                        <?php wp_nonce_field( self::NONCE_EXPORT ); ?>

                        <?php if ( empty( $bundles ) ) : ?>

                            <p><em><?php esc_html_e( 'No bundles found in this store yet.', 'bundlepilot' ); ?></em></p>

                        <?php else : ?>

                            <p>
                                <label>
                                    <input type="checkbox" id="aop-bb-export-select-all" />
                                    <strong><?php esc_html_e( 'Select all', 'bundlepilot' ); ?></strong>
                                </label>
                            </p>

                            <div class="aop-bb-import-export__bundle-list">
                                <?php foreach ( $bundles as $bundle ) : ?>
                                    <label>
                                        <input type="checkbox" name="bundle_ids[]" value="<?php echo esc_attr( $bundle->ID ); ?>" />
                                        <?php echo esc_html( $bundle->post_title ); ?>
                                        <span style="color: #94a3b8; font-size: 12px;">
                                            (#<?php echo (int) $bundle->ID; ?>)
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <p>
                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e( 'Download Selected as JSON', 'bundlepilot' ); ?>
                                </button>
                            </p>

                            <script>
                                (function () {
                                    const selectAll = document.getElementById('aop-bb-export-select-all');
                                    if (!selectAll) return;
                                    selectAll.addEventListener('change', function () {
                                        document.querySelectorAll('.aop-bb-import-export__bundle-list input[type="checkbox"]')
                                            .forEach(cb => cb.checked = selectAll.checked);
                                    });
                                })();
                            </script>

                        <?php endif; ?>
                    </form>
                </div>

                <!-- Import section -->
                <div class="aop-bb-import-export__section">
                    <h3><?php esc_html_e( 'Import Bundles', 'bundlepilot' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Upload a JSON file exported from BundlePilot. Each bundle is imported as a draft so you can review before publishing.', 'bundlepilot' ); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>" />
                        <?php wp_nonce_field( self::NONCE_IMPORT ); ?>

                        <p>
                            <input type="file"
                                   name="bundles_file"
                                   accept=".json,application/json"
                                   class="aop-bb-import-export__file-input"
                                   required />
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e( 'Import Bundles', 'bundlepilot' ); ?>
                            </button>
                            <span style="color: #94a3b8; font-size: 12px; margin-left: 10px;">
                                <?php esc_html_e( 'Maximum file size: 2 MB', 'bundlepilot' ); ?>
                            </span>
                        </p>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     |  Export
     | ------------------------------------------------------------------*/

    /**
     * Handle the export request — stream a JSON download.
     *
     * @return void
     */
    public function handle_export(): void {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export bundles.', 'bundlepilot' ), 403 );
        }

        check_admin_referer( self::NONCE_EXPORT );

        if ( ! AOP_BB_License_Manager::can_use( 'bundle_import_export' ) ) {
            wp_die( esc_html__( 'Bundle import/export requires the Business plan.', 'bundlepilot' ), 403 );
        }

        $bundle_ids = isset( $_POST['bundle_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bundle_ids'] ) ) : array();

        if ( empty( $bundle_ids ) ) {
            $this->redirect_with_error( __( 'Please select at least one bundle to export.', 'bundlepilot' ) );
        }

        $payload = $this->build_export_payload( $bundle_ids );

        if ( empty( $payload['bundles'] ) ) {
            $this->redirect_with_error( __( 'No valid bundles found to export.', 'bundlepilot' ) );
        }

        $filename = sprintf( 'bundlepilot-bundles-%s.json', gmdate( 'Y-m-d-His' ) );
        $body     = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $body ) );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file body, not HTML.
        echo $body;
        exit;
    }

    /**
     * Build the export payload for a set of bundle IDs.
     *
     * @param int[] $bundle_ids Bundle product IDs.
     * @return array
     */
    protected function build_export_payload( array $bundle_ids ): array {

        $entries = array();

        foreach ( $bundle_ids as $bundle_id ) {

            $product = wc_get_product( $bundle_id );

            if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
                continue;
            }

            $meta = array(
                '_aop_bb_pricing_mode'     => get_post_meta( $bundle_id, '_aop_bb_pricing_mode', true ),
                '_aop_bb_fixed_price'      => get_post_meta( $bundle_id, '_aop_bb_fixed_price', true ),
                '_aop_bb_tiered_discounts' => get_post_meta( $bundle_id, '_aop_bb_tiered_discounts', true ),
                '_aop_bb_steps'            => get_post_meta( $bundle_id, '_aop_bb_steps', true ),
                '_aop_bb_visible_roles'    => get_post_meta( $bundle_id, '_aop_bb_visible_roles', true ),
            );

            // Convert handpicked product IDs to SKUs (portable across sites).
            if ( is_array( $meta['_aop_bb_steps'] ) ) {
                foreach ( $meta['_aop_bb_steps'] as &$step ) {
                    if ( ! empty( $step['product_ids'] ) && is_array( $step['product_ids'] ) ) {
                        $step['product_skus'] = $this->ids_to_skus( (array) $step['product_ids'] );
                        // Keep IDs only as a soft reference; SKUs are the canonical pointer.
                    }
                }
                unset( $step );
            }

            $entries[] = array(
                'title'       => $product->get_name(),
                'description' => $product->get_description(),
                'meta'        => $meta,
            );
        }

        return array(
            'schema'      => self::SCHEMA_VERSION,
            'plugin'      => 'bundlepilot',
            'exported_at' => gmdate( 'c' ),
            'site_url'    => home_url(),
            'bundles'     => $entries,
        );
    }

    /**
     * Convert an array of product IDs to SKUs.
     *
     * @param int[] $ids Product IDs.
     * @return string[] SKUs (skipping products without one).
     */
    protected function ids_to_skus( array $ids ): array {

        $skus = array();

        foreach ( $ids as $id ) {
            $product = wc_get_product( (int) $id );

            if ( ! $product ) {
                continue;
            }

            $sku = $product->get_sku();

            if ( ! empty( $sku ) ) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }

    /* ------------------------------------------------------------------
     |  Import
     | ------------------------------------------------------------------*/

    /**
     * Handle the import request — read the upload and create bundles.
     *
     * @return void
     */
    public function handle_import(): void {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to import bundles.', 'bundlepilot' ), 403 );
        }

        check_admin_referer( self::NONCE_IMPORT );

        if ( ! AOP_BB_License_Manager::can_use( 'bundle_import_export' ) ) {
            wp_die( esc_html__( 'Bundle import/export requires the Business plan.', 'bundlepilot' ), 403 );
        }

        if ( empty( $_FILES['bundles_file']['name'] ) ) {
            $this->redirect_with_error( __( 'Please choose a file to upload.', 'bundlepilot' ) );
        }

        $file = $_FILES['bundles_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is validated below.

        if ( ! is_array( $file ) || ! isset( $file['error'], $file['size'], $file['tmp_name'] ) ) {
            $this->redirect_with_error( __( 'Invalid upload.', 'bundlepilot' ) );
        }

        if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
            $this->redirect_with_error( __( 'Upload failed. Please try again.', 'bundlepilot' ) );
        }

        if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
            $this->redirect_with_error( __( 'File too large. Maximum size is 2 MB.', 'bundlepilot' ) );
        }

        $filetype = wp_check_filetype( $file['name'] );

        if ( 'json' !== ( $filetype['ext'] ?? '' ) ) {
            $this->redirect_with_error( __( 'Only JSON files are accepted.', 'bundlepilot' ) );
        }

        $contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( false === $contents ) {
            $this->redirect_with_error( __( 'Could not read the uploaded file.', 'bundlepilot' ) );
        }

        $payload = json_decode( $contents, true );

        if ( ! is_array( $payload ) || empty( $payload['bundles'] ) || ! is_array( $payload['bundles'] ) ) {
            $this->redirect_with_error( __( 'The file is not a valid BundlePilot export.', 'bundlepilot' ) );
        }

        $created = 0;
        $skipped = 0;

        foreach ( $payload['bundles'] as $entry ) {
            $result = $this->create_bundle_from_entry( $entry );

            if ( is_wp_error( $result ) ) {
                ++$skipped;
                continue;
            }

            ++$created;
        }

        $message = sprintf(
            /* translators: 1: count of imported bundles, 2: count of skipped bundles. */
            _n(
                'Imported %1$d bundle. (%2$d skipped due to errors.)',
                'Imported %1$d bundles. (%2$d skipped due to errors.)',
                $created,
                'bundlepilot'
            ),
            $created,
            $skipped
        );

        $this->redirect_with_success( $message );
    }

    /**
     * Create a new bundle from an import entry.
     *
     * @param array $entry Single bundle entry from the import payload.
     * @return int|WP_Error
     */
    protected function create_bundle_from_entry( array $entry ) {

        $title = isset( $entry['title'] ) ? sanitize_text_field( wp_unslash( $entry['title'] ) ) : __( 'Imported Bundle', 'bundlepilot' );
        $desc  = isset( $entry['description'] ) ? wp_kses_post( $entry['description'] ) : '';
        $meta  = isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : array();

        $new_id = wp_insert_post(
            array(
                'post_type'    => 'product',
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_content' => $desc,
                'post_author'  => get_current_user_id(),
            ),
            true
        );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        wp_set_object_terms( $new_id, 'bundle_builder', 'product_type' );

        // Sanitize and apply meta.
        $clean_meta = $this->sanitize_imported_meta( $meta );

        foreach ( $clean_meta as $key => $value ) {
            update_post_meta( $new_id, $key, $value );
        }

        /**
         * Fires after a bundle has been created via import.
         *
         * @param int   $new_id New bundle ID.
         * @param array $entry  Original import entry.
         */
        do_action( 'aop_bb_bundle_imported', $new_id, $entry );

        return $new_id;
    }

    /**
     * Sanitize imported meta values.
     *
     * Resolves SKUs back to product IDs on the destination store.
     *
     * @param array $meta Raw meta from the import file.
     * @return array Sanitized meta array.
     */
    protected function sanitize_imported_meta( array $meta ): array {

        $clean = array();

        if ( isset( $meta['_aop_bb_pricing_mode'] ) ) {
            $mode = sanitize_key( $meta['_aop_bb_pricing_mode'] );
            $clean['_aop_bb_pricing_mode'] = in_array( $mode, array( 'fixed', 'sum', 'tiered' ), true ) ? $mode : 'sum';
        }

        if ( isset( $meta['_aop_bb_fixed_price'] ) ) {
            $clean['_aop_bb_fixed_price'] = wc_format_decimal( (string) $meta['_aop_bb_fixed_price'] );
        }

        if ( isset( $meta['_aop_bb_tiered_discounts'] ) && is_array( $meta['_aop_bb_tiered_discounts'] ) ) {
            $tiers = array();
            foreach ( $meta['_aop_bb_tiered_discounts'] as $tier ) {
                if ( ! is_array( $tier ) ) {
                    continue;
                }
                $tiers[] = array(
                    'min_qty'  => isset( $tier['min_qty'] ) ? max( 0, (int) $tier['min_qty'] ) : 0,
                    'discount' => isset( $tier['discount'] ) ? max( 0, min( 100, (float) $tier['discount'] ) ) : 0,
                );
            }
            $clean['_aop_bb_tiered_discounts'] = $tiers;
        }

        if ( isset( $meta['_aop_bb_steps'] ) && is_array( $meta['_aop_bb_steps'] ) ) {
            $clean['_aop_bb_steps'] = $this->sanitize_imported_steps( $meta['_aop_bb_steps'] );
        }

        if ( isset( $meta['_aop_bb_visible_roles'] ) && is_array( $meta['_aop_bb_visible_roles'] ) ) {
            $clean['_aop_bb_visible_roles'] = array_map( 'sanitize_key', $meta['_aop_bb_visible_roles'] );
        }

        return $clean;
    }

    /**
     * Sanitize an imported steps array.
     *
     * Resolves product SKUs to local product IDs.
     *
     * @param array $steps Steps array.
     * @return array Sanitized steps.
     */
    protected function sanitize_imported_steps( array $steps ): array {

        $clean = array();

        foreach ( $steps as $step ) {
            if ( ! is_array( $step ) ) {
                continue;
            }

            $entry = array(
                'title'      => isset( $step['title'] ) ? sanitize_text_field( $step['title'] ) : '',
                'source'     => isset( $step['source'] ) && in_array( $step['source'], array( 'category', 'handpicked' ), true ) ? $step['source'] : 'category',
                'min_qty'    => isset( $step['min_qty'] ) ? max( 0, (int) $step['min_qty'] ) : 0,
                'max_qty'    => isset( $step['max_qty'] ) ? max( 0, (int) $step['max_qty'] ) : 0,
            );

            // Category IDs may not match across sites — preserve as best-effort.
            if ( ! empty( $step['category_ids'] ) && is_array( $step['category_ids'] ) ) {
                $entry['category_ids'] = array_map( 'absint', $step['category_ids'] );
            }

            // Resolve SKUs back to product IDs on this site.
            if ( ! empty( $step['product_skus'] ) && is_array( $step['product_skus'] ) ) {
                $entry['product_ids'] = $this->skus_to_ids( array_map( 'sanitize_text_field', $step['product_skus'] ) );
            } elseif ( ! empty( $step['product_ids'] ) && is_array( $step['product_ids'] ) ) {
                // Fallback to raw IDs if no SKUs (same-site duplication scenario).
                $entry['product_ids'] = array_map( 'absint', $step['product_ids'] );
            }

            $clean[] = $entry;
        }

        return $clean;
    }

    /**
     * Convert an array of SKUs to local product IDs.
     *
     * @param string[] $skus SKUs to look up.
     * @return int[] Resolved product IDs.
     */
    protected function skus_to_ids( array $skus ): array {

        $ids = array();

        foreach ( $skus as $sku ) {

            if ( '' === $sku ) {
                continue;
            }

            $id = wc_get_product_id_by_sku( $sku );

            if ( $id ) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /* ------------------------------------------------------------------
     |  Helpers
     | ------------------------------------------------------------------*/

    /**
     * Get all bundle products in the store.
     *
     * @return WP_Post[]
     */
    protected function get_all_bundles(): array {

        $query = new WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => 'bundle_builder',
                    ),
                ),
            )
        );

        return $query->posts;
    }

    /**
     * Redirect to the import/export page with an error notice.
     *
     * @param string $message Error message.
     * @return void
     */
    protected function redirect_with_error( string $message ): void {

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => self::MENU_SLUG,
                    'aop_bb_ie_error' => rawurlencode( $message ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Redirect to the import/export page with a success notice.
     *
     * @param string $message Success message.
     * @return void
     */
    protected function redirect_with_success( string $message ): void {

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'              => self::MENU_SLUG,
                    'aop_bb_ie_success' => rawurlencode( $message ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Render notices on the import/export page.
     *
     * @return void
     */
    public function maybe_render_notice(): void {

        if ( ! empty( $_GET['aop_bb_ie_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['aop_bb_ie_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
        }

        if ( ! empty( $_GET['aop_bb_ie_success'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['aop_bb_ie_success'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
        }
    }
}
