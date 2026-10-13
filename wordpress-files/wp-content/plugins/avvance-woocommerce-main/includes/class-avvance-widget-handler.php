<?php
/**
 * Avvance Widget Handler
 * Handles the Avvance consumer eligibility widget display
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Widget_Handler {
    
    public static function init() {
        // Product page widget
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_product_widget'], 25);
        
        // Checkout page widget (after order review)
        add_action('woocommerce_review_order_before_payment', [__CLASS__, 'render_checkout_widget']);
    }
    
    /**
     * Render widget on product page
     */
    public static function render_product_widget() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        // Check if Avvance is enabled
        $gateway = avvance_get_gateway();
        if (!$gateway || $gateway->enabled !== 'yes') {
            return;
        }
        
        // Check if price is within range ($300 - $25,000)
        $price = $product->get_price();
        if (empty($price) || $price < 300 || $price > 25000) {
            return;
        }
        
        // Get merchant ID (shortcode for now)
        $merchant_id = $gateway->get_option('merchant_id');
        $shortcode = '221e3a15';
        
        // Get environment
        $environment = $gateway->get_option('environment');
        $widget_url = ($environment === 'production') 
            ? 'https://www.avvance.com/poslp/widgets/widget'
            : 'https://uat1.avvance.com/poslp/widgets/widget';
        
        // The div ID where WooCommerce displays the price
        $price_div_id = 'price'; // WooCommerce default, may need adjustment per theme
        
        ?>
        <div class="avvance-product-widget" style="margin: 15px 0;">
            <div id="pdp-widget-modal-wrapper"></div>
        </div>
        <script>
            (function() {
                var script = document.createElement('script');
                script.src = '<?php echo esc_js($widget_url); ?>?merchantId=<?php echo esc_js($shortcode); ?>&widgetType=consumereligibility&divReference=<?php echo esc_js($price_div_id); ?>&deviceType=large';
                script.async = true;
                document.body.appendChild(script);
            })();
        </script>
        <?php
    }
    
    /**
     * Render widget on checkout page
     */
    public static function render_checkout_widget() {
        // Check if Avvance is enabled
        $gateway = avvance_get_gateway();
        if (!$gateway || $gateway->enabled !== 'yes') {
            return;
        }
        
        // Check if Avvance is available for current cart
        if (!$gateway->is_available()) {
            return;
        }
        
        // Get merchant ID (shortcode for now)
        $merchant_id = $gateway->get_option('merchant_id');
        $shortcode = '221e3a15';
        
        // Get environment
        $environment = $gateway->get_option('environment');
        $widget_url = ($environment === 'production') 
            ? 'https://www.avvance.com/poslp/widgets/widget'
            : 'https://uat1.avvance.com/poslp/widgets/widget';
        
        // The div ID where WooCommerce displays the order total
        // Common IDs: 'order_review', 'order_total', or specific theme elements
        $price_div_id = 'order_review';
        
        ?>
        <div class="avvance-checkout-widget" style="margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
            <div id="pdp-widget-modal-wrapper"></div>
        </div>
        <script>
            (function() {
                // Wait for checkout to be fully loaded
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).ready(function($) {
                        var script = document.createElement('script');
                        script.src = '<?php echo esc_js($widget_url); ?>?merchantId=<?php echo esc_js($shortcode); ?>&widgetType=consumereligibility&divReference=<?php echo esc_js($price_div_id); ?>&deviceType=large';
                        script.async = true;
                        document.body.appendChild(script);
                    });
                }
            })();
        </script>
        <?php
    }
}