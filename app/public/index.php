<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    // Symfony Runtime в debug-режиме выставляет umask 000 (файлы 666, каталоги 777).
    // Все процессы работают от одного пользователя, поэтому возвращаем стандартную маску.
    umask(0o022);

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
