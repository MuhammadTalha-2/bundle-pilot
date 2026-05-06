<?php
/**
 * Custom Webhooks (Business Feature)
 *
 * Allows Business plan users to subscribe external endpoints
 * (Zapier, Make, custom integrations) to bundle lifecycle events:
 *
 * - bundle.added_to_cart    Fired when a customer completes the wizard
 *                           and adds the bundle to their cart.
 * - bundle.order_completed  Fired when an order containing a bundle
 *                           reaches the "completed" status.
 *
 * Architecture:
 * - Webhooks are stored as a single option (`aop_bb_webhooks`)
 *   containing an array of subscriptions: `{ url, event, enabled }`.
 * - Dispatch is queued via Action Scheduler when available, falling
 *   back to a non-blocking wp_remote_post otherwise.
 * - Each request signs the payload with HMAC-SHA256 using a per-site
 *   secret stored in `aop_bb_webhook_secret` for receiver validation.
 *
 * Receiver validation:
 *   The `X-BundlePilot-Signature` header contains:
 *     `sha256=<hex>` where <hex> = hash_hmac('sha256', $body, $secret).
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Webhooks
 */
class AOP_BB_Webhooks {

    /**
     * Option key for the subscriptions list.
     *
     * @var string
     */
    const OPTION_KEY = 'aop_bb_webhooks';

    /**
     * Option key for the per-site signing secret.
     *
     * @var string
     */
    const SECRET_KEY = 'aop_bb_webhook_secret';

    /**
     * Action hook used by Action Scheduler for async dispatch.
     *
     * @var string
     */
    const SCHEDULER_HOOK = 'aop_bb_webhook_dispatch';

    /**
     * Available event identifiers.
     *
     * @var string
     */
    const EVENT_ADDED_TO_CART   = 'bundle.added_to_cart';
    const EVENT_ORDER_COMPLETED = 'bundle.order_completed';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        // Settings UI is rendered for everyone, but inputs are gated.
        // Priority 30 ensures Freemius parent menu exists first.
        add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Dispatch hooks are only wired when the plan allows it.
        if ( AOP_BB_License_Manager::can_use( 'custom_webhooks' ) ) {
            $this->register_dispatchers();
        }

        // Async runner — registered always so previously-queued jobs still drain.
        add_action( self::SCHEDULER_HOOK, array( $this, 'dispatch' ), 10, 3 );
    }

    /* ------------------------------------------------------------------
     |  Event Dispatchers
     | ------------------------------------------------------------------*/

    /**
     * Wire up the WC hooks that emit our events.
     *
     * @return void
     */
    protected function register_dispatchers(): void {

        // Fire when a bundle is added to the cart via our AJAX handler.
        add_action( 'aop_bb_bundle_added_to_cart', array( $this, 'on_bundle_added_to_cart' ), 10, 2 );

        // Fire when an order containing a bundle is marked completed.
        add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 2 );
    }

    /**
     * Build payload and queue the "added to cart" event.
     *
     * @param int   $bundle_id Bundle product ID.
     * @param array $context   Add-to-cart context (selections, totals, etc.).
     * @return void
     */
    public function on_bundle_added_to_cart( int $bundle_id, array $context ): void {

        $payload = array(
            'event'      => self::EVENT_ADDED_TO_CART,
            'bundle'     => array(
                'id'    => $bundle_id,
                'title' => get_the_title( $bundle_id ),
                'url'   => get_permalink( $bundle_id ),
            ),
            'selections' => isset( $context['selections'] ) ? $context['selections'] : array(),
            'totals'     => isset( $context['totals'] ) ? $context['totals'] : array(),
            'customer'   => $this->customer_snapshot(),
            'timestamp'  => gmdate( 'c' ),
            'site'       => home_url(),
        );

        $this->queue_event( self::EVENT_ADDED_TO_CART, $payload );
    }

    /**
     * Build payload and queue the "order completed" event.
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object.
     * @return void
     */
    public function on_order_completed( int $order_id, $order = null ): void {

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order ) {
            return;
        }

        // Find the bundles in the order. We only fire if there's at least one.
        $bundles = $this->extract_bundles_from_order( $order );

        if ( empty( $bundles ) ) {
            return;
        }

        $payload = array(
            'event'     => self::EVENT_ORDER_COMPLETED,
            'order'     => array(
                'id'       => $order_id,
                'number'   => $order->get_order_number(),
                'total'    => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'status'   => $order->get_status(),
            ),
            'bundles'   => $bundles,
            'customer'  => array(
                'id'    => $order->get_customer_id(),
                'email' => $order->get_billing_email(),
            ),
            'timestamp' => gmdate( 'c' ),
            'site'      => home_url(),
        );

        $this->queue_event( self::EVENT_ORDER_COMPLETED, $payload );
    }

    /**
     * Build a snapshot of the current customer (logged-in or guest).
     *
     * @return array
     */
    protected function customer_snapshot(): array {

        $user_id = get_current_user_id();

        if ( $user_id > 0 ) {
            $user = wp_get_current_user();
            return array(
                'id'    => (int) $user_id,
                'email' => $user->user_email ?? '',
                'guest' => false,
            );
        }

        return array(
            'id'    => 0,
            'email' => '',
            'guest' => true,
        );
    }

    /**
     * Extract bundle line items from an order.
     *
     * @param WC_Order $order Order to inspect.
     * @return array
     */
    protected function extract_bundles_from_order( WC_Order $order ): array {

        $bundles = array();

        foreach ( $order->get_items() as $item ) {

            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Identify parent bundle line items via meta set in the cart handler.
            $is_bundle = $item->get_meta( '_aop_bb_is_bundle', true );

            if ( 'yes' !== $is_bundle ) {
                continue;
            }

            $bundles[] = array(
                'id'       => $item->get_product_id(),
                'title'    => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'subtotal' => (float) $item->get_subtotal(),
                'total'    => (float) $item->get_total(),
            );
        }

        return $bundles;
    }

    /* ------------------------------------------------------------------
     |  Queueing & Dispatch
     | ------------------------------------------------------------------*/

    /**
     * Queue an event for delivery to all subscribed webhooks.
     *
     * @param string $event   Event identifier.
     * @param array  $payload Event payload.
     * @return void
     */
    protected function queue_event( string $event, array $payload ): void {

        $subscriptions = $this->get_subscriptions_for_event( $event );

        if ( empty( $subscriptions ) ) {
            return;
        }

        foreach ( $subscriptions as $subscription ) {

            // Use Action Scheduler if available (non-blocking, retryable).
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action(
                    self::SCHEDULER_HOOK,
                    array( $subscription['url'], $event, $payload ),
                    'bundlepilot'
                );
                continue;
            }

            // Fallback: fire-and-forget HTTP request with short timeout.
            $this->dispatch( $subscription['url'], $event, $payload );
        }
    }

    /**
     * Dispatch a webhook payload to a single URL.
     *
     * Called either synchronously by {@see queue_event()} as a fallback,
     * or asynchronously via Action Scheduler.
     *
     * @param string $url     Destination URL.
     * @param string $event   Event identifier.
     * @param array  $payload Event payload.
     * @return void
     */
    public function dispatch( string $url, string $event, array $payload ): void {

        if ( ! wp_http_validate_url( $url ) ) {
            return;
        }

        $body      = wp_json_encode( $payload );
        $secret    = $this->get_or_create_secret();
        $signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

        wp_remote_post(
            $url,
            array(
                'timeout'  => 5,
                'blocking' => false,
                'headers'  => array(
                    'Content-Type'             => 'application/json; charset=utf-8',
                    'X-BundlePilot-Event'      => $event,
                    'X-BundlePilot-Signature'  => $signature,
                    'User-Agent'               => 'BundlePilot/' . AOP_BB_VERSION,
                ),
                'body'     => $body,
            )
        );
    }

    /* ------------------------------------------------------------------
     |  Subscription Storage
     | ------------------------------------------------------------------*/

    /**
     * Get all subscriptions, normalized.
     *
     * @return array
     */
    public function get_subscriptions(): array {

        $stored = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $stored ) ) {
            return array();
        }

        return array_values( array_filter( array_map( array( $this, 'normalize_subscription' ), $stored ) ) );
    }

    /**
     * Get subscriptions filtered by event.
     *
     * @param string $event Event identifier.
     * @return array
     */
    public function get_subscriptions_for_event( string $event ): array {

        $all   = $this->get_subscriptions();
        $found = array();

        foreach ( $all as $sub ) {
            if ( ! $sub['enabled'] ) {
                continue;
            }
            if ( $sub['event'] === $event || '*' === $sub['event'] ) {
                $found[] = $sub;
            }
        }

        return $found;
    }

    /**
     * Normalize a single subscription entry.
     *
     * @param mixed $entry Raw entry from storage.
     * @return array|null
     */
    protected function normalize_subscription( $entry ): ?array {

        if ( ! is_array( $entry ) ) {
            return null;
        }

        $url     = isset( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '';
        $event   = isset( $entry['event'] ) ? sanitize_key( $entry['event'] ) : '';
        $enabled = ! empty( $entry['enabled'] );

        if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
            return null;
        }

        $valid_events = array( self::EVENT_ADDED_TO_CART, self::EVENT_ORDER_COMPLETED, '*' );

        if ( ! in_array( $event, $valid_events, true ) ) {
            $event = '*';
        }

        return array(
            'url'     => $url,
            'event'   => $event,
            'enabled' => $enabled,
        );
    }

    /**
     * Get the per-site signing secret, creating one if missing.
     *
     * @return string
     */
    protected function get_or_create_secret(): string {

        $secret = get_option( self::SECRET_KEY );

        if ( ! empty( $secret ) ) {
            return $secret;
        }

        $secret = wp_generate_password( 48, false, false );
        update_option( self::SECRET_KEY, $secret, false );

        return $secret;
    }

    /* ------------------------------------------------------------------
     |  Settings UI
     | ------------------------------------------------------------------*/

    /**
     * Register the Webhooks settings page under WC > Bundle Builder.
     *
     * @return void
     */
    public function register_menu(): void {

        add_submenu_page(
            'bundlepilot',
            __( 'BundlePilot Webhooks', 'bundlepilot' ),
            __( 'Webhooks', 'bundlepilot' ),
            'manage_woocommerce',
            'aop-bb-webhooks',
            array( $this, 'render_page' )
        );
    }

    /**
     * Register the option for save handling.
     *
     * @return void
     */
    public function register_settings(): void {

        register_setting(
            'aop_bb_webhooks_group',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_subscriptions' ),
                'default'           => array(),
            )
        );
    }

    /**
     * Sanitize the subscriptions array on save.
     *
     * @param mixed $input Raw form input.
     * @return array
     */
    public function sanitize_subscriptions( $input ): array {

        if ( ! is_array( $input ) ) {
            return array();
        }

        // Server-side plan check on save.
        if ( ! AOP_BB_License_Manager::can_use( 'custom_webhooks' ) ) {
            // Refuse to save if not Business — preserve existing.
            return (array) get_option( self::OPTION_KEY, array() );
        }

        $clean = array();

        foreach ( $input as $entry ) {
            $normalized = $this->normalize_subscription( $entry );
            if ( null !== $normalized ) {
                $clean[] = $normalized;
            }
        }

        return $clean;
    }

    /**
     * Render the webhooks settings page.
     *
     * @return void
     */
    public function render_page(): void {

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'bundlepilot' ), 403 );
        }

        $can_use       = AOP_BB_License_Manager::can_use( 'custom_webhooks' );
        $subscriptions = $this->get_subscriptions();
        $secret        = $this->get_or_create_secret();

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Bundle Webhooks', 'bundlepilot' ); ?>
                <?php
                if ( ! $can_use ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo AOP_BB_License_Manager::badge( 'business' );
                }
                ?>
            </h1>

            <p class="description" style="max-width: 720px;">
                <?php esc_html_e( 'Send bundle events to external services (Zapier, Make, custom endpoints). Each request is signed with HMAC-SHA256 for receiver validation.', 'bundlepilot' ); ?>
            </p>

            <?php if ( ! $can_use ) : ?>

                <div class="aop-bb-upgrade-notice aop-bb-upgrade-notice--business" style="max-width: 720px;">
                    <h4 class="aop-bb-upgrade-notice__title">
                        <?php esc_html_e( 'Connect bundles to anything', 'bundlepilot' ); ?>
                    </h4>
                    <p class="aop-bb-upgrade-notice__description">
                        <?php esc_html_e( 'Webhooks let you push bundle events into Zapier, Make, your CRM, your analytics, or any custom integration. Available on the Business plan.', 'bundlepilot' ); ?>
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

            <form method="post" action="options.php">
                <?php settings_fields( 'aop_bb_webhooks_group' ); ?>

                <div class="aop-bb-webhooks-list" id="aop-bb-webhooks-list">
                    <?php
                    if ( empty( $subscriptions ) ) {
                        // Render one empty row to start.
                        $subscriptions = array(
                            array( 'url' => '', 'event' => '*', 'enabled' => true ),
                        );
                    }

                    foreach ( $subscriptions as $i => $sub ) {
                        $this->render_webhook_row( $i, $sub );
                    }
                    ?>
                </div>

                <p>
                    <button type="button" id="aop-bb-add-webhook" class="button">
                        <?php esc_html_e( '+ Add Webhook', 'bundlepilot' ); ?>
                    </button>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Signing Secret', 'bundlepilot' ); ?>
                        </th>
                        <td>
                            <code style="user-select: all; padding: 6px 8px; background: #f1f5f9; border-radius: 4px;">
                                <?php echo esc_html( $secret ); ?>
                            </code>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: header name */
                                    esc_html__( 'Each request includes %s = "sha256=<hex>" computed as HMAC-SHA256 of the body. Validate this on your receiver to confirm the request is from this site.', 'bundlepilot' ),
                                    '<code>X-BundlePilot-Signature</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Webhooks', 'bundlepilot' ) ); ?>
            </form>

            <!-- Row template for "Add Webhook" -->
            <script type="text/html" id="aop-bb-webhook-row-template">
                <?php $this->render_webhook_row( '__INDEX__', array( 'url' => '', 'event' => '*', 'enabled' => true ) ); ?>
            </script>

            <script>
                (function () {
                    const list   = document.getElementById('aop-bb-webhooks-list');
                    const button = document.getElementById('aop-bb-add-webhook');
                    const tpl    = document.getElementById('aop-bb-webhook-row-template').innerHTML;
                    let counter  = <?php echo (int) count( $subscriptions ); ?>;

                    button.addEventListener('click', function () {
                        const html = tpl.replace(/__INDEX__/g, counter++);
                        list.insertAdjacentHTML('beforeend', html);
                    });

                    list.addEventListener('click', function (e) {
                        if (e.target.classList.contains('aop-bb-webhook-row__remove')) {
                            const row = e.target.closest('.aop-bb-webhook-row');
                            if (row) row.remove();
                        }
                    });
                })();
            </script>
        </div>
        <?php
    }

    /**
     * Render a single webhook row (URL + event + enabled toggle).
     *
     * @param int|string $index Row index.
     * @param array      $sub   Subscription data.
     * @return void
     */
    protected function render_webhook_row( $index, array $sub ): void {

        $url     = $sub['url'] ?? '';
        $event   = $sub['event'] ?? '*';
        $enabled = ! empty( $sub['enabled'] );

        $name = self::OPTION_KEY . '[' . $index . ']';

        ?>
        <div class="aop-bb-webhook-row">
            <input type="url"
                   name="<?php echo esc_attr( $name . '[url]' ); ?>"
                   value="<?php echo esc_attr( $url ); ?>"
                   placeholder="https://hooks.example.com/bundlepilot" />

            <select name="<?php echo esc_attr( $name . '[event]' ); ?>">
                <option value="*" <?php selected( '*', $event ); ?>>
                    <?php esc_html_e( 'All events', 'bundlepilot' ); ?>
                </option>
                <option value="<?php echo esc_attr( self::EVENT_ADDED_TO_CART ); ?>" <?php selected( self::EVENT_ADDED_TO_CART, $event ); ?>>
                    <?php esc_html_e( 'Bundle added to cart', 'bundlepilot' ); ?>
                </option>
                <option value="<?php echo esc_attr( self::EVENT_ORDER_COMPLETED ); ?>" <?php selected( self::EVENT_ORDER_COMPLETED, $event ); ?>>
                    <?php esc_html_e( 'Order completed', 'bundlepilot' ); ?>
                </option>
            </select>

            <label>
                <input type="checkbox"
                       name="<?php echo esc_attr( $name . '[enabled]' ); ?>"
                       value="1"
                       <?php checked( $enabled ); ?> />
                <?php esc_html_e( 'Enabled', 'bundlepilot' ); ?>
            </label>

            <button type="button" class="aop-bb-webhook-row__remove" aria-label="<?php esc_attr_e( 'Remove webhook', 'bundlepilot' ); ?>">
                ×
            </button>
        </div>
        <?php
    }
}
