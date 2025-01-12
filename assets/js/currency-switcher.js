jQuery(document).ready(function($) {
    $('.scm-currency-switcher').on('change', function() {
        var currency = $(this).val();
        var currentUrl = window.location.href;
        
        // Создаем URL с новым параметром
        var url = new URL(currentUrl);
        url.searchParams.set('new_currency', currency);
        
        // Перенаправляем на новый URL
        window.location.href = url.toString();
    });
}); 