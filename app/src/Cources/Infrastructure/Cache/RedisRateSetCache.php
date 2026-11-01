<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cache;

use App\Cources\Application\Contract\RateSetCacheInterface;
use App\Cources\Application\Exception\RateSetCacheFailure;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use App\Cources\Infrastructure\Mapping\InvalidRateSetPayload;
use App\Cources\Infrastructure\Mapping\RateSetMapper;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(RateSetCacheInterface::class)]
final readonly class RedisRateSetCache implements RateSetCacheInterface
{
    public function __construct(
        private \Redis $redis,
        private RateSetMapper $mapper,
        #[Autowire('%env(int:CACHE_DEFAULT_TTL)%')]
        private int $ttl,
    ) {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('TTL набора курсов должен быть положительным');
        }
    }

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

    public function put(DatePoint $requestDate, RateSet $set): void
    {
        try {
            $payload = json_encode($this->mapper->toArray($set), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            if (true !== $this->redis->set($this->key($requestDate), $payload, ['ex' => $this->ttl])) {
                throw new RateSetCacheFailure('Redis не подтвердил запись набора курсов');
            }
        } catch (\RedisException|\JsonException $e) {
            throw new RateSetCacheFailure('Не удалось записать набор курсов в Redis', 0, $e);
        }
    }

    private function key(DatePoint $date): string
    {
        return 'rateset:date:'.$date->format('Y-m-d');
    }
}
