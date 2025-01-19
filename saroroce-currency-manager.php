<?php

/**
 * Plugin Name: SAROROCE Currency Manager
 * Plugin URI: https://github.com/saroroce
 * Description: Менеджер валют для WooCommerce с автоматическим обновлением курсов
 * Version: 1.5
 * Author: SAROROCE | Web Development
 * Author URI: https://github.com/saroroce
 * License: ISC
 * License URI: https://opensource.org/licenses/ISC
 * Text Domain: saroroce-currency-manager
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Определяем константы плагина
define('SCM_PLUGIN_ON', true);
define('SCM_VERSION', '1.5');
define('SCM_FILE', __FILE__);
define('SCM_PATH', plugin_dir_path(SCM_FILE));
define('SCM_URL', plugin_dir_url(SCM_FILE));

// Автозагрузка классов
spl_autoload_register(function ($class) {
    $prefix = 'Saroroce\\CurrencyManager\\';
    $base_dir = SCM_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Активация плагина
register_activation_hook(SCM_FILE, function () {
    // Проверяем наличие WooCommerce
    if (!class_exists('WooCommerce')) {
        wp_die('Этот плагин требует установленный и активированный WooCommerce.');
    }

    // Создаем основную валюту
    $base_currency = get_woocommerce_currency();
    $base_symbol = get_woocommerce_currency_symbol();

    // Проверяем существование основной валюты
    $existing = get_posts([
        'post_type' => 'currency',
        'posts_per_page' => 1,
        'meta_key' => 'currency_code',
        'meta_value' => $base_currency
    ]);

    if (empty($existing)) {
        // Создаем запись для основной валюты
        $post_data = array(
            'post_title' => $base_currency,
            'post_type' => 'currency',
            'post_status' => 'publish'
        );

        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            update_post_meta($post_id, 'currency_code', $base_currency);
            update_post_meta($post_id, 'currency_symbol', $base_symbol);
            update_post_meta($post_id, 'currency_rate', '1');
            update_post_meta($post_id, 'is_default', '1');
            update_post_meta($post_id, 'is_active', '1');
        }
    }

    // Создаем таблицы и настройки при активации
    update_option('scm_auto_detect_currency', '0');
});

// Инициализация плагина
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
?>
            <div class="notice notice-error">
                <p><?php _e('SAROROCE Currency Manager требует установленный и активированный WooCommerce.', 'saroroce-currency-manager'); ?></p>
            </div>
<?php
        });
        return;
    }

    // Загружаем текстовый домен
    load_plugin_textdomain('saroroce-currency-manager', false, dirname(plugin_basename(SCM_FILE)) . '/languages');

    // Инициализируем основной класс
    new \Saroroce\CurrencyManager\Plugin();

    // Инициализируем новые классы
    \Saroroce\CurrencyManager\CurrencyManager::getInstance();
    new \Saroroce\CurrencyManager\Shortcodes();
    new \Saroroce\CurrencyManager\Admin\Settings();
});

// Регистрируем хук для обновления курсов валют
add_action('init', function () {
    if (!wp_next_scheduled('scm_update_currency_rates')) {
        wp_schedule_event(time(), 'daily', 'scm_update_currency_rates');
    }
});

// Добавляем скрипты для админки
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook == 'post-new.php' || $hook == 'post.php') {
        global $post;
        if ($post && $post->post_type == 'currency') {
            // Подключаем Select2
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);

            // Подключаем наши скрипты
            wp_enqueue_script('scm-admin', SCM_URL . 'assets/js/admin.js', ['jquery', 'select2'], SCM_VERSION, true);
            wp_localize_script('scm-admin', 'scmAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('scm_admin')
            ]);
        }
    }
});

if (!function_exists('scm_get_current_currency')) {
    function scm_get_current_currency()
    {
        $currency = \Saroroce\CurrencyManager\CurrencyManager::getInstance()->getCurrentCurrency();
        return $currency;
    }
}

if (!function_exists('scm_set_user_currency')) {
    function scm_set_user_currency($currency_code)
    {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->setUserCurrency($currency_code);
    }
}

if (!function_exists('scm_convert_price')) {
    function scm_convert_price($price)
    {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->convertPrice($price);
    }
}

if (!function_exists('scm_get_currencies')) {
    function scm_get_currencies()
    {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->getCurrencies();
    }
}

if (!function_exists('scm_get_original_price')) {
    function scm_get_original_price($product_id, $formatted = false) {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->getOriginalPrice($product_id, $formatted);
    }
}

if (!function_exists('scm_get_original_regular_price')) {
    function scm_get_original_regular_price($product_id, $formatted = false) {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->getOriginalRegularPrice($product_id, $formatted);
    }
}

if (!function_exists('scm_get_original_sale_price')) {
    function scm_get_original_sale_price($product_id, $formatted = false) {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->getOriginalSalePrice($product_id, $formatted);
    }
}

add_action('init', function() {
    if (!is_admin()) {
        // Отключаем кэширование на уровне WordPress
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
        
        // Устанавливаем заголовки для запрета кэширования
        header_remove('Last-Modified');
        header_remove('ETag');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sun, 01 Jan 1984 00:00:00 GMT');
        
        // Добавляем случайное число для предотвращения кэширования
        header('X-No-Cache: ' . rand());
    }
}, 0);

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('scm-frontend', SCM_URL . 'assets/js/frontend.js', ['jquery'], SCM_VERSION, true);
});