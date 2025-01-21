<?php

namespace Saroroce\CurrencyManager\Admin;

class Settings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('wp_ajax_reset_currency_display', [$this, 'ajaxResetCurrencyDisplay']);
    }

    public function addSettingsPage()
    {
        add_submenu_page(
            'edit.php?post_type=currency',
            __('Настройки валют', 'saroroce-currency-manager'),
            __('Настройки', 'saroroce-currency-manager'),
            'manage_options',
            'currency-settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings()
    {
        register_setting('scm_settings', 'scm_auto_detect_currency');
        register_setting('scm_settings', 'scm_auto_update_enabled');
        register_setting('scm_settings', 'scm_update_interval');

        add_settings_section(
            'scm_main_section',
            __('Основные настройки', 'saroroce-currency-manager'),
            null,
            'currency-settings'
        );

        // Автоопределение валюты
        add_settings_field(
            'scm_auto_detect_currency',
            __('Автоопределение валюты', 'saroroce-currency-manager'),
            [$this, 'renderAutoDetectField'],
            'currency-settings',
            'scm_main_section'
        );

        // Автообновление курсов
        add_settings_field(
            'scm_auto_update_enabled',
            __('Автообновление курсов', 'saroroce-currency-manager'),
            [$this, 'renderAutoUpdateField'],
            'currency-settings',
            'scm_main_section'
        );

        // Интервал обновления
        add_settings_field(
            'scm_update_interval',
            __('Интервал обновления', 'saroroce-currency-manager'),
            [$this, 'renderUpdateIntervalField'],
            'currency-settings',
            'scm_main_section'
        );
    }

    public function renderSettingsPage()
    {
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="currency-actions">
                <button type="button" id="import-currencies" class="button button-primary">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Импортировать валюты', 'saroroce-currency-manager'); ?>
                </button>
                <button type="button" id="update-rates-manual" class="button button-primary">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Обновить курсы валют', 'saroroce-currency-manager'); ?>
                </button>
                <button type="button" id="reset-currency-display" class="button button-secondary">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Сбросить отображение валют', 'saroroce-currency-manager'); ?>
                </button>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields('scm_settings');
                do_settings_sections('currency-settings');
                submit_button();
                ?>
            </form>

            <div class="scm-documentation">
                <h2><?php _e('Документация', 'saroroce-currency-manager'); ?></h2>

                <h3><?php _e('Шорткоды', 'saroroce-currency-manager'); ?></h3>
                <code>[currency_switcher]</code> - <?php _e('Добавляет селектор выбора валюты', 'saroroce-currency-manager'); ?>

                <h3><?php _e('PHP функции', 'saroroce-currency-manager'); ?></h3>
                <pre>
// Получить текущую валюту пользователя
scm_get_current_currency();

// Установить валюту для пользователя
scm_set_user_currency($currency_code);

// Конвертировать цену в валюту пользователя
scm_convert_price($price, $from_currency = '', $to_currency = '');
                </pre>

                <h3><?php _e('Как добавить товары с разными валютами', 'saroroce-currency-manager'); ?></h3>
                <ol>
                    <li><?php _e('Создайте валюты в разделе "Валюты"', 'saroroce-currency-manager'); ?></li>
                    <li><?php _e('При создании товара укажите базовую цену в основной валюте WooCommerce', 'saroroce-currency-manager'); ?></li>
                    <li><?php _e('Плагин автоматически конвертирует цены в выбранную пользователем валюту', 'saroroce-currency-manager'); ?></li>
                </ol>
            </div>
        </div>
    <?php
    }

    public function renderAutoDetectField()
    {
        $value = get_option('scm_auto_detect_currency', '0');
    ?>
        <label>
            <input type="checkbox" name="scm_auto_detect_currency" value="1" <?php checked('1', $value); ?>>
            <?php _e('Автоматически определять валюту по IP адресу посетителя', 'saroroce-currency-manager'); ?>
        </label>
        <p class="description">
            <?php _e('Если включено, при первом посещении сайта валюта будет установлена автоматически на основе страны посетителя. Если валюта страны не найдена, будет использован доллар США.', 'saroroce-currency-manager'); ?>
        </p>
<?php
    }

    public function renderAutoUpdateField()
    {
        $value = get_option('scm_auto_update_enabled', '0');
    ?>
        <label>
            <input type="checkbox" name="scm_auto_update_enabled" value="1" <?php checked('1', $value); ?>>
            <?php _e('Включить автоматическое обновление курсов валют', 'saroroce-currency-manager'); ?>
        </label>
    <?php
    }

    public function renderUpdateIntervalField()
    {
        $value = get_option('scm_update_interval', 'daily');
    ?>
        <select name="scm_update_interval">
            <option value="hourly" <?php selected($value, 'hourly'); ?>><?php _e('Каждый час', 'saroroce-currency-manager'); ?></option>
            <option value="twicedaily" <?php selected($value, 'twicedaily'); ?>><?php _e('Два раза в день', 'saroroce-currency-manager'); ?></option>
            <option value="daily" <?php selected($value, 'daily'); ?>><?php _e('Раз в день', 'saroroce-currency-manager'); ?></option>
        </select>
    <?php
    }

    public function ajaxResetCurrencyDisplay() 
    {
        check_ajax_referer('currency_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        // Получаем все валюты
        $currencies = get_posts([
            'post_type' => 'currency',
            'posts_per_page' => -1,
        ]);

        $updated = 0;
        foreach ($currencies as $currency) {
            // Получаем код валюты
            $currency_code = get_post_meta($currency->ID, 'currency_code', true);
            
            // Получаем стандартные настройки WooCommerce для этой валюты
            $wc_currency_pos = get_option('woocommerce_currency_pos', 'left');

            // Обновляем только позицию символа, оставляем текущий символ без изменений
            update_post_meta($currency->ID, 'currency_position', 'default');
            
            $updated++;
        }

        if ($updated > 0) {
            wp_send_json_success([
                'message' => sprintf(__('Позиция символа валюты сброшена для %d валют. Все валюты теперь используют настройки отображения из WooCommerce', 'saroroce-currency-manager'), $updated)
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Нет валют для обновления', 'saroroce-currency-manager')
            ]);
        }
    }
}
