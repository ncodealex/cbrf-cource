<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Application\Exception\RateSetConflict;
use App\Cources\Application\Exception\RateSetNotFound;
use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

/**
 * Хранилище неизменяемых наборов курсов и подтверждённых связей календарных дат.
 *
 * Граница конкурентности текущей реализации PostgreSQL: параллельные INSERT
 * при READ COMMITTED. Сохранённые наборы и связи дат не изменяются. Удаление
 * разрешено только use case повторной загрузки после очистки связанных cache-key;
 * конкурентные save/delete одной даты не поддерживаются.
 *
 * После конфликта INSERT существующая запись читается отдельным SQL-оператором:
 * при READ COMMITTED он получает новый снимок и видит завершённую конкурентную вставку.
 * Advisory lock и дополнительная блокировка строки для этой модели не требуются.
 * Вызов внутри внешней транзакции с более строгой изоляцией этой гарантией не покрывается.
 *
 * Delete удаляет набор целиком, а все связанные календарные даты — каскадно.
 */
interface RateSetRepositoryInterface
{
    /**
     *  Получить котировки на календарную дату.
     *
     * @throws RateSetNotFound
     */
    public function get(DatePoint $requestDate): RateSet;

    /**
     * Атомарно сохранить набор и связь requestDate → effectiveDate.
     *
     * Повторное сохранение идентичного набора и той же связи идемпотентно.
     * Другой набор на существующую effectiveDate или другая effectiveDate
     * для существующей requestDate приводят к конфликту и откату операции.
     * Существующие записи не обновляются.
     *
     * @throws RateSetConflict
     */
    public function save(DatePoint $requestDate, RateSet $rateSet): void;

    /** @return list<DatePoint> все календарные даты, связанные с тем же набором */
    public function relatedRequestDates(DatePoint $requestDate): array;

    /** Удалить набор выбранной даты; связанные календарные даты удаляются каскадно. */
    public function delete(DatePoint $requestDate): void;
}
