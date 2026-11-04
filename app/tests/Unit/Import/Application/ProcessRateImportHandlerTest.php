<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Application;

use App\Courses\Application\Exception\RateSetConflict;
use App\Courses\Application\Exception\RateSetLoadBusy;
use App\Courses\Infrastructure\Cbr\Exception\CbrInvalidResponse;
use App\Courses\Infrastructure\Cbr\Exception\CbrRateLimited;
use App\Courses\Infrastructure\Cbr\Exception\CbrRequestRateLimitExceeded;
use App\Courses\Infrastructure\Cbr\Exception\CbrUnavailable;
use App\Import\Application\Configuration\RateImportSettings;
use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Application\Message\ProcessRateImportMessage;
use App\Import\Application\UseCase\ProcessRateImport\ProcessRateImportHandler;
use App\Import\Domain\Enum\RateImportAction;
use App\Import\Domain\ValueObject\ImportJobExecution;
use App\Shared\Contracts\Rates\RateImportPermanentFailure;
use App\Shared\Contracts\Rates\RateSetImportGateway;
use App\Shared\Domain\DatePointParser;
use App\Support\Retry\Application\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Throwable;

final class ProcessRateImportHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, int, RateImportAction, int}>
     */
    public static function failures(): iterable
    {
        yield 'busy' => [new RateSetLoadBusy(), 0, RateImportAction::Defer, 0];
        yield 'local limit' => [new CbrRequestRateLimitExceeded(new MockClock()->now()), 0, RateImportAction::Defer, 0];
        yield '429' => [new CbrRateLimited('429', 429), 0, RateImportAction::Defer, 1];
        yield '503' => [new CbrUnavailable('503', 503), 0, RateImportAction::Defer, 1];
        yield 'timeout' => [new CbrUnavailable('timeout'), 0, RateImportAction::Defer, 1];
        yield 'invalid' => [new CbrInvalidResponse('XML'), 0, RateImportAction::Fail, 1];
        yield 'conflict' => [new RateSetConflict('Конфликт сохранённых курсов'), 0, RateImportAction::Fail, 1];
        yield 'exhausted' => [new CbrUnavailable('503', 503), 4, RateImportAction::Fail, 5];
    }

    public function testRateSetConflictIsPermanentFailure(): void
    {
        self::assertContains(
            RateImportPermanentFailure::class,
            new ReflectionClass(RateSetConflict::class)->getInterfaceNames(),
        );
    }

    public function testStaleMessageDoesNotResolve(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([
            ImportJobRepositoryInterface::class,
            ImportJobQueryRepositoryInterface::class,
        ]);
        $repo->expects(self::once())->method('claim')->willReturn(null);
        $provider = $this->createMock(RateSetImportGateway::class);
        $provider->expects(self::never())->method('ensureAvailable');
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    private function handler(
        ImportJobRepositoryInterface $repo,
        RateSetImportGateway $provider
    ): ProcessRateImportHandler {
        return new ProcessRateImportHandler($repo, $provider, new RateImportSettings(20, 2, 600, 20, 120, 2, 0),
            new RetryPolicy(new MockClock(), 5, 1000, 60000, 0), new NullLogger(), new MockClock());
    }

    public function testConfirmedDateCompletesWithoutProvider(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([
            ImportJobRepositoryInterface::class,
            ImportJobQueryRepositoryInterface::class,
        ]);
        $repo->expects(self::once())->method('claim')->willReturn(new ImportJobExecution(DatePointParser::parse('2026-01-01'),
            1, 0));

        $repo->expects(self::once())->method('finish')->with(self::anything(), RateImportAction::Complete,
            0)->willReturn(false);
        $provider = $this->createMock(RateSetImportGateway::class);
        $provider->method('isConfirmed')->willReturn(true);
        $provider->expects(self::never())->method('ensureAvailable');
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    public function testConflictIsSavedAsTerminalErrorWithoutRetry(): void
    {
        $failure = new RateSetConflict('Конфликт сохранённых курсов');
        $repo    = $this->createMockForIntersectionOfInterfaces([
            ImportJobRepositoryInterface::class,
            ImportJobQueryRepositoryInterface::class,
        ]);
        $repo->expects(self::once())->method('claim')->willReturn(
            new ImportJobExecution(DatePointParser::parse('2026-01-01'), 7, 2),
        );
        $repo->expects(self::once())->method('finish')->with(
            self::callback(static fn(ImportJobExecution $claim): bool => 7 === $claim->version),
            RateImportAction::Fail,
            3,
            null,
            self::callback(static fn(?string $code): bool => null !== $code && 1 === preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                    $code,
                )),
            'Конфликт сохранённых курсов',
        )->willReturn(true);
        $provider = $this->createMock(RateSetImportGateway::class);
        $provider->expects(self::once())->method('isConfirmed')->willReturn(false);
        $provider->expects(self::once())->method('ensureAvailable')->willThrowException($failure);

        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 6));
    }

    public function testFailureToSaveConflictStateEscapes(): void
    {
        $storageFailure = new RuntimeException('PostgreSQL недоступен');
        $repo           = $this->createMockForIntersectionOfInterfaces([
            ImportJobRepositoryInterface::class,
            ImportJobQueryRepositoryInterface::class,
        ]);
        $repo->expects(self::once())->method('claim')->willReturn(
            new ImportJobExecution(DatePointParser::parse('2026-01-01'), 1, 0),
        );
        $repo->expects(self::once())->method('finish')->with(
            self::anything(),
            RateImportAction::Fail,
            1,
        )->willThrowException($storageFailure);
        $provider = $this->createMock(RateSetImportGateway::class);
        $provider->expects(self::once())->method('ensureAvailable')->willThrowException(
            new RateSetConflict('Конфликт сохранённых курсов'),
        );

        $this->expectExceptionObject($storageFailure);

        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    #[DataProvider('failures')]
    public function testFailures(Throwable $failure, int $before, RateImportAction $action, int $after): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([
            ImportJobRepositoryInterface::class,
            ImportJobQueryRepositoryInterface::class,
        ]);
        $repo->expects(self::once())->method('claim')->willReturn(new ImportJobExecution(DatePointParser::parse('2026-01-01'),
            1, $before));

        $repo->expects(self::once())->method('finish')->with(self::anything(), $action, $after)->willReturn(true);
        $provider = $this->createMock(RateSetImportGateway::class);
        $provider->expects(self::once())->method('ensureAvailable')->willThrowException($failure);
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    public function testUnknownInfrastructureFailureEscapes(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([
            ImportJobRepositoryInterface::class,
            ImportJobQueryRepositoryInterface::class,
        ]);
        $repo->expects(self::once())->method('claim')->willReturn(new ImportJobExecution(DatePointParser::parse('2026-01-01'),
            1, 0));
        $repo->expects(self::never())->method('finish');
        $provider = $this->createMock(RateSetImportGateway::class);
        $provider->expects(self::once())->method('ensureAvailable')->willThrowException(new RuntimeException('DB'));
        $this->expectException(RuntimeException::class);
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }
}
