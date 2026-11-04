<?php

declare(strict_types=1);

namespace App\Support\Health\Infrastructure;

use App\Support\Health\Application\HealthProbe;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RedisHealthProbe implements HealthProbe
{
    /**
     * @param array<string, mixed> $connectionOptions
     */
    public function __construct(
        #[Autowire(env: 'default:app.redis.default_url:REDIS_URL')]
        private string $dsn,
        #[Autowire('%app.redis.connection_options%')]
        private array $connectionOptions,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'redis';
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
        $redis = RedisAdapter::createConnection($this->dsn, array_replace($this->connectionOptions, ['lazy' => false]));
        if (!$redis instanceof \Redis) {
            throw new \RuntimeException('Для проверки Redis требуется расширение phpredis.');
        }
        try {
            if (!\in_array($redis->ping(), [true, '+PONG', 'PONG'], true)) {
                throw new \RuntimeException('Redis не подтвердил готовность.');
            }
        } finally {
            $redis->close();
        }
    }
}
