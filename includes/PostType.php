<?php

namespace Saroroce\CurrencyManager;

class PostType {
    private $default_currency;

    public function __construct() {
        $this->default_currency = get_option('woocommerce_currency');

        add_action('init', [$this, 'registerPostType']);
        add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
        add_action('save_post_currency', [$this, 'saveMetaBoxes'], 10, 2);
        add_filter('manage_currency_posts_columns', [$this, 'addColumns']);
        add_action('manage_currency_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-currency_sortable_columns', [$this, 'setSortableColumns']);
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_notices', [$this, 'renderAdminNotices']);
        add_filter('post_row_actions', [$this, 'modifyRowActions'], 10, 2);
        add_action('woocommerce_settings_saved', [$this, 'handleWoocommerceCurrencyChange']);
        add_action('before_delete_post', [$this, 'preventDefaultCurrencyDeletion']);
        add_action('wp_trash_post', [$this, 'preventDefaultCurrencyTrash']);
        add_action('admin_init', [$this, 'checkDefaultCurrency']);

        // AJAX обработчики
        add_action('wp_ajax_update_all_rates', [$this, 'ajaxUpdateAllRates']);
        add_action('wp_ajax_import_currencies', [$this, 'ajaxImportCurrencies']);

        // Добавляем подключение скриптов
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
    }

    public function registerPostType() {
        register_post_type('currency', [
            'labels' => [
                'name' => 'Валюты',
                'singular_name' => 'Валюта',
                'add_new' => 'Добавить валюту',
                'add_new_item' => 'Добавить новую валюту',
                'edit_item' => 'Редактировать валюту',
                'new_item' => 'Новая валюта',
                'view_item' => 'Просмотр валюты',
                'search_items' => 'Поиск валют',
                'not_found' => 'Валюты не найдены',
                'not_found_in_trash' => 'В корзине нет валют'
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-money-alt',
            'supports' => ['title'],
            'menu_position' => 56
        ]);
    }

    public function addMetaBoxes() {
        add_meta_box(
            'currency_details',
            'Параметры валюты',
            [$this, 'renderMetaBox'],
            'currency',
            'normal',
            'high'
        );
    }

    public function renderMetaBox($post) {
        wp_nonce_field('currency_meta_box', 'currency_meta_box_nonce');

        $code = get_post_meta($post->ID, 'currency_code', true);
        $symbol = get_post_meta($post->ID, 'currency_symbol', true);
        $rate = get_post_meta($post->ID, 'currency_rate', true);
        $position = get_post_meta($post->ID, 'currency_position', true) ?: 'left';
        $auto_update = get_post_meta($post->ID, 'auto_update', true);
        $is_active = get_post_meta($post->ID, 'is_active', true);
        
        // Проверяем, является ли валюта основной в WooCommerce
        $base_currency = get_woocommerce_currency();
        $is_base = ($code === $base_currency);

        // Показываем уведомление только если это основная валюта WooCommerce
        if ($is_base) {
            ?>
            <div class="notice notice-info">
                <p>Это основная валюта магазина WooCommerce (<?php echo esc_html($base_currency); ?>). Её нельзя изменить или удалить.</p>
            </div>
            <?php
        }

        ?>
        <table class="form-table">
            <tr>
                <th><label for="currency_code">Код валюты</label></th>
                <td>
                    <select id="currency_code" name="currency_code" <?php echo $is_base ? 'disabled' : ''; ?>>
                        <option value="">Выберите валюту</option>
                        <?php 
                        $wc_currencies = get_woocommerce_currencies();
                        
                        // Получаем все используемые валюты
                        $used_currencies = [];
                        $args = [
                            'post_type' => 'currency',
                            'posts_per_page' => -1,
                            'post__not_in' => [$post->ID],
                            'meta_query' => [
                                [
                                    'key' => 'currency_code',
                                    'compare' => 'EXISTS'
                                ]
                            ]
                        ];
                        
                        $existing_currencies = get_posts($args);
                        foreach ($existing_currencies as $currency) {
                            $used_currencies[] = get_post_meta($currency->ID, 'currency_code', true);
                        }
                        
                        foreach ($wc_currencies as $currency_code => $currency_name):
                            $is_used = in_array($currency_code, $used_currencies);
                            $is_current = $currency_code === $code;
                            $disabled = ($is_used && !$is_current) ? 'disabled' : '';
                        ?>
                            <option value="<?php echo esc_attr($currency_code); ?>" 
                                <?php selected($code, $currency_code); ?> 
                                <?php echo $disabled; ?>>
                                <?php 
                                echo esc_html($currency_name) . ' (' . $currency_code . ')';
                                if ($is_used && !$is_current) {
                                    echo ' - уже используется';
                                }
                                if ($currency_code === $base_currency && !$is_current) {
                                    echo ' - основная валюта WooCommerce';
                                }
                                ?>
                            </option>
                        <?php 
                        endforeach; 
                        ?>
                    </select>
                    <?php if ($is_base): ?>
                        <input type="hidden" name="currency_code" value="<?php echo esc_attr($code); ?>">
                        <p class="description">Это основная валюта WooCommerce. Её нельзя изменить.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="currency_symbol">Символ валюты</label></th>
                <td>
                    <input type="text" id="currency_symbol" name="currency_symbol" 
                           value="<?php echo esc_attr($symbol); ?>" 
                           <?php echo $is_base ? 'readonly' : ''; ?>>
                </td>
            </tr>
            <tr>
                <th><label for="currency_rate">Курс валюты</label></th>
                <td>
                    <input type="number" step="0.000001" id="currency_rate" name="currency_rate" 
                           value="<?php echo esc_attr($rate); ?>" 
                           <?php echo $is_base ? 'readonly' : ''; ?>>
                    <?php if ($is_base): ?>
                        <p class="description">Курс основной валюты всегда равен 1.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="currency_position">Позиция символа</label></th>
                <td>
                    <select name="currency_position" id="currency_position" <?php echo $is_base ? 'disabled' : ''; ?>>
                        <option value="left" <?php selected($position, 'left'); ?>>Слева (P99.99)</option>
                        <option value="right" <?php selected($position, 'right'); ?>>Справа (99.99P)</option>
                        <option value="left_space" <?php selected($position, 'left_space'); ?>>Слева с пробелом (P 99.99)</option>
                        <option value="right_space" <?php selected($position, 'right_space'); ?>>Справа с пробелом (99.99 P)</option>
                    </select>
                    <?php if ($is_base): ?>
                        <input type="hidden" name="currency_position" value="<?php echo esc_attr($position); ?>">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="auto_update">Автообновление</label></th>
                <td>
                    <input type="checkbox" id="auto_update" name="auto_update" value="1" 
                           <?php checked($auto_update, '1'); ?>>
                </td>
            </tr>
            <tr>
                <th><label for="is_active">Активна</label></th>
                <td>
                    <input type="checkbox" id="is_active" name="is_active" value="1" 
                           <?php checked($is_active, '1'); ?>>
                </td>
            </tr>
        </table>
        <?php
    }

    public function saveMetaBoxes($post_id, $post) {
        // Базовые проверки
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ($post->post_type !== 'currency') return;

        // Сохраняем все поля
        $fields = [
            'currency_code',
            'currency_symbol',
            'currency_rate',
            'currency_position',
            'auto_update',
            'is_active'
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = sanitize_text_field($_POST[$field]);
                update_post_meta($post_id, $field, $value);
            }
        }

        // Специальная обработка для чекбоксов
        update_post_meta($post_id, 'auto_update', isset($_POST['auto_update']) ? '1' : '0');
        update_post_meta($post_id, 'is_active', isset($_POST['is_active']) ? '1' : '0');

        // Очищаем кэш
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::get_transient_version('product', true);
            wc_delete_product_transients();
        }
    }

    public function addColumns($columns) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = 'Название';
        $new_columns['currency_code'] = 'Код';
        $new_columns['currency_symbol'] = 'Символ';
        $new_columns['currency_rate'] = 'Курс';
        $new_columns['auto_update'] = 'Автообновление';
        $new_columns['is_active'] = 'Активна';
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public function renderColumn($column, $post_id) {
        switch ($column) {
            case 'currency_code':
                echo esc_html(get_post_meta($post_id, 'currency_code', true));
                break;
            case 'currency_symbol':
                echo esc_html(get_post_meta($post_id, 'currency_symbol', true));
                break;
            case 'currency_rate':
                echo esc_html(get_post_meta($post_id, 'currency_rate', true));
                break;
            case 'auto_update':
                $auto_update = get_post_meta($post_id, 'auto_update', true);
                if (!empty($auto_update)) {
                    echo '<span class="dashicons dashicons-yes" style="color: #46b450;"></span>';
                } else {
                    echo '<span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span>';
                }
                break;
            case 'is_active':
                $is_active = get_post_meta($post_id, 'is_active', true);
                if (!empty($is_active)) {
                    echo '<span class="dashicons dashicons-yes" style="color: #46b450;"></span>';
                } else {
                    echo '<span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span>';
                }
                break;
        }
    }

    public function setSortableColumns($columns) {
        $columns['currency_code'] = 'currency_code';
        $columns['currency_rate'] = 'currency_rate';
        return $columns;
    }

    public function addSettingsPage() {
        add_submenu_page(
            'edit.php?post_type=currency',
            'Настройки валют',
            'Настройки',
            'manage_options',
            'currency-settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings() {
        register_setting('currency_settings', 'currency_auto_update_enabled');
        register_setting('currency_settings', 'currency_update_interval');
        register_setting('currency_settings', 'scm_auto_detect_currency');
    }

    public function renderSettingsPage() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="currency-settings-page">
                <!-- Добавляем кнопки -->
                <div class="currency-actions">
                    <button type="button" id="import-currencies" class="button button-primary">
                        <span class="dashicons dashicons-download"></span>
                        Импортировать валюты
                    </button>
                    <button type="button" id="update-rates-manual" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        Обновить курсы валют
                    </button>
                </div>

                <form method="post" action="options.php">
                    <?php
                    settings_fields('currency_settings');
                    $auto_update = get_option('currency_auto_update_enabled', '0');
                    $interval = get_option('currency_update_interval', 'daily');
                    ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Автоматическое обновление курсов</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="currency_auto_update_enabled" value="1" <?php checked($auto_update, '1'); ?>>
                                    Включить автоматическое обновление курсов валют
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Автоопределение валюты</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="scm_auto_detect_currency" value="1" <?php checked(get_option('scm_auto_detect_currency', '0'), '1'); ?>>
                                    Включить автоматическое определение валюты по стране посетителя
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Интервал обновления</th>
                            <td>
                                <select name="currency_update_interval">
                                    <option value="hourly" <?php selected($interval, 'hourly'); ?>>Каждый час</option>
                                    <option value="twicedaily" <?php selected($interval, 'twicedaily'); ?>>Два раза в день</option>
                                    <option value="daily" <?php selected($interval, 'daily'); ?>>Раз в день</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function updateAllRates() {
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

        if (empty($currencies)) {
            return 0;
        }

        // Получаем курсы через API
        $response = wp_remote_get('https://api.exchangerate-api.com/v4/latest/' . $this->default_currency);
        
        if (is_wp_error($response)) {
            return 0;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['rates'])) {
            return 0;
        }

        $updated = 0;
        foreach ($currencies as $currency) {
            $code = get_post_meta($currency->ID, 'currency_code', true);
            if (isset($data['rates'][$code])) {
                update_post_meta($currency->ID, 'currency_rate', $data['rates'][$code]);
                $updated++;
            }
        }

        return $updated;
    }

    public function ajaxUpdateAllRates() {
        check_ajax_referer('currency_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        // Проверяем наличие валют для обновления
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

        if (empty($currencies)) {
            wp_send_json_error(['message' => 'Нет валют для обновления. Сначала импортируйте валюты.']);
            return;
        }

        $updated = $this->updateAllRates();
        
        if ($updated > 0) {
            wp_send_json_success([
                'message' => sprintf('Обновлено курсов: %d', $updated)
            ]);
        } else {
            wp_send_json_error(['message' => 'Не удалось обновить курсы валют. Проверьте подключение к интернету или попробуйте позже.']);
        }
    }

    public function ajaxImportCurrencies() {
        check_ajax_referer('currency_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        $wc_currencies = get_woocommerce_currencies();
        
        // Проверяем существующие валюты
        $existing_count = 0;
        foreach ($wc_currencies as $code => $name) {
            if ($code === $this->default_currency) {
                continue;
            }
            
            $existing = get_posts([
                'post_type' => 'currency',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'currency_code',
                        'value' => $code
                    ],
                    [
                        'key' => 'is_active',
                        'value' => '1'
                    ]
                ],
                'posts_per_page' => 1
            ]);
            
            if (!empty($existing)) {
                $existing_count++;
            }
        }

        // Если все валюты уже импортированы
        if ($existing_count === count($wc_currencies) - 1) { // -1 для основной валюты
            wp_send_json_error(['message' => 'Все доступные валюты уже импортированы.']);
            return;
        }

        $imported = 0;
        foreach ($wc_currencies as $code => $name) {
            if ($code === $this->default_currency) {
                continue;
            }

            $existing = get_posts([
                'post_type' => 'currency',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'currency_code',
                        'value' => $code
                    ],
                    [
                        'key' => 'is_active',
                        'value' => '1'
                    ]
                ],
                'posts_per_page' => 1
            ]);

            if (empty($existing)) {
                $post_id = wp_insert_post([
                    'post_title' => $name,
                    'post_type' => 'currency',
                    'post_status' => 'publish'
                ]);

                if ($post_id) {
                    // Получаем массив символов валют WooCommerce
                    $currency_symbols = get_woocommerce_currency_symbols();
                    
                    // Устанавливаем метаданные с явным указанием значений
                    $meta_data = [
                        'currency_code' => $code,
                        'currency_symbol' => $currency_symbols[$code] ?? $code, // Используем символ из массива или код валюты как запасной вариант
                        'currency_rate' => '0',
                        'auto_update' => '1',
                        'is_active' => '1'
                    ];

                    foreach ($meta_data as $key => $value) {
                        update_post_meta($post_id, $key, $value);
                    }

                    $imported++;
                }
            }
        }

        if ($imported > 0) {
            // Обновляем курсы для новых валют
            $this->updateAllRates();
            wp_send_json_success([
                'message' => sprintf('Импортировано новых валют: %d', $imported)
            ]);
        } else {
            wp_send_json_error(['message' => 'Не удалось импортировать новые валюты.']);
        }
    }

    public function handleWoocommerceCurrencyChange() {
        $new_default = get_option('woocommerce_currency');
        if ($new_default !== $this->default_currency) {
            // Получаем старую основную валюту
            $old_default = get_posts([
                'post_type' => 'currency',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'is_default',
                        'value' => '1'
                    ]
                ]
            ]);

            // Снимаем флаг is_default со старой валюты
            foreach ($old_default as $old) {
                update_post_meta($old->ID, 'is_default', '0');
            }

            // Проверяем существование новой основной валюты
            $existing = get_posts([
                'post_type' => 'currency',
                'meta_query' => [
                    [
                        'key' => 'currency_code',
                        'value' => $new_default
                    ]
                ]
            ]);

            if (!empty($existing)) {
                // Временно отключаем проверку на удаление основной валюты
                remove_action('before_delete_post', [$this, 'preventDefaultCurrencyDeletion']);
                remove_action('wp_trash_post', [$this, 'preventDefaultCurrencyTrash']);
                
                // Если валюта уже существует, делаем её основной
                update_post_meta($existing[0]->ID, 'is_default', '1');
                update_post_meta($existing[0]->ID, 'currency_rate', '1');
                update_post_meta($existing[0]->ID, 'is_active', '1');
                
                // Удаляем дубликаты если есть
                if (count($existing) > 1) {
                    for ($i = 1; $i < count($existing); $i++) {
                        wp_delete_post($existing[$i]->ID, true);
                    }
                }
                
                // Восстанавливаем проверку
                add_action('before_delete_post', [$this, 'preventDefaultCurrencyDeletion']);
                add_action('wp_trash_post', [$this, 'preventDefaultCurrencyTrash']);
            } else {
                // Создаем новую основную валюту
                $wc_currencies = get_woocommerce_currencies();
                $post_id = wp_insert_post([
                    'post_title' => $wc_currencies[$new_default],
                    'post_type' => 'currency',
                    'post_status' => 'publish'
                ]);

                if ($post_id) {
                    update_post_meta($post_id, 'currency_code', $new_default);
                    update_post_meta($post_id, 'currency_symbol', get_woocommerce_currency_symbol($new_default));
                    update_post_meta($post_id, 'currency_rate', '1');
                    update_post_meta($post_id, 'currency_position', get_option('woocommerce_currency_pos', 'left'));
                    update_post_meta($post_id, 'is_active', '1');
                    update_post_meta($post_id, 'is_default', '1');
                    update_post_meta($post_id, 'auto_update', '1');
                }
            }

            // Обновляем курсы всех валют относительно новой основной
            $this->updateAllRates();
        }
    }

    public function preventDefaultCurrencyDeletion($post_id) {
        if (get_post_type($post_id) !== 'currency') {
            return;
        }

        $currency_code = get_post_meta($post_id, 'currency_code', true);
        $base_currency = get_woocommerce_currency();

        if ($currency_code === $base_currency) {
            wp_die('Нельзя удалить основную валюту магазина.', 'Ошибка', ['back_link' => true]);
        }
    }

    public function preventDefaultCurrencyTrash($post_id) {
        if (get_post_type($post_id) !== 'currency') {
            return;
        }

        $currency_code = get_post_meta($post_id, 'currency_code', true);
        $base_currency = get_woocommerce_currency();

        if ($currency_code === $base_currency) {
            wp_die('Нельзя поместить основную валюту магазина в корзину.', 'Ошибка', ['back_link' => true]);
        }
    }

    public function checkDefaultCurrency() {
        $base_currency = get_woocommerce_currency();
        
        // Проверяем существование валют с этим кодом
        $existing = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'currency_code',
                    'value' => $base_currency
                ]
            ]
        ]);

        if (empty($existing)) {
            // Если валюты нет, создаем её
            $wc_currencies = get_woocommerce_currencies();
            $post_id = wp_insert_post([
                'post_title' => $wc_currencies[$base_currency],
                'post_type' => 'currency',
                'post_status' => 'publish'
            ]);

            if ($post_id) {
                update_post_meta($post_id, 'currency_code', $base_currency);
                update_post_meta($post_id, 'currency_symbol', get_woocommerce_currency_symbol($base_currency));
                update_post_meta($post_id, 'currency_rate', '1');
                update_post_meta($post_id, 'currency_position', get_option('woocommerce_currency_pos', 'left'));
                update_post_meta($post_id, 'is_active', '1');
                update_post_meta($post_id, 'is_default', '1');
                update_post_meta($post_id, 'auto_update', '1');
            }
        } else {
            // Если есть дубликаты, оставляем только одну запись
            if (count($existing) > 1) {
                // Первую запись делаем основной
                update_post_meta($existing[0]->ID, 'is_default', '1');
                update_post_meta($existing[0]->ID, 'currency_rate', '1');
                update_post_meta($existing[0]->ID, 'is_active', '1');
                
                // Временно отключаем проверку на удаление основной валюты
                remove_action('before_delete_post', [$this, 'preventDefaultCurrencyDeletion']);
                remove_action('wp_trash_post', [$this, 'preventDefaultCurrencyTrash']);
                
                // Удаляем дубликаты
                for ($i = 1; $i < count($existing); $i++) {
                    wp_delete_post($existing[$i]->ID, true);
                }
                
                // Восстанавливаем проверку
                add_action('before_delete_post', [$this, 'preventDefaultCurrencyDeletion']);
                add_action('wp_trash_post', [$this, 'preventDefaultCurrencyTrash']);
            } else {
                // Убеждаемся, что единственная запись помечена как основная
                update_post_meta($existing[0]->ID, 'is_default', '1');
                update_post_meta($existing[0]->ID, 'currency_rate', '1');
                update_post_meta($existing[0]->ID, 'is_active', '1');
            }
        }
    }

    public function modifyRowActions($actions, $post) {
        if ($post->post_type === 'currency') {
            $code = get_post_meta($post->ID, 'currency_code', true);
            if ($code === $this->default_currency) {
                unset($actions['trash']);
                unset($actions['delete']);
            }
        }
        return $actions;
    }

    public function modifyBulkActions($actions) {
        if (isset($actions['trash'])) {
            $actions['trash'] = 'Удалить (кроме основной валюты)';
        }
        return $actions;
    }

    public function renderAdminNotices() {
        $screen = get_current_screen();
        
        // Показываем уведомления только на страницах валют
        if (!$screen || !in_array($screen->id, ['currency', 'edit-currency', 'currency_page_currency-settings'])) {
            return;
        }

        // Проверяем наличие основной валюты
        $default_currency = get_posts([
            'post_type' => 'currency',
            'meta_key' => 'currency_code',
            'meta_value' => $this->default_currency,
            'posts_per_page' => 1
        ]);

        if (empty($default_currency)) {
            ?>
            <div class="notice notice-warning">
                <p>Основная валюта (<?php echo esc_html($this->default_currency); ?>) не найдена. <a href="#" id="import-currencies-notice">Импортировать валюты</a>?</p>
            </div>
            <?php
        }

        // Показываем уведомление об успешном импорте
        if (isset($_GET['imported']) && $_GET['imported'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Валюты успешно импортированы.</p>
            </div>
            <?php
        }

        // Показываем уведомление об успешном обновлении курсов
        if (isset($_GET['rates_updated']) && $_GET['rates_updated'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Курсы валют успешно обновлены.</p>
            </div>
            <?php
        }
    }

    private function renderCurrencyFields($post) {
        // Получаем текущие значения
        $code = get_post_meta($post->ID, 'currency_code', true);
        $symbol = get_post_meta($post->ID, 'currency_symbol', true);
        $rate = get_post_meta($post->ID, 'currency_rate', true);
        $position = get_post_meta($post->ID, 'currency_position', true) ?: 'left';
        $is_default = get_post_meta($post->ID, 'is_default', true);
        $auto_update = get_post_meta($post->ID, 'auto_update', true);

        // Получаем все используемые валюты
        $used_currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => -1,
            'post__not_in' => [$post->ID], // Исключаем текущую валюту
            'meta_query' => [
                [
                    'key' => 'currency_code',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        $used_currency_codes = [];
        foreach ($used_currencies as $currency) {
            $used_currency_codes[] = get_post_meta($currency->ID, 'currency_code', true);
        }

        // Список всех доступных валют
        $available_currencies = [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'RUB' => 'Russian Ruble',
            'BGN' => 'Bulgarian Lev',
            'BYN' => 'Belarusian Ruble',
            'KZT' => 'Kazakhstani Tenge',
            'UAH' => 'Ukrainian Hryvnia',
            'CNY' => 'Chinese Yuan',
            'JPY' => 'Japanese Yen'
        ];

        ?>
        <table class="form-table">
            <tr>
                <th><label for="currency_code">Код валюты</label></th>
                <td>
                    <select name="currency_code" id="currency_code" <?php echo $is_default ? 'disabled' : ''; ?>>
                        <?php foreach ($available_currencies as $currency_code => $currency_name): 
                            $is_used = in_array($currency_code, $used_currency_codes);
                            $is_current = $currency_code === $code;
                            $disabled = $is_used && !$is_current ? 'disabled' : '';
                            $selected = $is_current ? 'selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($currency_code); ?>" 
                                    <?php echo $disabled; ?> 
                                    <?php echo $selected; ?>>
                                <?php echo esc_html($currency_name); ?> (<?php echo esc_html($currency_code); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($is_default): ?>
                        <input type="hidden" name="currency_code" value="<?php echo esc_attr($code); ?>">
                        <p class="description">Это основная валюта WooCommerce. Её нельзя изменить.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="currency_symbol">Символ валюты</label></th>
                <td>
                    <input type="text" id="currency_symbol" name="currency_symbol" 
                           value="<?php echo esc_attr($symbol); ?>" 
                           <?php echo $is_default ? 'readonly' : ''; ?>>
                </td>
            </tr>
            <tr>
                <th><label for="currency_rate">Курс валюты</label></th>
                <td>
                    <input type="number" step="0.000001" id="currency_rate" name="currency_rate" 
                           value="<?php echo esc_attr($rate); ?>" 
                           <?php echo $is_default ? 'readonly' : ''; ?>>
                    <?php if ($is_default): ?>
                        <p class="description">Курс основной валюты всегда равен 1.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="currency_position">Позиция символа</label></th>
                <td>
                    <select name="currency_position" id="currency_position" <?php echo $is_default ? 'disabled' : ''; ?>>
                        <option value="left" <?php selected($position, 'left'); ?>>Слева</option>
                        <option value="right" <?php selected($position, 'right'); ?>>Справа</option>
                        <option value="left_space" <?php selected($position, 'left_space'); ?>>Слева с пробелом</option>
                        <option value="right_space" <?php selected($position, 'right_space'); ?>>Справа с пробелом</option>
                    </select>
                    <?php if ($is_default): ?>
                        <input type="hidden" name="currency_position" value="<?php echo esc_attr($position); ?>">
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (!$is_default): ?>
            <tr>
                <th><label for="auto_update">Автообновление цены</label></th>
                <td>
                    <input type="checkbox" id="auto_update" name="auto_update" value="1" 
                           <?php checked(get_post_meta($post->ID, 'auto_update', true), '0'); ?>>
                    <label for="auto_update"><strong>Выключить автообновление цены</strong></label>
                    <p class="description">Отметьте, чтобы отключить автоматическое обновление курса через API</p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    // Добавляем подключение скриптов для админки
    public function enqueueAdminScripts($hook) 
    {
        // Подключаем скрипты только на нужных страницах
        if ($hook === 'currency_page_currency-settings' || 
            ($hook === 'post.php' && get_post_type() === 'currency') || 
            ($hook === 'post-new.php' && get_post_type() === 'currency')) {
            
            // Подключаем Select2
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                [],
                '4.1.0'
            );
            
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                ['jquery'],
                '4.1.0',
                true
            );

            // Подключаем наши скрипты
            wp_enqueue_style(
                'scm-admin',
                plugins_url('/assets/css/admin.css', SCM_FILE),
                ['select2'],
                SCM_VERSION
            );
            
            wp_enqueue_script(
                'scm-admin',
                plugins_url('/assets/js/admin.js', SCM_FILE),
                ['jquery', 'select2'],
                SCM_VERSION,
                true
            );

            wp_localize_script('scm-admin', 'scmAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('currency_action')
            ]);
        }
    }
} 