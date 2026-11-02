<?php

declare(strict_types=1);

namespace App\Support\Logging\Infrastructure;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor(handler: 'main')]
#[AsMonologProcessor(handler: 'errors')]
#[AsMonologProcessor(handler: 'stderr')]
final readonly class RemoveXmlProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        unset($context['xml'], $context['xml_base64']);

        return $record->with(context: $context);
    }
}
