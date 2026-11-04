<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use App\Support\Health\Application\HealthProbe;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MigrationsHealthProbe implements HealthProbe
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'migrations';
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): void
    {
        // Только сравнение зарегистрированных версий и истории в БД; DDL не выполняется.
        $pending = $this->migrations->getMigrationStatusCalculator()->getNewMigrations();
        if (0 !== \count($pending)) {
            throw new \RuntimeException('Есть неприменённые миграции базы данных.');
        }
    }
}
