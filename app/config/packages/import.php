<?php

declare(strict_types=1);

return [
    'parameters' => [
        'app.import.default_dispatch_batch_size' => '20',
        'app.import.default_dispatch_interval_seconds' => '2',
        'app.import.default_redispatch_after_seconds' => '600',
        'app.import.default_max_unclaimed' => '20',
        'app.import.default_lease_seconds' => '120',
        'app.import.default_max_attempts' => '5',
        'app.import.default_retry_initial_delay_ms' => '1000',
        'app.import.default_retry_max_delay_ms' => '60000',
        'app.import.default_retry_jitter_percent' => '20',
        'app.import.default_progress_interval_seconds' => '2',
        'app.import.default_progress_timeout_seconds' => '0',
    ],
];
