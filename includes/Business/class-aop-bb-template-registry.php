<?php
/**
 * Bundle Template Registry
 *
 * Holds the catalogue of pre-built bundle starters that customers
 * can instantiate with a single click.
 *
 * Templates are pure data (no products are pre-selected) — they
 * provide the structure (steps, pricing mode, tier rules, naming)
 * and the admin fills in the actual products afterward.
 *
 * To add a new template, append an entry to {@see self::definitions()}
 * or hook into the `aop_bb_bundle_templates` filter.
 *
 * @package AOP_BundleBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AOP_BB_Template_Registry
 */
class AOP_BB_Template_Registry {

    /**
     * Get all available templates.
     *
     * @return array Templates keyed by slug.
     */
    public static function all(): array {

        $templates = self::definitions();

        /**
         * Filter the available bundle templates.
         *
         * @param array $templates Templates keyed by slug.
         */
        $templates = apply_filters( 'aop_bb_bundle_templates', $templates );

        return $templates;
    }

    /**
     * Get a single template by slug.
     *
     * @param string $slug Template slug.
     * @return array|null
     */
    public static function get( string $slug ): ?array {

        $all = self::all();

        return $all[ $slug ] ?? null;
    }

    /**
     * Built-in template definitions.
     *
     * Each template includes:
     * - title       Marketing title shown to admins.
     * - description Short pitch for the template card.
     * - icon        Emoji or short symbol for visual identification.
     * - tags        Display pills (e.g. "Gift", "DTC", "Fixed Price").
     * - product     Default product post fields (title, status).
     * - meta        Bundle configuration meta (steps, pricing mode, tiers).
     *
     * @return array
     */
    protected static function definitions(): array {

        return array(

            // -----------------------------------------------------------
            // 1. Build Your Own Gift Box
            // -----------------------------------------------------------
            'gift_box_3_steps' => array(
                'title'       => __( 'Build Your Own Gift Box', 'bundlepilot' ),
                'description' => __( 'Three-step gift bundle: pick the box, choose the gift, add a card. Sum-of-items pricing.', 'bundlepilot' ),
                'icon'        => '🎁',
                'tags'        => array( __( 'Gift', 'bundlepilot' ), __( '3 steps', 'bundlepilot' ), __( 'Sum pricing', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Build Your Own Gift Box', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode' => 'sum',
                    '_aop_bb_steps'        => array(
                        array(
                            'title'   => __( 'Choose a Box', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Select Your Gifts', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 5,
                        ),
                        array(
                            'title'   => __( 'Add a Greeting Card', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 0,
                            'max_qty' => 1,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 2. Subscription Box Builder
            // -----------------------------------------------------------
            'subscription_box' => array(
                'title'       => __( 'Subscription Box Builder', 'bundlepilot' ),
                'description' => __( 'Curated monthly box at a fixed price. Customer picks the items, you set the value.', 'bundlepilot' ),
                'icon'        => '📦',
                'tags'        => array( __( 'DTC', 'bundlepilot' ), __( 'Fixed price', 'bundlepilot' ), __( '4 steps', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Monthly Subscription Box', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode' => 'fixed',
                    '_aop_bb_fixed_price'  => 49.00,
                    '_aop_bb_steps'        => array(
                        array(
                            'title'   => __( 'Snacks', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 2,
                            'max_qty' => 4,
                        ),
                        array(
                            'title'   => __( 'Beverages', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 3,
                        ),
                        array(
                            'title'   => __( 'Self-Care', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 2,
                        ),
                        array(
                            'title'   => __( 'Bonus Item', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 0,
                            'max_qty' => 1,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 3. Build Your Own Meal
            // -----------------------------------------------------------
            'build_your_meal' => array(
                'title'       => __( 'Build Your Own Meal', 'bundlepilot' ),
                'description' => __( 'Restaurant-style meal builder: protein, sides, drink. Sum-of-items pricing.', 'bundlepilot' ),
                'icon'        => '🍱',
                'tags'        => array( __( 'Food', 'bundlepilot' ), __( 'Sum pricing', 'bundlepilot' ), __( '3 steps', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Build Your Own Meal', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode' => 'sum',
                    '_aop_bb_steps'        => array(
                        array(
                            'title'   => __( 'Choose Your Protein', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Pick 2 Sides', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 2,
                            'max_qty' => 2,
                        ),
                        array(
                            'title'   => __( 'Add a Drink', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 4. Buy More Save More
            // -----------------------------------------------------------
            'buy_more_save_more' => array(
                'title'       => __( 'Buy More, Save More', 'bundlepilot' ),
                'description' => __( 'Single-step bundle with quantity-based discount tiers. Drives larger basket sizes.', 'bundlepilot' ),
                'icon'        => '💰',
                'tags'        => array( __( 'Tiered', 'bundlepilot' ), __( 'Volume discount', 'bundlepilot' ), __( '1 step', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Buy More & Save', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode'     => 'tiered',
                    '_aop_bb_tiered_discounts' => array(
                        array( 'min_qty' => 3,  'discount' => 5 ),
                        array( 'min_qty' => 5,  'discount' => 10 ),
                        array( 'min_qty' => 10, 'discount' => 15 ),
                    ),
                    '_aop_bb_steps' => array(
                        array(
                            'title'   => __( 'Pick Your Items', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 3,
                            'max_qty' => 20,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 5. Skincare Routine Kit
            // -----------------------------------------------------------
            'skincare_kit' => array(
                'title'       => __( 'Skincare Routine Kit', 'bundlepilot' ),
                'description' => __( 'Complete routine builder: cleanser, treatment, moisturizer, SPF. Tiered pricing.', 'bundlepilot' ),
                'icon'        => '🧴',
                'tags'        => array( __( 'Beauty', 'bundlepilot' ), __( 'Tiered', 'bundlepilot' ), __( '4 steps', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Build Your Skincare Routine', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode'     => 'tiered',
                    '_aop_bb_tiered_discounts' => array(
                        array( 'min_qty' => 3, 'discount' => 10 ),
                        array( 'min_qty' => 4, 'discount' => 15 ),
                    ),
                    '_aop_bb_steps' => array(
                        array(
                            'title'   => __( 'Cleanser', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Treatment', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 2,
                        ),
                        array(
                            'title'   => __( 'Moisturizer', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Sunscreen', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 0,
                            'max_qty' => 1,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 6. Outfit Builder
            // -----------------------------------------------------------
            'outfit_builder' => array(
                'title'       => __( 'Outfit Builder', 'bundlepilot' ),
                'description' => __( 'Fashion bundle: top, bottom, shoes, accessories. Sum-of-items pricing.', 'bundlepilot' ),
                'icon'        => '👕',
                'tags'        => array( __( 'Fashion', 'bundlepilot' ), __( 'Sum pricing', 'bundlepilot' ), __( '4 steps', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Complete Outfit Builder', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode' => 'sum',
                    '_aop_bb_steps'        => array(
                        array(
                            'title'   => __( 'Top', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Bottom', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Shoes', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 0,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Accessories', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 0,
                            'max_qty' => 3,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 7. Coffee Lover's Bundle
            // -----------------------------------------------------------
            'coffee_bundle' => array(
                'title'       => __( "Coffee Lover's Bundle", 'bundlepilot' ),
                'description' => __( 'Coffee-themed gift bundle: beans, equipment, accessories. Fixed price.', 'bundlepilot' ),
                'icon'        => '☕',
                'tags'        => array( __( 'Gift', 'bundlepilot' ), __( 'Fixed price', 'bundlepilot' ), __( '3 steps', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( "Coffee Lover's Gift Bundle", 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode' => 'fixed',
                    '_aop_bb_fixed_price'  => 79.00,
                    '_aop_bb_steps'        => array(
                        array(
                            'title'   => __( 'Coffee Beans', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 2,
                        ),
                        array(
                            'title'   => __( 'Brewing Equipment', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 1,
                            'max_qty' => 1,
                        ),
                        array(
                            'title'   => __( 'Accessories', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 0,
                            'max_qty' => 3,
                        ),
                    ),
                ),
            ),

            // -----------------------------------------------------------
            // 8. New Customer Welcome Pack
            // -----------------------------------------------------------
            'welcome_pack' => array(
                'title'       => __( 'New Customer Welcome Pack', 'bundlepilot' ),
                'description' => __( 'Single-step starter bundle for first-time buyers. Quantity tiers reward larger first orders.', 'bundlepilot' ),
                'icon'        => '🌟',
                'tags'        => array( __( 'Tiered', 'bundlepilot' ), __( 'Onboarding', 'bundlepilot' ), __( '1 step', 'bundlepilot' ) ),
                'product'     => array(
                    'title' => __( 'Welcome Starter Pack', 'bundlepilot' ),
                ),
                'meta'        => array(
                    '_aop_bb_pricing_mode'     => 'tiered',
                    '_aop_bb_tiered_discounts' => array(
                        array( 'min_qty' => 2, 'discount' => 5 ),
                        array( 'min_qty' => 3, 'discount' => 10 ),
                        array( 'min_qty' => 5, 'discount' => 20 ),
                    ),
                    '_aop_bb_steps' => array(
                        array(
                            'title'   => __( 'Choose Your Starter Items', 'bundlepilot' ),
                            'source'  => 'category',
                            'min_qty' => 2,
                            'max_qty' => 8,
                        ),
                    ),
                ),
            ),
        );
    }
}
