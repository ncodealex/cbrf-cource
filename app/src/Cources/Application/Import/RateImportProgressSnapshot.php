<?php

declare(strict_types=1);

namespace App\Cources\Application\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateImportProgressSnapshot
{
    public ?int $estimatedRemaining;

    public function __construct(
        public int $total,
        public int $confirmed,
        public int $waiting,
        public int $processing,
        public int $deferred,
        public int $error,
        public int $inconsistentDone,
        public int $missing,
        public int $elapsed = 0,
        int $initialConfirmed = 0,
    ) {
        $loaded = $confirmed - $initialConfirmed;
        $this->estimatedRemaining = $confirmed === $total ? 0 : ($loaded > 0 && $elapsed > 0
            ? (int) round($elapsed * ($total - $confirmed) / $loaded)
            : null);
    }

    /**
     * Время не входит в сравнение: неизменившиеся счётчики не требуют нового вывода.
     *
     * @return list<int>
     */
    public function counts(): array
    {
        return [$this->total, $this->confirmed, $this->waiting, $this->processing, $this->deferred, $this->error, $this->inconsistentDone, $this->missing];
    }
}
