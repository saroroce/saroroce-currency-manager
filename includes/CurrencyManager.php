<?php

namespace Saroroce\CurrencyManager;

use Exception;
use WC_Geolocation;

class CurrencyManager
{
    private static $instance = null;
    private $cookie_name = 'scm_currency';
    private $cookie_expiry = YEAR_IN_SECONDS;
    private $default_currency = null;
    private static $initialized = false;
    private $current_currency = null;

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $this->default_currency = get_woocommerce_currency();

        // Добавляем обработку параметра new_currency
        add_action('init', [$this, 'handleCurrencyParameter']);

        // Добавляем автоопределение валюты
        add_action('wp_loaded', [$this, 'maybeSetCurrencyByIp'], 20);

        // Основные фильтры для цен WooCommerce
        // add_filter('woocommerce_product_get_price', [$this, 'convertProductPrice'], 10, 2);
        // add_filter('woocommerce_product_get_regular_price', [$this, 'convertProductPrice'], 10, 2);
        // add_filter('woocommerce_product_get_sale_price', [$this, 'convertProductPrice'], 10, 2);
        add_filter('wc_price', [$this, 'filterPrice'], 10, 5);
        // add_filter('woocommerce_product_variation_get_price', [$this, 'convertProductPrice'], 10, 2);
        // add_filter('woocommerce_product_variation_get_regular_price', [$this, 'convertProductPrice'], 10, 2);
        // add_filter('woocommerce_product_variation_get_sale_price', [$this, 'convertProductPrice'], 10, 2);

        // Фильтры для отображения
        add_filter('woocommerce_currency_symbol', [$this, 'filterWoocommerceCurrencySymbol'], 10, 2);
        add_filter('woocommerce_price_format', [$this, 'getPriceFormat'], 10, 2);

        // Добавляем фильтр валюты только для фронтенда
        if (!is_admin()) {
            add_filter('woocommerce_currency', [$this, 'filterWoocommerceCurrency'], 999, 1);
        }

        // AJAX обработчики
        add_action('wp_ajax_update_all_rates', [$this, 'ajaxUpdateAllRates']);

        // Добавляем хуки для сохранения оригинальной цены
        add_action('woocommerce_process_product_meta', [$this, 'saveOriginalPrice'], 10, 1);
        add_action('woocommerce_save_product_variation', [$this, 'saveOriginalPrice'], 10, 1);

        // Добавляем колонку с оригинальной ценой в админке
        add_filter('manage_product_posts_columns', [$this, 'addOriginalPriceColumn']);
        add_action('manage_product_posts_custom_column', [$this, 'renderOriginalPriceColumn'], 10, 2);
    }

    public function __clone() {}

    /**
     * Магический метод для десериализации объекта
     */
    public function __wakeup() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }

    public function filterPrice($return, $price, $args, $unformatted_price, $original_price)
    {
        static $is_filtering = false;

        // Защита от рекурсии
        if ($is_filtering) {
            return $return;
        }

        $is_filtering = true;

        $this->current_currency = $this->getCurrentCurrency();

        // Если текущая валюта совпадает с основной - возвращаем исходное значение
        if ($this->current_currency === $this->default_currency) {
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
                    'value' => $this->current_currency
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
        $args['currency'] = $this->current_currency;
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
        $current_currency = isset($_COOKIE[$this->cookie_name]) ? $_COOKIE[$this->cookie_name] : $this->default_currency;
        
        // Всегда устанавливаем актуальные заголовки если возможно
        if (!headers_sent()) {
            header_remove('X-' . $this->cookie_name); // Удаляем старый заголовок если есть
            header('Vary: Cookie, X-' . $this->cookie_name);
            header('X-' . $this->cookie_name . ': ' . $current_currency);
        }
        
        return $current_currency;
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
        // Получаем основной домен
        $domain = '';
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $domain = COOKIE_DOMAIN;
        } else {
            $parsed = parse_url(home_url());
            $domain = isset($parsed['host']) ? $parsed['host'] : '';
            // Убираем www если есть
            if (strpos($domain, 'www.') === 0) {
                $domain = substr($domain, 4);
            }
        }
        
        // Устанавливаем куки с правильными параметрами
        $secure = is_ssl();
        
        setcookie(
            $this->cookie_name,
            $currency_code,
            [
                'expires' => time() + $this->cookie_expiry,
                'path' => '/',
                'domain' => '.' . $domain, // Добавляем точку перед доменом для работы на поддоменах
                'secure' => $secure,
                'httponly' => false,
                'samesite' => 'Lax'
            ]
        );
        
        // Устанавливаем куки также в $_COOKIE для немедленного использования
        $_COOKIE[$this->cookie_name] = $currency_code;
        
        if (!headers_sent()) {
            header('Vary: X-' . $this->cookie_name);
            header('X-' . $this->cookie_name . ': ' . $currency_code);
        }
        
        return $currency_code;
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
        $rates = [];
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
            $rates['single_' . $currency_code] = false;
        } else {
            $rate = get_post_meta($currencies[0]->ID, 'currency_rate', true);
            $rates['single_' . $currency_code] = $rate ? (float) $rate : false;
        }

        return $rates['single_' . $currency_code];
    }

    public function maybeSetCurrencyByIp()
    {

        // Проверяем настройку автоопределения
        $auto_detect = get_option('scm_auto_detect_currency', '0');

        if ($auto_detect !== '1') {
            return;
        }

        // Проверяем, установлена ли уже валюта
        if (isset($_COOKIE[$this->cookie_name])) {
            return;
        }

        // Получаем геолокацию через WooCommerce
        $location = WC_Geolocation::geolocate_ip();

        if (!empty($location['country'])) {
            $country_code = $location['country'];

            // Пробуем получить валюту через WooCommerce
            $currency = $this->getWooCommerceCurrencyByCountry($country_code);

            // Проверяем существование валюты в нашей системе
            if ($currency && $this->quickCurrencyCheck($currency)) {
                $this->setUserCurrency($currency);
                return;
            }
        }

        // Если не удалось определить валюту, устанавливаем валюту по умолчанию
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
            'DE',
            'FR',
            'IT',
            'ES',
            'PT',
            'GR',
            'AT',
            'BE',
            'IE',
            'NL',
            'FI',
            'SK',
            'SI',
            'EE',
            'LV',
            'LT',
            'LU',
            'MT',
            'CY'
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
                [
                    'key' => 'currency_code',
                    'value' => $currency
                ]
            ]
        ]);

        if (!empty($currencies)) {
            $position = get_post_meta($currencies[0]->ID, 'currency_position', true);
            if ($position === 'default') {
                return $format; // Возвращаем стандартный формат WooCommerce
            }
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

        $this->current_currency = $this->getCurrentCurrency();

        // Если текущая валюта совпадает с основной - ничего не меняем
        if ($this->current_currency === $this->default_currency) {
            return $price;
        }

        // Получаем курс валюты
        $rate = $this->getCurrencyRate($this->current_currency);
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
    }

    public function handleCurrencyParameter()
    {
        if (isset($_GET['new_currency'])) {
            $new_currency = sanitize_text_field($_GET['new_currency']);
            
            // Устанавливаем валюту
            $this->setUserCurrency($new_currency);
            
            // Получаем текущий URL без параметра new_currency
            $redirect_url = remove_query_arg('new_currency');
            
            // Делаем редирект с сохранением куки
            wp_redirect($redirect_url, 302);
            exit;
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

        $this->current_currency = $this->getCurrentCurrency();

        if ($this->current_currency === $this->default_currency) {
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

        $rate = $this->getCurrencyRate($this->current_currency);
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
            update_post_meta(
                $post_id,
                '_original_regular_price_formatted',
                wc_price($regular_price, ['currency' => $this->default_currency])
            );
        }

        if ($sale_price) {
            update_post_meta($post_id, '_original_sale_price', $sale_price);
            update_post_meta(
                $post_id,
                '_original_sale_price_formatted',
                wc_price($sale_price, ['currency' => $this->default_currency])
            );
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
        $this->current_currency = $this->getCurrentCurrency();

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

            if ($code === $this->current_currency) {
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

    public function init()
    {
        if (!isset($_COOKIE[$this->cookie_name])) {
            $this->setUserCurrency($this->getCurrentCurrency());
        }
    }

    /**
     * Конвертирует сумму в текущую валюту без форматирования
     * @param float $amount Сумма для конвертации
     * @return float Сконвертированная сумма
     */
    public function convertAmount($amount)
    {
        if (empty($amount) || !is_numeric($amount)) {
            return $amount;
        }

        $current_currency = $this->getCurrentCurrency();

        // Если текущая валюта совпадает с основной - возвращаем исходное значение
        if ($current_currency === $this->default_currency) {
            return $amount;
        }

        // Получаем курс валюты
        $rate = $this->getCurrencyRate($current_currency);
        if (!$rate) {
            return $amount;
        }

        return $amount * floatval($rate);
    }
}
