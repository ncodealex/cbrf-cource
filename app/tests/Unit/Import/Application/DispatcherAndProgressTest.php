<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Application;

use App\Import\Application\Configuration\RateImportSettings;
use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Application\Contract\RateImportJobPublisherInterface;
use App\Import\Application\DTO\RateImportProgress;
use App\Import\Application\Message\ProcessRateImportMessage;
use App\Import\Application\UseCase\DispatchRateImports\DispatchRateImportsHandler;
use App\Import\Application\UseCase\GetRateImportProgress\GetRateImportProgressHandler;
use App\Import\Domain\ValueObject\RatesImportPeriod;
use App\Import\Endpoints\Console\RatesImport\RateImportProgressObserver;
use App\Shared\Domain\DatePointParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\BufferedOutput;

final class DispatcherAndProgressTest extends TestCase
{
    public function testPartialPublicationAndNextCycle(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::exactly(2))->method('reserveDispatch')->with(20, 600, 20)->willReturnOnConsecutiveCalls([new ProcessRateImportMessage('2026-01-01', 0), new ProcessRateImportMessage('2026-01-02', 0)], []);
        $publisher = $this->createMock(RateImportJobPublisherInterface::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(static function (ProcessRateImportMessage $message): void { if ('2026-01-01' === $message->requestedDate) { throw new \RuntimeException('RabbitMQ'); } });
        $handler = new DispatchRateImportsHandler($repo, $publisher, new RateImportSettings(20, 2, 600, 20, 120, 2, 0), new NullLogger());
        self::assertSame(1, $handler());
        self::assertSame(0, $handler());
    }

    public function testProgressTimeoutDoesNotRepeatUnchangedOutput(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::exactly(3))->method('progress')->willReturn(
            new RateImportProgress(0, 0, 0, 1, 0, 0, 0),
        );
        $observer = new RateImportProgressObserver(new GetRateImportProgressHandler($repo), new MockClock(), new RateImportSettings(20, 2, 600, 20, 120, 2, 0));
        $date = DatePointParser::parse('2026-01-01');
        $out = new BufferedOutput(decorated: false);
        self::assertSame(1, $observer->observe(new RatesImportPeriod($date, $date), $out, 1, 2));
        $text = $out->fetch();
        self::assertSame(1, substr_count($text, 'Подтверждено:'));
        self::assertStringNotContainsString("\033", $text);
    }

    public function testProgressAllConfirmed(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::once())->method('progress')->willReturn(
            new RateImportProgress(1, 0, 0, 0, 0, 0, 0),
        );
        $observer = new RateImportProgressObserver(new GetRateImportProgressHandler($repo), new MockClock(), new RateImportSettings(20, 2, 600, 20, 120, 2, 0));
        $date = DatePointParser::parse('2026-01-01');
        self::assertSame(0, $observer->observe(new RatesImportPeriod($date, $date), new BufferedOutput(), 1, 2));
    }

    public function testInteractiveProgressRewritesTheSameBlockAndUsesObservedThroughput(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $states = static fn (int $confirmed): RateImportProgress => new RateImportProgress(
            confirmed: $confirmed,
            waiting: 0,
            processing: 0,
            deferred: 180 - $confirmed,
            error: 0,
            inconsistentDone: 0,
            missing: 0,
        );
        $repo->expects(self::exactly(3))->method('progress')->willReturnOnConsecutiveCalls($states(66), $states(67), $states(68));
        $observer = new RateImportProgressObserver(new GetRateImportProgressHandler($repo), new MockClock(), new RateImportSettings(20, 2, 600, 20, 120, 2, 0));
        $period = new RatesImportPeriod(DatePointParser::parse('2026-05-06'), DatePointParser::parse('2026-11-01'));
        $out = new BufferedOutput(decorated: true);
        self::assertSame(1, $observer->observe($period, $out, 1, 2));
        $text = $out->fetch();
        self::assertSame(6, substr_count($text, "\033[1A"));
        self::assertStringContainsString('68/180', $text);
        self::assertStringContainsString('ETA: 00:01:52', $text);
        self::assertStringNotContainsString('Подтверждено:', $text);
        self::assertStringContainsString('Время наблюдения истекло. Импорт продолжается.', $text);
        self::assertStringNotContainsString('100%', $text);
    }
}
