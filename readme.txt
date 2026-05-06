=== BundlePilot — Product Bundle Wizard for WooCommerce ===
Contributors: addoneplugins
Tags: woocommerce, product bundles, bundle wizard, build a box, tiered discounts
Requires at least: 6.4
Tested up to: 6.9.1
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 10.5.2
Stable tag: 1.0.0
License: GPL v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Guide your customers to the perfect bundle. A step-by-step product bundle wizard with fixed, summed, and tiered-discount pricing.

== Description ==

BundlePilot turns bundle creation into a guided journey. Customers move through a beautiful, mobile-first wizard — picking products step by step from curated categories or hand-picked lists — while the price updates live and stock is checked in real time.

Perfect for gift boxes, build-your-own meals, subscription boxes, skincare routines, outfit builders, and any "buy more, save more" promotion.

**Why BundlePilot?**

* **Step-by-step wizard** — Guide customers through a beautiful, mobile-first bundle builder interface.
* **Three pricing modes** — Fixed price, sum of items, or tiered volume discounts (Pro).
* **Real parent-child cart** — Each bundled item is a real cart line for accurate stock deduction.
* **Live price preview** — Bundle total updates in real time as customers select.
* **Real-time stock sync** — Out-of-stock items are automatically disabled in the wizard.
* **HPOS compatible** — Fully compatible with WooCommerce High-Performance Order Storage.
* **Cart & Checkout Blocks** — Works with both the classic shortcode cart and the block-based Cart and Checkout.
* **Customizable look** — Accent color, card styles, and progress styles to match your brand.
* **Theme compatible** — Tested with Storefront, Astra, GeneratePress, Kadence, OceanWP, and block themes.

**Free vs Pro vs Business**

* **Free** — Up to 3 bundles, 3 steps each, fixed and sum pricing, category-based steps.
* **Pro** — Unlimited bundles and steps, tiered volume discounts, hand-picked product steps, advanced quantity rules.
* **Business** — Multi-site licensing, white-label branding, bundle templates library, import/export, role-based visibility, custom webhooks, priority support.

== Installation ==

1. Upload the `bundlepilot` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to **WooCommerce → BundlePilot → Settings** to configure global appearance and behavior.
4. Create a new product and select **"BundlePilot Bundle"** as the product type.
5. Configure the bundle steps, pricing mode, and products.

== Frequently Asked Questions ==

= How does stock management work? =

Each product selected in a bundle is added to the cart as a real line item. WooCommerce's native stock management handles deduction for each product individually at checkout — no custom inventory layer.

= Does this work with the block-based cart and checkout? =

Yes. BundlePilot extends the WooCommerce Store API so bundle metadata is available to the Cart and Checkout blocks. Parent-child relationships, bundle badges, and pricing are all displayed correctly.

= What pricing modes are available? =

Three modes: **Fixed** (one price regardless of selection), **Sum of Items** (total of individual product prices), and **Tiered Volume Discounts** (percentage off based on quantity thresholds — Pro feature).

= Can I customize the wizard appearance? =

Yes. Accent color picker is available on the Free plan. Pro adds card style options and progress style choices. Business adds white-label branding to remove the "Powered by BundlePilot" footer.

= Will my existing bundle products break if I deactivate the plugin? =

No. Deactivation does not delete bundle products or settings. If you reactivate later, all configurations are restored exactly as they were.

== Screenshots ==

1. The step-by-step bundle builder on the product page.
2. Admin product data panel with step configuration.
3. BundlePilot settings page with appearance customization options.
4. Bundle displayed in the WooCommerce cart with parent-child structure.
5. Order details showing bundle metadata in the admin.

== Changelog ==

= 1.0.0 =
* Initial release.
