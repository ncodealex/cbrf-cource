<?php

declare(strict_types=1);

namespace App\Support\Health\Application;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ReadinessChecker
{
    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(
        #[AutowireIterator(HealthProbe::TAG)]
        private iterable $probes,
        private LoggerInterface $logger,
    ) {
    }

    public function check(): ReadinessReport
    {
        $checks = [];

        foreach ($this->probes as $probe) {
            if (!$probe->isEnabled()) {
                continue;
            }

            try {
                $probe->check();
                $checks[$probe->name()] = ReadinessReport::OK;
            } catch (\Throwable $e) {
                $checks[$probe->name()] = ReadinessReport::FAILED;
                // В лог — только тип ошибки и код: сообщения драйверов могут содержать адреса и учётные данные.
                $this->logger->warning('Readiness probe failed', [
                    'probe' => $probe->name(),
                    'error' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        return new ReadinessReport($checks);
    }
}
