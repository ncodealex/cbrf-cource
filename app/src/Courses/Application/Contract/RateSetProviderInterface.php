<?php

declare(strict_types=1);

namespace App\Courses\Application\Contract;

use App\Courses\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

interface RateSetProviderInterface
{
    /**
     * Получить набор на календарную дату, последовательно проверяя кеш, БД и источник.
     * Чтение из БД или загрузка из источника прогревают кеш; сбой кеша не прерывает получение.
     * Наличие результата только в кеше не гарантирует календарную связь в PostgreSQL.
     *
     * @throws \Throwable при недопустимой дате, отказе источника, блокировки или хранилища
     */
    public function resolve(DatePoint $requestDate): RateSet;
}
