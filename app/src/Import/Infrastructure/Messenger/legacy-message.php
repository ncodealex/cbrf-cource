<?php

declare(strict_types=1);

// Совместимость PHP-сериализации сообщений, опубликованных до разделения модулей.
class_alias(App\Import\Application\Message\ProcessRateImportMessage::class, 'App\\Cources\\Application\\Message\\ProcessRateImportMessage');
