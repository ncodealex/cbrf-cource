<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Cache;

use App\Courses\Application\Contract\RateSetCacheInterface;
use App\Courses\Application\Exception\RateSetCacheFailure;
use App\Courses\Application\Exception\RateSetNotFound;
use App\Courses\Domain\Entity\RateSet;
use App\Courses\Infrastructure\Mapping\InvalidRateSetPayload;
use App\Courses\Infrastructure\Mapping\RateSetMapper;
use App\Shared\Domain\DateFormatInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(RateSetCacheInterface::class)]
final readonly class RedisRateSetCache implements RateSetCacheInterface
{
    public function __construct(
        private \Redis $redis,
        private RateSetMapper $mapper,
        #[Autowire('%env(int:default:app.cache.default_ttl:CACHE_DEFAULT_TTL)%')]
        private int $ttl,
    ) {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('TTL набора курсов должен быть положительным');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(DatePoint $requestDate): RateSet
    {
        try {
            $this->redis->clearLastError();
            $payload = $this->redis->get($this->key($requestDate));
            if (false === $payload && null !== $this->redis->getLastError()) {
                throw new RateSetCacheFailure('Redis вернул ошибку чтения набора курсов');
            }
        } catch (\RedisException $e) {
            throw new RateSetCacheFailure('Не удалось прочитать набор курсов из Redis', 0, $e);
        }
        if (false === $payload) {
            throw new RateSetNotFound('В кеше нет набора курсов на дату '.$requestDate->format('Y-m-d'));
        }
        try {
            if (!\is_string($payload)) {
                throw new InvalidRateSetPayload('Ожидалась JSON-строка набора курсов');
            }

            return $this->mapper->fromArray(json_decode($payload, true, 512, \JSON_THROW_ON_ERROR));
        } catch (\JsonException|InvalidRateSetPayload $e) {
            throw new RateSetCacheFailure('Повреждён набор курсов в Redis', 0, $e);
        }
    }

    private function key(DatePoint $date): string
    {
        return 'rateset:date:'.$date->format(DateFormatInterface::ISO);
    }

    /**
     * {@inheritdoc}
     */
    public function put(
        DatePoint $requestDate,
        RateSet $set,
    ): void {
        try {
            $payload = json_encode($this->mapper->toArray($set), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            if (true !== $this->redis->set($this->key($requestDate), $payload, ['ex' => $this->ttl])) {
                throw new RateSetCacheFailure('Redis не подтвердил запись набора курсов');
            }
        } catch (\RedisException|\JsonException $e) {
            throw new RateSetCacheFailure('Не удалось записать набор курсов в Redis', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(DatePoint ...$requestDates): void
    {
        if ([] === $requestDates) {
            return;
        }

        try {
            $this->redis->del(array_map($this->key(...), $requestDates));
        } catch (\RedisException $e) {
            throw new RateSetCacheFailure('Не удалось удалить набор курсов из кеша', 0, $e);
        }
    }
}
