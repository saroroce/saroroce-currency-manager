<?php

if (!function_exists('scm_convert')) {
    function scm_convert($amount) {
        return \Saroroce\CurrencyManager\CurrencyManager::getInstance()->convertAmount($amount);
    }
} 