<?php

namespace Saroroce\CurrencyManager;

class Plugin {
    private $post_type;

    public function __construct() {
        // Инициализируем типы постов и другие компоненты
        $this->post_type = new PostType();

        // Добавляем скрипты и стили
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    public function enqueueAdminAssets($hook) {
        $screen = get_current_screen();
        if (!$screen || ($screen->post_type !== 'currency' && $screen->id !== 'currency_page_currency-settings')) {
            return;
        }

        wp_enqueue_style(
            'scm-admin',
            SCM_URL . 'assets/css/admin.css',
            [],
            SCM_VERSION
        );

        wp_enqueue_script(
            'scm-admin',
            SCM_URL . 'assets/js/admin.js',
            ['jquery'],
            SCM_VERSION,
            true
        );

        wp_localize_script('scm-admin', 'scmAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('currency_action')
        ]);
    }
} 