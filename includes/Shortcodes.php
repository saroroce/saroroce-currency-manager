<?php

namespace Saroroce\CurrencyManager;

class Shortcodes {
    public function __construct() {
        add_shortcode('currency_switcher', [$this, 'renderCurrencySwitcher']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets() {
        wp_enqueue_style('scm-currency-switcher', SCM_URL . 'assets/css/currency-switcher.css', [], SCM_VERSION);
        wp_enqueue_script('scm-currency-switcher', SCM_URL . 'assets/js/currency-switcher.js', ['jquery'], SCM_VERSION, true);
        
        wp_localize_script('scm-currency-switcher', 'scmData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scm_change_currency')
        ]);
    }

    public function renderCurrencySwitcher($atts = []) {
        // Получаем все активные валюты
        $currencies = scm_get_currencies();
        
        if (empty($currencies)) {
            return '';
        }

        $current_currency = scm_get_current_currency();
        
        $output = '<select class="scm-currency-switcher">';
        
        foreach ($currencies as $currency) {
            $symbol = !empty($currency['symbol']) ? $currency['symbol'] : $currency['code'];
            
            $output .= sprintf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr($currency['code']),
                selected($current_currency, $currency['code'], false),
                esc_html($currency['name']),
                esc_html($symbol)
            );
        }
        
        $output .= '</select>';
        
        return $output;
    }
} 