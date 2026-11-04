<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum RateImportAction
{
    /**
     * Получить право исполнения ожидающего или отложенного задания.
     */
    case Claim;
    /**
     * Завершить исполнение после подтверждения календарной даты.
     */
    case Complete;
    /**
     * Отложить исполнение до принятого Application времени повтора.
     */
    case Defer;
    /**
     * Сохранить терминальную ошибку текущего исполнения.
     */
    case Fail;
    /**
     * Восстановить истёкший Processing в Deferred либо Error по решению RetryPolicy.
     */
    case Recover;
    /**
     * Инвалидировать прежнее исполнение независимо от текущего состояния.
     */
    case Refresh;
    /**
     * Вернуть неподтверждённый Done в ожидание.
     */
    case RepairDone;
    /**
     * Вручную возобновить Error с очисткой попыток и прежней ошибки.
     */
    case RetryError;
}
