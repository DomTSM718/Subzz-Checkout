<?php
/**
 * Product Attribute Tiles
 *
 * Renders non-variation product attributes (Hand, Flex, Loft, Bounce, etc.)
 * as selectable button tiles on the single product page. Uses the same
 * class names as Marc's Duration tile component (subzz-attr-group, subzz-term-btn,
 * is-active) so the tiles inherit existing styling.
 *
 * Attributes are synced dynamically from SA Golf → Shopify → Subzz API → WooCommerce.
 * Products with no non-variation attributes render nothing.
 *
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Subzz_Product_Attribute_Tiles {

    /** Attributes to skip (handled by Marc's existing Duration tile component) */
    private const SKIP_ATTRIBUTES = ['pa_subscription-duration', 'subscription-duration'];

    /** Attributes that should render in a 2-column grid (binary choices) */
    private const TWO_COL_ATTRIBUTES = ['pa_hand', 'hand'];

    public function __construct() {
        // Render tiles on single product page
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_attribute_tiles'], 15);

        // Enqueue JS on single product pages
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Capture selected attributes into cart item data
        add_filter('woocommerce_add_cart_item_data', [$this, 'capture_selections'], 10, 3);

        // Display selected attributes in cart and checkout
        add_filter('woocommerce_get_item_data', [$this, 'display_in_cart'], 10, 2);

        // Persist to order item meta
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'persist_to_order'], 10, 4);
    }

    /**
     * Render button tiles for each non-variation attribute on the product.
     */
    public function render_attribute_tiles() {
        global $product;

        if (!$product || !$product->is_type('variable')) {
            return;
        }

        $attributes = $product->get_attributes();
        if (empty($attributes)) {
            return;
        }

        $rendered = false;

        foreach ($attributes as $slug => $attribute) {
            // Skip variation attributes (Duration is handled by Marc's component)
            if ($attribute->get_variation()) {
                continue;
            }

            // Skip explicitly excluded attributes
            if (in_array($slug, self::SKIP_ATTRIBUTES, true)) {
                continue;
            }

            $options = $attribute->get_options();
            if (empty($options)) {
                continue;
            }

            // Resolve human-readable option labels
            $labels = [];
            if ($attribute->is_taxonomy()) {
                foreach ($options as $term_id) {
                    $term = get_term($term_id);
                    if ($term && !is_wp_error($term)) {
                        $labels[] = $term->name;
                    }
                }
            } else {
                $labels = $options;
            }

            if (empty($labels)) {
                continue;
            }

            if (!$rendered) {
                echo '<div class="subzz-attribute-tiles-wrapper">';
                $rendered = true;
            }

            $attr_label = wc_attribute_label($attribute->get_name(), $product);
            $attr_slug  = sanitize_title($slug);
            $cols       = $this->get_column_count($slug, count($labels));

            echo '<div class="subzz-attr-tile-group" style="margin-bottom: 16px;">';
            echo '<label class="subzz-attr-tile-label" style="display:block; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#545454; opacity:0.7; margin-bottom:8px;">';
            echo esc_html($attr_label);
            echo '</label>';
            echo '<div class="subzz-attr-group" data-attr="attribute_' . esc_attr($attr_slug) . '" style="display:grid; grid-template-columns:repeat(' . $cols . ', 1fr); gap:8px;">';

            foreach ($labels as $index => $label) {
                $active_class = ($index === 0) ? ' is-active' : '';
                echo '<button type="button" class="subzz-term-btn' . $active_class . '" '
                   . 'data-value="' . esc_attr($label) . '" '
                   . 'data-attr="attribute_' . esc_attr($attr_slug) . '">'
                   . esc_html($label)
                   . '</button>';
            }

            echo '</div>'; // .subzz-attr-group
            echo '</div>'; // .subzz-attr-tile-group

            // Hidden field to carry the selection into the form POST
            echo '<input type="hidden" name="subzz_attr_' . esc_attr($attr_slug) . '" '
               . 'class="subzz-attr-hidden" '
               . 'data-attr="attribute_' . esc_attr($attr_slug) . '" '
               . 'value="' . esc_attr($labels[0]) . '" />';
        }

        if ($rendered) {
            echo '</div>'; // .subzz-attribute-tiles-wrapper
        }
    }

    /**
     * Enqueue the tile interaction JS on single product pages.
     */
    public function enqueue_assets() {
        if (!is_product()) {
            return;
        }

        wp_enqueue_script(
            'subzz-attribute-tiles',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/attribute-tiles.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    /**
     * Capture selected attribute values when product is added to cart.
     */
    public function capture_selections($cart_item_data, $product_id, $variation_id) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return $cart_item_data;
        }

        $attributes = $product->get_attributes();
        foreach ($attributes as $slug => $attribute) {
            if ($attribute->get_variation()) {
                continue;
            }
            if (in_array($slug, self::SKIP_ATTRIBUTES, true)) {
                continue;
            }

            $field_name = 'subzz_attr_' . sanitize_title($slug);
            if (isset($_POST[$field_name]) && !empty($_POST[$field_name])) {
                $cart_item_data['subzz_attributes'][$slug] = sanitize_text_field($_POST[$field_name]);
            }
        }

        return $cart_item_data;
    }

    /**
     * Display selected attributes in the cart and checkout order review.
     */
    public function display_in_cart($item_data, $cart_item) {
        if (empty($cart_item['subzz_attributes'])) {
            return $item_data;
        }

        foreach ($cart_item['subzz_attributes'] as $slug => $value) {
            $label = wc_attribute_label($slug);
            $item_data[] = [
                'key'   => $label,
                'value' => $value,
            ];
        }

        return $item_data;
    }

    /**
     * Persist selected attributes to the order line item meta.
     * These flow through to admin order view, retailer notification email, etc.
     */
    public function persist_to_order($item, $cart_item_key, $values, $order) {
        if (empty($values['subzz_attributes'])) {
            return;
        }

        foreach ($values['subzz_attributes'] as $slug => $value) {
            $label = wc_attribute_label($slug);
            $item->add_meta_data($label, $value, true);
        }
    }

    /**
     * Determine grid column count based on the attribute and number of options.
     */
    private function get_column_count(string $slug, int $option_count): int {
        // Binary attributes (Hand: RH/LH) get 2 columns
        if (in_array($slug, self::TWO_COL_ATTRIBUTES, true)) {
            return 2;
        }

        // 4+ options: cap at 3 columns, let them wrap
        if ($option_count >= 4) {
            return 3;
        }

        // Default: match option count (e.g. 3 flexes = 3 columns)
        return max(1, $option_count);
    }
}
