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
 * при READ COMMITTED. Сохранённые наборы и связи дат не должны изменяться
 * или удаляться другими операциями; конкурентные UPDATE и DELETE не поддерживаются.
 * Неизменяемость — требование ко всем писателям, а не запрет на уровне прав БД.
 *
 * После конфликта INSERT существующая запись читается отдельным SQL-оператором:
 * при READ COMMITTED он получает новый снимок и видит завершённую конкурентную вставку.
 * Advisory lock и дополнительная блокировка строки для этой модели не требуются.
 * Вызов внутри внешней транзакции с более строгой изоляцией этой гарантией не покрывается.
 *
 * Добавление UPDATE или DELETE требует пересмотра алгоритма: защиты проверяемых строк
 * до COMMIT и обработки их исчезновения между INSERT и SELECT. Такая блокировка
 * сама по себе не гарантирует неизменяемость данных после завершения транзакции.
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
}
