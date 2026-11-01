<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/** Ограничивает wall-clock время, включая зависший драйвер, SQL и DNS. */
final readonly class ProbeProcess
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%app.health.probe_timeout%')]
        private float $timeout,
    ) {
    }

    /** @param array<string, mixed> $parameters */
    public function check(string $name, array $parameters): void
    {
        $process = new Process([\PHP_BINDIR.'/php', $this->projectDir.'/bin/check-dependency', $name]);
        // Credentials не попадают в argv, вывод процесса или текст исключения.
        $process->setInput(json_encode($parameters, \JSON_THROW_ON_ERROR));
        $process->disableOutput();
        $process->setTimeout($this->timeout);

        if (0 !== $process->run()) {
            throw new \RuntimeException('Dependency probe failed.');
        }
    }
}
