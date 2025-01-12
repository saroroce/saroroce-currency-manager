<?php

namespace Saroroce\CurrencyManager;

use Exception;
use WC_Geolocation;

class CurrencyManager
{
    private static $instance = null;
    private $cookie_name = 'scm_currency';
    private $cookie_expiry = YEAR_IN_SECONDS;
    private $rates_cache = [];
    private $is_checking_currency = false;
    private $default_currency = null;

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        error_log('CurrencyManager constructor called');
        $this->default_currency = get_woocommerce_currency();
        $this->cookie_name = 'scm_currency';

        // Добавляем глобальные функции
        // $this->addGlobalFunctions();

        // Добавляем обработку параметра new_currency
        add_action('init', [$this, 'handleCurrencyParameter']);
        
        // Добавляем автоопределение валюты
        add_action('wp_loaded', [$this, 'maybeSetCurrencyByIp'], 20);

        // Основные фильтры для цен WooCommerce
        add_filter('woocommerce_product_get_price', [$this, 'convertProductPrice'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'convertProductPrice'], 10, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'convertProductPrice'], 10, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'convertProductPrice'], 10, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'convertProductPrice'], 10, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'convertProductPrice'], 10, 2);

        // Фильтры для отображения
        add_filter('woocommerce_currency_symbol', [$this, 'filterWoocommerceCurrencySymbol'], 10, 2);
        add_filter('woocommerce_price_format', [$this, 'getPriceFormat'], 10, 2);
        
        // Добавляем фильтр валюты только для фронтенда
        if (!is_admin()) {
            add_filter('woocommerce_currency', [$this, 'filterWoocommerceCurrency'], 999, 1);
        }
        
        // AJAX обработчики
        add_action('wp_ajax_scm_change_currency', [$this, 'ajaxChangeCurrency']);
        add_action('wp_ajax_nopriv_scm_change_currency', [$this, 'ajaxChangeCurrency']);
        add_action('wp_ajax_update_all_rates', [$this, 'ajaxUpdateAllRates']);

        // Добавляем хуки для сохранения оригинальной цены
        add_action('woocommerce_process_product_meta', [$this, 'saveOriginalPrice'], 10, 1);
        add_action('woocommerce_save_product_variation', [$this, 'saveOriginalPrice'], 10, 1);
        
        // Добавляем колонку с оригинальной ценой в админке
        add_filter('manage_product_posts_columns', [$this, 'addOriginalPriceColumn']);
        add_action('manage_product_posts_custom_column', [$this, 'renderOriginalPriceColumn'], 10, 2);
    }

    public function filterPrice($return, $price, $args, $unformatted_price, $original_price)
    {
        static $is_filtering = false;

        // Защита от рекурсии
        if ($is_filtering) {
            return $return;
        }

        $is_filtering = true;

        $current_currency = $this->getCurrentCurrency();

        // Если текущая валюта совпадает с основной - возвращаем исходное значение
        if ($current_currency === $this->default_currency) {
            $is_filtering = false;
            return $return;
        }

        // Получаем курс валюты
        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'currency_code',
                    'value' => $current_currency
                ],
                [
                    'key' => 'is_active',
                    'value' => '1'
                ]
            ]
        ]);

        if (empty($currencies)) {
            $is_filtering = false;
            return $return;
        }

        $rate = get_post_meta($currencies[0]->ID, 'currency_rate', true);
        if (!$rate) {
            $is_filtering = false;
            return $return;
        }

        // Конвертируем цену
        $converted_price = $unformatted_price * floatval($rate);

        // Получаем символ и позицию для текущей валюты
        $symbol = get_post_meta($currencies[0]->ID, 'currency_symbol', true);
        $position = get_post_meta($currencies[0]->ID, 'currency_position', true);

        // Обновляем аргументы
        $args['currency'] = $current_currency;
        $args['currency_symbol'] = $symbol;

        // Устанавливаем формат в зависимости от позиции
        switch ($position) {
            case 'left':
                $args['price_format'] = '%1$s%2$s';
                break;
            case 'right':
                $args['price_format'] = '%2$s%1$s';
                break;
            case 'left_space':
                $args['price_format'] = '%1$s&nbsp;%2$s';
                break;
            case 'right_space':
                $args['price_format'] = '%2$s&nbsp;%1$s';
                break;
        }

        // Форматируем цену без фильтра
        remove_filter('wc_price', [$this, 'filterPrice'], 10);
        $formatted = wc_price($converted_price, $args);
        add_filter('wc_price', [$this, 'filterPrice'], 10, 5);

        $is_filtering = false;
        return $formatted;
    }

    public function filterWoocommerceCurrency($currency)
    {
        return $this->getCurrentCurrency();
    }

    public function getCurrentCurrency()
    {
        // Проверяем сессию WooCommerce
        if (function_exists('WC') && WC()->session && WC()->session->get('selected_currency')) {
            $currency = WC()->session->get('selected_currency');
            if ($this->quickCurrencyCheck($currency)) {
                return $currency;
            }
        }

        // Проверяем куки
        if (isset($_COOKIE[$this->cookie_name])) {
            $currency = sanitize_text_field($_COOKIE[$this->cookie_name]);
            if ($this->quickCurrencyCheck($currency)) {
                return $currency;
            }
        }

        return $this->default_currency;
    }

    public function filterWoocommerceCurrencySymbol($currency_symbol, $currency)
    {
        return $this->getCurrencySymbol($currency);
    }

    public function filterWCProductQuery($query)
    {
        $query->set('meta_key', 'currency_code');
        $query->set('meta_value', $this->getCurrentCurrency());
        return $query;
    }

    private function quickCurrencyCheck($currency_code)
    {
        global $wpdb;

        // Прямой запрос к базе данных вместо get_posts
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            WHERE p.post_type = 'currency'
            AND pm1.meta_key = 'currency_code'
            AND pm1.meta_value = %s
            AND pm2.meta_key = 'is_active'
            AND pm2.meta_value = '1'
            LIMIT 1
        ", $currency_code));

        return !empty($exists);
    }

    public function setUserCurrency($currency_code)
    {
        try {
            $currency_code = sanitize_text_field($currency_code);
            
            if (!$this->quickCurrencyCheck($currency_code)) {
                return false;
            }

            // Устанавливаем куки напрямую
            setcookie(
                $this->cookie_name,
                $currency_code,
                [
                    'expires' => time() + $this->cookie_expiry,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );

            $_COOKIE[$this->cookie_name] = $currency_code;
            
            // Обновляем сессию WooCommerce
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('selected_currency', $currency_code);
            }

            // Очищаем кэш
            $this->clearCache();

            do_action('scm_currency_changed', $currency_code);
            
            return true;
        } catch (Exception $e) {
            error_log('Currency Manager: Failed to set currency - ' . $e->getMessage());
            return false;
        }
    }

    public function convertPrice($price, $product = null)
    {
        if (empty($price) || !is_numeric($price)) {
            return $price;
        }

        $from_currency = get_woocommerce_currency();
        $to_currency = $this->getCurrentCurrency();

        if ($from_currency === $to_currency) {
            return $price;
        }

        // Получаем курс из настроек валюты
        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'currency_code',
                    'value' => $to_currency
                ],
                [
                    'key' => 'is_active',
                    'value' => '1'
                ]
            ]
        ]);

        if (empty($currencies)) {
            return $price;
        }

        $rate = get_post_meta($currencies[0]->ID, 'currency_rate', true);
        if (!$rate) {
            return $price;
        }

        // Конвертируем цену
        // Если курс 2, значит 1 USD = 2 BGN
        // Поэтому для конвертации USD в BGN умножаем на курс
        return floatval($price) * floatval($rate);
    }

    public function updateAllRates()
    {
        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'is_active',
                    'value' => '1'
                ],
                [
                    'key' => 'auto_update',
                    'value' => '1'
                ]
            ]
        ]);

        if (empty($currencies)) {
            return 0;
        }

        $base_currency = get_woocommerce_currency();
        $updated = 0;

        // Получаем курсы через API
        $response = wp_remote_get('https://api.exchangerate-api.com/v4/latest/' . $base_currency);

        if (is_wp_error($response)) {
            return 0;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['rates'])) {
            return 0;
        }

        foreach ($currencies as $currency) {
            $code = get_post_meta($currency->ID, 'currency_code', true);
            // Пропускаем базовую валюту
            if ($code === $base_currency) {
                continue;
            }
            // Проверяем auto_update для каждой валюты
            $auto_update = get_post_meta($currency->ID, 'auto_update', true);
            if ($auto_update && isset($data['rates'][$code])) {
                update_post_meta($currency->ID, 'currency_rate', $data['rates'][$code]);
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->clearCache();
        }

        return $updated;
    }

    public function addCurrencyToHash($hash)
    {
        $hash[] = $this->getCurrentCurrency();
        return $hash;
    }

    private function currencyExists($currency_code)
    {
        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'currency_code',
                    'value' => $currency_code
                ],
                [
                    'key' => 'is_active',
                    'value' => '1'
                ]
            ]
        ]);
        return !empty($currencies);
    }

    public function getCurrencyRate($currency_code)
    {
        if (!isset($this->rates_cache['single_' . $currency_code])) {
            $currencies = get_posts([
                'post_type' => 'currency',
                'posts_per_page' => 1,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'currency_code',
                        'value' => $currency_code
                    ],
                    [
                        'key' => 'is_active',
                        'value' => '1'
                    ]
                ]
            ]);

            if (empty($currencies)) {
                $this->rates_cache['single_' . $currency_code] = false;
            } else {
                $rate = get_post_meta($currencies[0]->ID, 'currency_rate', true);
                $this->rates_cache['single_' . $currency_code] = $rate ? (float) $rate : false;
            }
        }

        return $this->rates_cache['single_' . $currency_code];
    }

    public function ajaxChangeCurrency()
    {
        try {
            // Проверяем nonce
            if (!check_ajax_referer('scm_change_currency', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            if (!isset($_POST['currency']) || empty($_POST['currency'])) {
                throw new Exception('Currency not specified');
            }

            $currency = sanitize_text_field($_POST['currency']);

            // Проверяем существование и активность валюты
            if (!$this->quickCurrencyCheck($currency)) {
                throw new Exception('Invalid or inactive currency');
            }

            // Устанавливаем куки напрямую
            setcookie(
                $this->cookie_name,
                $currency,
                [
                    'expires' => time() + $this->cookie_expiry,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );

            $_COOKIE[$this->cookie_name] = $currency;

            // Обновляем сессию WooCommerce
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('selected_currency', $currency);
            }

            // Очищаем кэш
            $this->clearCache();

            do_action('scm_currency_changed', $currency);

            wp_send_json_success([
                'currency' => $currency,
                'symbol' => $this->getCurrencySymbol('', $currency),
                'message' => 'Currency changed successfully',
                'reload' => true
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => 'error',
                'debug' => [
                    'currency' => isset($currency) ? $currency : null,
                    'cookie_name' => $this->cookie_name,
                    'cookie_exists' => isset($_COOKIE[$this->cookie_name])
                ]
            ]);
        }
    }

    public function maybeSetCurrencyByIp()
    {
        error_log('maybeSetCurrencyByIp called');
        
        // Проверяем настройку автоопределения
        if (get_option('scm_auto_detect_currency', '0') !== '1') {
            error_log('Auto detection is disabled');
            return;
        }

        // Проверяем, установлена ли уже валюта
        if (isset($_COOKIE[$this->cookie_name])) {
            error_log('Currency cookie already set: ' . $_COOKIE[$this->cookie_name]);
            return;
        }

        // Получаем геолокацию через WooCommerce
        $location = WC_Geolocation::geolocate_ip();
        error_log('WC Geolocation result: ' . print_r($location, true));
        
        if (!empty($location['country'])) {
            $country_code = $location['country'];
            error_log('Country code: ' . $country_code);
            
            // Пробуем получить валюту через WooCommerce
            $currency = $this->getWooCommerceCurrencyByCountry($country_code);
            error_log('Currency for country: ' . $currency);
            
            // Проверяем существование валюты в нашей системе
            if ($currency && $this->quickCurrencyCheck($currency)) {
                error_log('Setting currency to: ' . $currency);
                $this->setUserCurrency($currency);
                return;
            }
        }

        // Если не удалось определить валюту, устанавливаем валюту по умолчанию
        error_log('Setting default currency: ' . $this->default_currency);
        $this->setUserCurrency($this->default_currency);
    }

    private function getWooCommerceCurrencyByCountry($country_code) 
    {
        // Получаем все доступные валюты WooCommerce
        $wc_currencies = get_woocommerce_currencies();
        
        // Получаем базовую локацию магазина
        $base_location = wc_get_base_location();
        
        // Пытаемся определить валюту по стране
        $currency = false;
        
        // Проверяем специальные случаи для стран еврозоны
        $eurozone_countries = [
            'DE', 'FR', 'IT', 'ES', 'PT', 'GR', 'AT', 'BE', 'IE', 'NL', 
            'FI', 'SK', 'SI', 'EE', 'LV', 'LT', 'LU', 'MT', 'CY'
        ];
        
        if (in_array($country_code, $eurozone_countries)) {
            return 'EUR';
        }
        
        // Используем встроенное сопоставление WooCommerce, если оно доступно
        $locale_info = include WC()->plugin_path() . '/i18n/locale-info.php';
        if (isset($locale_info[$country_code]['currency_code'])) {
            $currency = $locale_info[$country_code]['currency_code'];
        }
        
        return $currency;
    }

    private function getCurrencyByCountry($country_code)
    {
        // Расширенная карта соответствия стран и валют
        $currency_map = [
            'US' => 'USD', // США
            'GB' => 'GBP', // Великобритания
            'EU' => 'EUR', // Европейский союз
            'RU' => 'RUB', // Россия
            'BG' => 'BGN', // Болгария
            'BY' => 'BYN', // Беларусь
            'KZ' => 'KZT', // Казахстан
            'UA' => 'UAH', // Украина
            'CN' => 'CNY', // Китай
            'JP' => 'JPY', // Япония
            // Страны еврозоны
            'DE' => 'EUR', // Германия
            'FR' => 'EUR', // Франция
            'IT' => 'EUR', // Италия
            'ES' => 'EUR', // Испания
            'PT' => 'EUR', // Португалия
            'GR' => 'EUR', // Греция
            'AT' => 'EUR', // Австрия
            'BE' => 'EUR', // Бельгия
            'IE' => 'EUR', // Ирландия
            'NL' => 'EUR', // Нидерланды
            'FI' => 'EUR', // Финляндия
            'SK' => 'EUR', // Словакия
            'SI' => 'EUR', // Словения
            'EE' => 'EUR', // Эстония
            'LV' => 'EUR', // Латвия
            'LT' => 'EUR', // Литва
        ];

        return isset($currency_map[$country_code]) ? $currency_map[$country_code] : 'USD';
    }

    public function getCurrencySymbol($symbol, $currency = '')
    {
        if (empty($currency)) {
            $currency = $this->getCurrentCurrency();
        }

        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'currency_code',
                    'value' => $currency
                ],
                [
                    'key' => 'is_active',
                    'value' => '1'
                ]
            ]
        ]);

        if (!empty($currencies)) {
            $custom_symbol = get_post_meta($currencies[0]->ID, 'currency_symbol', true);
            if ($custom_symbol) {
                return $custom_symbol;
            }
        }

        return $symbol;
    }

    public function getPriceFormat($format, $currency_pos)
    {
        $currency = $this->getCurrentCurrency();

        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'currency_code',
                    'value' => $currency
                ],
                [
                    'key' => 'is_active',
                    'value' => '1'
                ]
            ]
        ]);

        if (!empty($currencies)) {
            $position = get_post_meta($currencies[0]->ID, 'currency_position', true);
            if ($position) {
                switch ($position) {
                    case 'left':
                        return '%1$s%2$s';
                    case 'right':
                        return '%2$s%1$s';
                    case 'left_space':
                        return '%1$s&nbsp;%2$s';
                    case 'right_space':
                        return '%2$s&nbsp;%1$s';
                }
            }
        }

        return $format;
    }

    public function convertPostMetaPrice($value, $object_id, $meta_key, $single)
    {
        // Проверяем, является ли это ценой
        $price_meta_keys = ['_price', '_regular_price', '_sale_price'];

        if (!in_array($meta_key, $price_meta_keys)) {
            return $value;
        }

        // Получаем оригинальное значение
        remove_filter('get_post_metadata', [$this, 'convertPostMetaPrice'], 99);
        $original_value = get_post_meta($object_id, $meta_key, $single);
        add_filter('get_post_metadata', [$this, 'convertPostMetaPrice'], 99, 4);

        // Конвертируем цену
        if (is_numeric($original_value)) {
            return $this->convertPrice($original_value);
        }

        return $value;
    }

    public function modifyProductQuery($args)
    {
        // Если сортировка по цене
        if (isset($args['orderby']) && $args['orderby'] === 'price') {
            // Добавляем JOIN и WHERE для использования сконвертированных цен
            add_filter('posts_join', [$this, 'addPriceJoin'], 99);
            add_filter('posts_where', [$this, 'addPriceWhere'], 99);
        }
        return $args;
    }

    public function addPriceJoin($join)
    {
        global $wpdb;
        remove_filter('posts_join', [$this, 'addPriceJoin'], 99);

        $join .= " LEFT JOIN {$wpdb->postmeta} price_meta ON ({$wpdb->posts}.ID = price_meta.post_id AND price_meta.meta_key = '_price') ";

        return $join;
    }

    public function addPriceWhere($where)
    {
        remove_filter('posts_where', [$this, 'addPriceWhere'], 99);
        return $where;
    }

    private function clearCache()
    {
        // Очищаем кэш WooCommerce
        if (function_exists('WC')) {
            \WC_Cache_Helper::get_transient_version('product', true);
            wc_delete_product_transients();
            
            if (WC()->cart) {
                WC()->cart->calculate_totals();
            }
        }

        // Очищаем внутренний кэш курсов
        $this->rates_cache = [];

        // Очищаем кэш страниц
        wp_cache_flush();
    }

    public function checkCurrencyAvailability()
    {
        check_ajax_referer('scm_admin', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения операции.']);
        }

        $currency = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : '';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (empty($currency)) {
            wp_send_json_error(['message' => 'Не указан код валюты.']);
        }

        // Проверяем, не используется ли уже этот код валюты
        $args = [
            'post_type' => 'currency',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'currency_code',
                    'value' => $currency
                ]
            ]
        ];

        // Если это редактирование существующей валюты, исключаем её из проверки
        if ($post_id > 0) {
            $args['post__not_in'] = [$post_id];
        }

        $existing_currency = get_posts($args);

        if (!empty($existing_currency)) {
            wp_send_json_error([
                'message' => 'Эта валюта уже используется в системе. Пожалуйста, выберите другую валюту.'
            ]);
        }

        wp_send_json_success(['message' => 'Валюта доступна для использования.']);
    }

    public function convertSimplePrice($price, $product)
    {
        if (empty($price)) {
            return $price;
        }

        $current_currency = $this->getCurrentCurrency();

        // Если текущая валюта совпадает с основной - ничего не меняем
        if ($current_currency === $this->default_currency) {
            return $price;
        }

        // Получаем курс валюты
        $rate = $this->getCurrencyRate($current_currency);
        if (!$rate) {
            return $price;
        }

        // Конвертируем цену
        return $price * $rate;
    }

    public function saveCurrencyMeta($post_id, $post, $update = null)
    {
        // Проверяем права доступа
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Сохраняем метаданные без проверки nonce (временно)
        if (isset($_POST['currency_symbol'])) {
            update_post_meta($post_id, 'currency_symbol', sanitize_text_field($_POST['currency_symbol']));
        }
        if (isset($_POST['currency_position'])) {
            update_post_meta($post_id, 'currency_position', sanitize_text_field($_POST['currency_position']));
        }
        if (isset($_POST['currency_rate'])) {
            update_post_meta($post_id, 'currency_rate', (float)$_POST['currency_rate']);
        }
        if (isset($_POST['currency_code'])) {
            update_post_meta($post_id, 'currency_code', sanitize_text_field($_POST['currency_code']));
        }
        if (isset($_POST['is_active'])) {
            update_post_meta($post_id, 'is_active', '1');
        } else {
            update_post_meta($post_id, 'is_active', '0');
        }
        if (isset($_POST['auto_update'])) {
            update_post_meta($post_id, 'auto_update', '1');
        } else {
            update_post_meta($post_id, 'auto_update', '0');
        }

        // Очищаем кэш после сохранения
        $this->clearCache();
    }

    public function handleCurrencyParameter()
    {
        // Проверяем наличие параметра new_currency
        if (isset($_GET['new_currency'])) {
            $new_currency = sanitize_text_field($_GET['new_currency']);
            
            // Устанавливаем новую валюту
            if ($this->setUserCurrency($new_currency)) {
                // Удаляем параметр из URL и делаем редирект
                $redirect_url = remove_query_arg('new_currency');
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    // Добавляем новый метод для конвертации цен продуктов
    public function convertProductPrice($price, $product)
    {
        static $is_converting = false;

        if ($is_converting || empty($price) || !is_numeric($price)) {
            return $price;
        }

        $is_converting = true;

        $current_currency = $this->getCurrentCurrency();
        
        if ($current_currency === $this->default_currency) {
            $is_converting = false;
            return $price;
        }

        // Пробуем получить оригинальную цену
        $product_id = $product->get_id();
        $original_price = '';
        
        if ($product->is_on_sale()) {
            $original_price = get_post_meta($product_id, '_original_sale_price', true);
        } else {
            $original_price = get_post_meta($product_id, '_original_regular_price', true);
        }

        // Если оригинальная цена существует, используем её
        if ($original_price !== '') {
            $price = $original_price;
        }

        $rate = $this->getCurrencyRate($current_currency);
        if (!$rate) {
            $is_converting = false;
            return $price;
        }

        $is_converting = false;
        return $price * $rate;
    }

    // Методы для фильтрации цен в корзине
    public function filterCartProductSubtotal($subtotal, $product, $quantity, $cart)
    {
        $price = $product->get_price();
        return wc_price($price * $quantity);
    }

    public function filterCartSubtotal($subtotal, $compound, $cart)
    {
        return wc_price($cart->get_subtotal());
    }

    public function filterCartTotal($total)
    {
        if (WC()->cart) {
            return wc_price(WC()->cart->get_total('edit'));
        }
        return $total;
    }

    /**
     * Сохраняем оригинальную цену при сохранении товара
     */
    public function saveOriginalPrice($post_id) 
    {
        // Получаем текущую цену товара
        $product = wc_get_product($post_id);
        if (!$product) return;

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();

        // Сохраняем оригинальную цену как число
        if ($regular_price) {
            update_post_meta($post_id, '_original_regular_price', $regular_price);
            // Сохраняем отформатированную цену
            update_post_meta($post_id, '_original_regular_price_formatted', 
                wc_price($regular_price, ['currency' => $this->default_currency]));
        }

        if ($sale_price) {
            update_post_meta($post_id, '_original_sale_price', $sale_price);
            update_post_meta($post_id, '_original_sale_price_formatted', 
                wc_price($sale_price, ['currency' => $this->default_currency]));
        }
    }

    /**
     * Добавляем колонку в список товаров
     */
    public function addOriginalPriceColumn($columns)
    {
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'price') {
                $new_columns['original_price'] = 'Оригинальная цена (' . $this->default_currency . ')';
            }
        }
        return $new_columns;
    }

    /**
     * Выводим оригинальную цену в колонке
     */
    public function renderOriginalPriceColumn($column, $post_id)
    {
        if ($column === 'original_price') {
            $regular_price = get_post_meta($post_id, '_original_regular_price_formatted', true);
            $sale_price = get_post_meta($post_id, '_original_sale_price_formatted', true);
            
            if ($sale_price) {
                echo '<del>' . $regular_price . '</del> ' . $sale_price;
            } else {
                echo $regular_price;
            }
        }
    }

    public function getOriginalPrice($product_id, $formatted = false) 
    {
        $product = wc_get_product($product_id);
        if (!$product) return false;

        if ($product->is_on_sale()) {
            return $formatted 
                ? get_post_meta($product_id, '_original_sale_price_formatted', true)
                : get_post_meta($product_id, '_original_sale_price', true);
        }

        return $formatted 
            ? get_post_meta($product_id, '_original_regular_price_formatted', true)
            : get_post_meta($product_id, '_original_regular_price', true);
    }

    public function getOriginalRegularPrice($product_id, $formatted = false) 
    {
        return $formatted 
            ? get_post_meta($product_id, '_original_regular_price_formatted', true)
            : get_post_meta($product_id, '_original_regular_price', true);
    }

    public function getOriginalSalePrice($product_id, $formatted = false) 
    {
        return $formatted 
            ? get_post_meta($product_id, '_original_sale_price_formatted', true)
            : get_post_meta($product_id, '_original_sale_price', true);
    }

    /**
     * AJAX обработчик для обновления курсов валют
     */
    public function ajaxUpdateAllRates()
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
            return;
        }

        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'currency_action')) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
            return;
        }

        try {
            // Получаем все активные валюты
            $currencies = get_posts([
                'post_type' => 'currency',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'auto_update',
                        'value' => '1'
                    ]
                ]
            ]);

            $updated = 0;
            $errors = [];

            foreach ($currencies as $currency) {
                $currency_code = get_post_meta($currency->ID, 'currency_code', true);
                
                // Пропускаем основную валюту
                if ($currency_code === $this->default_currency) {
                    continue;
                }

                try {
                    // Получаем курс через API
                    $rate = $this->fetchExchangeRate($this->default_currency, $currency_code);
                    
                    if ($rate) {
                        update_post_meta($currency->ID, 'currency_rate', $rate);
                        $updated++;
                    }
                } catch (Exception $e) {
                    $errors[] = sprintf('Ошибка обновления %s: %s', $currency_code, $e->getMessage());
                }
            }

            // Очищаем кэш
            $this->clearCache();

            if (!empty($errors)) {
                wp_send_json_error([
                    'message' => sprintf('Обновлено валют: %d. Ошибки: %s', $updated, implode(', ', $errors))
                ]);
                return;
            }

            wp_send_json_success([
                'message' => sprintf('Успешно обновлено валют: %d', $updated)
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Ошибка обновления курсов: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Получение курса валюты через API
     */
    private function fetchExchangeRate($from_currency, $to_currency)
    {
        // API URL (можно заменить на другой сервис)
        $api_url = sprintf(
            'https://api.exchangerate-api.com/v4/latest/%s',
            urlencode($from_currency)
        );

        // Получаем данные
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['rates'][$to_currency])) {
            throw new Exception('Курс не найден');
        }

        return $data['rates'][$to_currency];
    }

    public function getCurrencies()
    {
        // Получаем текущую валюту
        $current_currency = $this->getCurrentCurrency();
        
        // Получаем все активные валюты
        $posts = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'is_active',
                    'value' => '1'
                ]
            ],
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        // Разделяем текущую валюту и остальные
        $current = null;
        $others = [];

        foreach ($posts as $post) {
            $code = get_post_meta($post->ID, 'currency_code', true);
            $currency_data = [
                'code' => $code,
                'name' => $post->post_title,
                'symbol' => get_post_meta($post->ID, 'currency_symbol', true),
                'rate' => get_post_meta($post->ID, 'currency_rate', true),
                'position' => get_post_meta($post->ID, 'currency_position', true)
            ];
            
            if ($code === $current_currency) {
                $current = $currency_data;
            } else {
                $others[] = $currency_data;
            }
        }

        // Объединяем массивы: сначала текущая валюта, потом остальные
        return array_merge(
            $current ? [$current] : [],
            $others
        );
    }
}
