<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Import;

use Symfony\Component\Clock\DatePoint;

interface ImportExecutionFence
{
    /**
     * Выполнить запись в короткой PostgreSQL-транзакции после блокировки актуальной версии.
     * HTTP выполняется до входа в эту операцию. Refresh использует ту же блокировку строки.
     * Запись выполняется ровно один раз только для Processing с совпадающей версией;
     * при исключении внутри write транзакция откатывается, ошибка передаётся вызывающему.
     *
     * @param \Closure(): void $write сохранение календарной связи
     *
     * @throws StaleImportExecution если исполнитель потерял владение
     * @throws \Throwable           при отказе PostgreSQL или переданной операции записи
     */
    public function write(
        DatePoint $date,
        int $version,
        \Closure $write,
    ): void;
}
