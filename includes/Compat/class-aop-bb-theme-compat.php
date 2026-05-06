<?php
/**
 * Theme Compatibility — Cross-Theme Consistency
 *
 * Ensures the Bundle Builder works cleanly across popular
 * WooCommerce themes by:
 *
 * - Detecting the active theme and applying targeted fixes.
 * - Enqueuing theme-specific CSS overrides where needed.
 * - Handling differences in single product template structure.
 * - Ensuring the builder container has proper width/spacing.
 *
 * Supported themes:
 * - Storefront (default WooCommerce theme)
 * - Astra
 * - GeneratePress
 * - Kadence
 * - OceanWP
 * - Block-based themes (FSE / Site Editor)
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Theme_Compat
 */
class AOP_BB_Theme_Compat {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_compat_styles' ), 30 );
        add_filter( 'body_class', array( $this, 'add_body_class' ) );
    }

    /**
     * Add a body class when on a bundle builder product page.
     *
     * This allows themes and custom CSS to target the builder.
     *
     * @param array $classes Existing body classes.
     * @return array Modified classes.
     */
    public function add_body_class( $classes ) {

        if ( ! is_product() ) {
            return $classes;
        }

        global $post;

        if ( ! $post ) {
            return $classes;
        }

        $product = wc_get_product( $post->ID );

        if ( $product && 'bundle_builder' === $product->get_type() ) {
            $classes[] = 'aop-bb-product-page';
            $classes[] = 'aop-bb-theme-' . $this->get_theme_slug();
        }

        return $classes;
    }

    /**
     * Enqueue theme-specific compatibility styles.
     *
     * @return void
     */
    public function enqueue_compat_styles(): void {

        if ( ! is_product() ) {
            return;
        }

        global $post;

        if ( ! $post ) {
            return;
        }

        $product = wc_get_product( $post->ID );

        if ( ! $product || 'bundle_builder' !== $product->get_type() ) {
            return;
        }

        $css = $this->get_base_compat_css();
        $css .= $this->get_theme_specific_css();

        if ( ! empty( $css ) ) {
            wp_register_style( 'aop-bb-compat', false );
            wp_enqueue_style( 'aop-bb-compat' );
            wp_add_inline_style( 'aop-bb-compat', $css );
        }
    }

    /**
     * Get base compatibility CSS that applies to all themes.
     *
     * Resets and normalizations that ensure the builder renders
     * consistently regardless of theme styling.
     *
     * @return string
     */
    private function get_base_compat_css(): string {

        return '
        /* Ensure the builder container is full width within the product summary */
        .aop-bb-builder-root {
            width: 100%;
            max-width: 100%;
            clear: both;
        }

        /* Reset any theme box-sizing that might break layout */
        .aop-bb-builder,
        .aop-bb-builder *,
        .aop-bb-builder *::before,
        .aop-bb-builder *::after {
            box-sizing: border-box;
        }

        /* Prevent theme button styles from overriding builder buttons */
        .aop-bb-builder .aop-bb-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-family: inherit;
        }

        /* Ensure product card images are not stretched by theme global img rules */
        .aop-bb-product-card img {
            max-width: 100%;
            height: auto;
            border-radius: 0;
            margin: 0;
            padding: 0;
        }

        /* Reset theme heading styles within the builder */
        .aop-bb-builder h3,
        .aop-bb-builder h4 {
            font-family: inherit;
            letter-spacing: normal;
            text-transform: none;
        }

        /* Cart badge styles for classic shortcode cart */
        .aop-bb-bundle-badge {
            display: inline-block;
            background: #FF4D00;
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 6px;
            vertical-align: middle;
        }
        ';
    }

    /**
     * Get theme-specific CSS overrides.
     *
     * @return string
     */
    private function get_theme_specific_css(): string {

        $slug = $this->get_theme_slug();
        $css  = '';

        switch ( $slug ) {

            case 'storefront':
                $css = '
                /* Storefront: wider product summary area */
                .aop-bb-product-page .summary.entry-summary {
                    width: 100%;
                    float: none;
                }
                .aop-bb-product-page .summary.entry-summary .aop-bb-builder-root {
                    margin-top: 20px;
                }
                /* Storefront button resets */
                .aop-bb-builder .aop-bb-btn-primary {
                    border-radius: 8px;
                }
                ';
                break;

            case 'astra':
                $css = '
                /* Astra: ensure builder takes full content width */
                .aop-bb-product-page .entry-content .aop-bb-builder-root,
                .aop-bb-product-page .summary .aop-bb-builder-root {
                    width: 100%;
                }
                /* Astra button normalization */
                .aop-bb-builder .aop-bb-btn {
                    line-height: 1.4;
                }
                ';
                break;

            case 'generatepress':
                $css = '
                /* GeneratePress: content area width */
                .aop-bb-product-page .inside-article .aop-bb-builder-root {
                    width: 100%;
                }
                ';
                break;

            case 'kadence':
                $css = '
                /* Kadence: builder container spacing */
                .aop-bb-product-page .product .aop-bb-builder-root {
                    margin-top: 24px;
                }
                ';
                break;

            case 'oceanwp':
                $css = '
                /* OceanWP: prevent content overflow */
                .aop-bb-product-page .owp-content-area .aop-bb-builder-root {
                    width: 100%;
                    overflow: visible;
                }
                ';
                break;

            default:
                // Block-based / FSE themes — generally clean, minimal fixes.
                if ( wp_is_block_theme() ) {
                    $css = '
                    /* Block theme: ensure proper spacing in content area */
                    .aop-bb-product-page .wp-block-woocommerce-product-details .aop-bb-builder-root,
                    .aop-bb-product-page .wc-block-components-product-details .aop-bb-builder-root {
                        margin-top: 24px;
                    }
                    ';
                }
                break;
        }

        return $css;
    }

    /**
     * Get the current theme slug (handles child themes).
     *
     * @return string
     */
    private function get_theme_slug(): string {

        $theme  = wp_get_theme();
        $parent = $theme->parent();

        // Use parent theme slug if this is a child theme.
        $slug = $parent ? $parent->get_stylesheet() : $theme->get_stylesheet();

        return sanitize_key( $slug );
    }
}
