jQuery(document).ready(function ($) {
    function setLanguageCode(lang) {
        const langCode = $(`[data-scm-lang-code]`);
        // set current lang code to langCode
        if (langCode) {
            langCode.text(lang);
        }
    }


    function autoDetectLang() {
        const languageGroups = {
            // 2 letter language code
            'en': ['en', 'gb', 'us', 'au', 'ca', 'nz', 'ie', 'za', 'in', 'sg', 'ph', 'my', 'ng', 'pk', 'lk', 'bd', 'ae', 'sa', 'kw', 'bh', 'qa', 'om', 'jo', 'lb', 'eg', 'ma', 'dz', 'tn', 'ly', 'sd', 'et', 'so', 'ke', 'gh', 'ci', 'ug', 'zm', 'tz', 'bw', 'zw', 'na', 'mz', 'mg', 'mu', 'sc', 'bw', 'sz', 'ls', 'gm', 'sl', 'lr', 'ng', 'gh', 'sn', 'mr', 'gn', 'bf', 'ne', 'tg', 'bj', 'cv', 'st', 'gq', 'gw', 'km', 'dj', 'er', 'sh', 're', 'yt', 'mu', 'sc', 'bw', 'sz', 'ls', 'gm', 'sl', 'lr', 'ng', 'gh', 'sn', 'mr', 'gn', 'bf', 'ne', 'tg', 'bj', 'cv', 'st', 'gq', 'gw', 'km', 'dj', 'er', 'sh', 're', 'yt', 'mu', 'sc', 'bw', 'sz', 'ls', 'gm', 'sl', 'lr', 'ng', 'gh', 'sn', 'mr', 'gn', 'bf', 'ne', 'tg', 'bj', 'cv', 'st', 'gq', 'gw', 'km', 'dj', 'er', 'sh', 're', 'yt', 'mu', 'sc', 'bw', 'sz', 'ls', 'gm', 'sl', 'lr', 'ng', 'gh', 'sn', 'mr', 'gn', 'bf', 'ne', 'tg', 'bj', 'cv', 'st', 'gq', 'gw', 'km', 'dj', 'er', 'sh', 're', 'yt', 'mu', 'sc', 'bw', 'sz', 'ls', 'gm', 'sl', 'lr', 'ng', 'gh', 'sn', 'mr', 'gn', 'bf', 'ne', 'tg', 'bj'],
            'ru': ['ru', 'ua', 'by', 'kz', 'kg', 'md', 'tj', 'tm', 'uz', 'am', 'az', 'ge', 'ee', 'lv', 'lt', 'il'],
            'ar': ['ar', 'ae', 'bh', 'dz', 'eg', 'iq', 'jo', 'kw', 'lb', 'ly', 'ma', 'om', 'qa', 'sa', 'sd', 'sy', 'tn', 'ye'],
        }
        const currentLang = (document.querySelector('html').lang || 'en').toLowerCase();
        if (!localStorage.getItem('navigate_by_lang')) {
            const browserLang = (navigator.language || navigator.userLanguage).slice(0, 2).toLowerCase();
            const browserLangGroup = Object.keys(languageGroups).find(group => languageGroups[group].includes(browserLang));
            if (browserLangGroup) {
                const userAgent = navigator.userAgent.toLowerCase();
                const isBot = /bot|googlebot|crawler|spider|robot|crawling/i.test(userAgent);

                if (!isBot) {
                    let currentUrl = (window.location.href).split('/');
                    if (currentLang === 'en') {
                        currentUrl.splice(3, 0, browserLangGroup);
                    } else {
                        currentUrl[3] = browserLangGroup;
                    }

                    currentUrl = currentUrl.join('/');
                    const currentUrlObj = new URL(currentUrl);
                    const newUrlObj = new URL(window.location.href);
                    if (currentUrlObj.href !== newUrlObj.href) {
                        window.location.href = currentUrl;
                    }

                    localStorage.setItem('navigate_by_lang', true);
                }
            }
        }

        // set language code to current lang
        setLanguageCode(currentLang);
    }
    autoDetectLang();
});