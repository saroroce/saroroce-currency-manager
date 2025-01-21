jQuery(document).ready(function($) {
    // Добавляем отладочный вывод
    console.log('Admin JS loaded');
    
    // Инициализируем Select2 для селекта валют
    $('#currency_code').select2({
        width: '100%',
        language: {
            noResults: function() {
                return 'Валюты не найдены';
            },
            searching: function() {
                return 'Поиск...';
            }
        },
        templateResult: function(data) {
            if (!data.id) return data.text;
            
            var $option = $(data.element);
            var isDisabled = $option.prop('disabled');
            
            var $result = $('<span>' + data.text + '</span>');
            
            if (isDisabled) {
                $result.css('opacity', '0.6');
            }
            
            return $result;
        }
    });

    // Проверка доступности валюты перед сохранением
    $('form#post').on('submit', function(e) {
        const $form = $(this);
        const $currencySelect = $('#currency_code');
        
        // Если это не форма редактирования валюты или селект отключен, пропускаем
        if (!$currencySelect.length || $currencySelect.prop('disabled')) {
            return true;
        }
        
        // Если валюта не выбрана, пропускаем
        const selectedCurrency = $currencySelect.val();
        if (!selectedCurrency) {
            return true;
        }
        
        // Если это текущая валюта (опция не disabled), пропускаем проверку
        if (!$currencySelect.find('option:selected').prop('disabled')) {
            return true;
        }
        
        // Если опция отключена, предотвращаем отправку
        e.preventDefault();
        alert('Эта валюта уже используется в системе. Пожалуйста, выберите другую валюту.');
        return false;
    });

    // Обработчик для кнопки обновления курсов
    $('#update-rates-manual').on('click', function(e) {
        console.log('Update rates button clicked'); // Отладочный вывод
        
        e.preventDefault();
        const $button = $(this);
        
        // Блокируем кнопку
        $button.prop('disabled', true).text('Обновление...');

        // Отправляем AJAX запрос
        $.post(scmAdmin.ajaxurl, {
            action: 'update_all_rates',
            nonce: scmAdmin.nonce
        }, function(response) {
            console.log('AJAX response:', response); // Отладочный вывод
            
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || 'Произошла ошибка');
                $button.prop('disabled', false).text('Обновить курсы валют');
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX error:', textStatus, errorThrown); // Отладочный вывод
            alert('Ошибка сервера');
            $button.prop('disabled', false).text('Обновить курсы валют');
        });
    });

    // Обработчик для кнопок импорта валют
    $('#import-currencies, #import-currencies-notice').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const originalText = $button.text();
        
        if ($button.prop('tagName') === 'A') {
            $button.text('Импортирование...');
        } else {
            $button.prop('disabled', true).text('Импортирование...');
        }

        $.post(scmAdmin.ajaxurl, {
            action: 'import_currencies',
            nonce: scmAdmin.nonce
        }, function(response) {
            const message = response.data && response.data.message ? response.data.message : 'Неизвестная ошибка';
            alert(message);
            
            if (response.success) {
                location.reload();
            } else {
                if ($button.prop('tagName') === 'A') {
                    $button.text(originalText);
                } else {
                    $button.prop('disabled', false).text(originalText);
                }
            }
        }).fail(function() {
            alert('Ошибка сервера. Попробуйте позже.');
            if ($button.prop('tagName') === 'A') {
                $button.text(originalText);
            } else {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    // Обработчик для кнопки сброса отображения валют
    $('#reset-currency-display').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true);

        $.ajax({
            url: scmAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'reset_currency_display',
                nonce: scmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                } else {
                    alert(response.data.message || 'Произошла ошибка при сбросе отображения валют');
                }
            },
            error: function() {
                alert('Произошла ошибка при выполнении запроса');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
}); 