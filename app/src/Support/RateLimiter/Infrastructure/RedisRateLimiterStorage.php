<?php

declare(strict_types=1);

namespace App\Support\RateLimiter\Infrastructure;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\TokenBucket;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** В отличие от кеша, сбои хранилища лимитера не могут трактоваться как отсутствие состояния. */
final readonly class RedisRateLimiterStorage implements StorageInterface
{
    public function __construct(private \Redis $redis)
    {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $ttl = $limiterState->getExpirationTime();
        $key = $this->key($limiterState->getId());
        $payload = serialize($limiterState);
        $saved = null === $ttl ? $this->redis->set($key, $payload) : $this->redis->setex($key, max(1, $ttl), $payload);
        if (!$saved) {
            throw new \RuntimeException('Не удалось сохранить состояние лимитера в Redis.');
        }
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $key = $this->key($limiterStateId);
        // Lua отличает отсутствие ключа от ошибки GET и возвращает явный маркер.
        $result = $this->redis->eval('local value = redis.call("GET", KEYS[1]); if not value then return {0} end; return {1, value}', [$key], 1);
        if (!\is_array($result) || !isset($result[0])) {
            throw new \RuntimeException('Не удалось прочитать состояние лимитера из Redis.');
        }
        if (0 === $result[0]) {
            return null;
        }
        if (1 !== $result[0] || !isset($result[1]) || !\is_string($result[1])) {
            throw new \RuntimeException('Redis вернул некорректное состояние лимитера.');
        }
        $state = @unserialize($result[1], ['allowed_classes' => [TokenBucket::class]]);
        if (!$state instanceof TokenBucket || $state->getId() !== $limiterStateId) {
            throw new \RuntimeException('Повреждено состояние лимитера в Redis.');
        }

        return $state;
    }

    public function delete(string $limiterStateId): void
    {
        if (false === $this->redis->del($this->key($limiterStateId))) {
            throw new \RuntimeException('Не удалось удалить состояние лимитера из Redis.');
        }
    }

    private function key(string $id): string
    {
        return 'cbr:limiter:state:'.hash('sha256', $id);
    }
}
