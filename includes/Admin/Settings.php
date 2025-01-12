<?php

namespace Saroroce\CurrencyManager\Admin;

class Settings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
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

        add_settings_section(
            'scm_main_section',
            __('Основные настройки', 'saroroce-currency-manager'),
            null,
            'currency-settings'
        );

        add_settings_field(
            'scm_auto_detect_currency',
            __('Автоопределение валюты', 'saroroce-currency-manager'),
            [$this, 'renderAutoDetectField'],
            'currency-settings',
            'scm_main_section'
        );
    }

    public function renderSettingsPage()
    {
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

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
}
