<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Import;

use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Contract\RateSetProviderInterface;
use App\Cources\Application\Exception\RateSetLoadBusy;
use App\Cources\Application\Import\RateImportAction;
use App\Cources\Application\Import\RateImportClaim;
use App\Cources\Application\Import\RateImportSettings;
use App\Cources\Application\Message\ProcessRateImportMessage;
use App\Cources\Application\UseCase\ProcessRateImport\ProcessRateImportHandler;
use App\Cources\Infrastructure\Cbr\Exception\CbrInvalidResponse;
use App\Cources\Infrastructure\Cbr\Exception\CbrRateLimited;
use App\Cources\Infrastructure\Cbr\Exception\CbrRequestRateLimitExceeded;
use App\Cources\Infrastructure\Cbr\Exception\CbrUnavailable;
use App\Support\Retry\Application\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class ProcessRateImportHandlerTest extends TestCase
{
    public function testStaleMessageDoesNotResolve(): void
    {
        $repo = $this->createMock(RateImportJobRepositoryInterface::class);
        $repo->expects(self::once())->method('claim')->willReturn(null);
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(self::never())->method('resolve');
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    public function testConfirmedDateCompletesWithoutProvider(): void
    {
        $repo = $this->createMock(RateImportJobRepositoryInterface::class);
        $repo->expects(self::once())->method('claim')->willReturn(new RateImportClaim('2026-01-01', 1, 0));
        $repo->method('isConfirmed')->willReturn(true);
        $repo->expects(self::once())->method('finish')->with(self::anything(), RateImportAction::Complete, 0)->willReturn(false);
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(self::never())->method('resolve');
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    /** @return iterable<string, array{\Throwable, int, RateImportAction, int}> */
    public static function failures(): iterable
    {
        yield 'busy' => [new RateSetLoadBusy(), 0, RateImportAction::Defer, 0];
        yield 'local limit' => [new CbrRequestRateLimitExceeded(new MockClock()->now()), 0, RateImportAction::Defer, 0];
        yield '429' => [new CbrRateLimited('429', 429), 0, RateImportAction::Defer, 1];
        yield '503' => [new CbrUnavailable('503', 503), 0, RateImportAction::Defer, 1];
        yield 'timeout' => [new CbrUnavailable('timeout'), 0, RateImportAction::Defer, 1];
        yield 'invalid' => [new CbrInvalidResponse('XML'), 0, RateImportAction::Fail, 1];
        yield 'exhausted' => [new CbrUnavailable('503', 503), 4, RateImportAction::Fail, 5];
    }

    #[DataProvider('failures')]
    public function testFailures(\Throwable $failure, int $before, RateImportAction $action, int $after): void
    {
        $repo = $this->createMock(RateImportJobRepositoryInterface::class);
        $repo->expects(self::once())->method('claim')->willReturn(new RateImportClaim('2026-01-01', 1, $before));
        $repo->method('isConfirmed')->willReturn(false);
        $repo->expects(self::once())->method('finish')->with(self::anything(), $action, $after)->willReturn(true);
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(self::once())->method('resolve')->willThrowException($failure);
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    public function testUnknownInfrastructureFailureEscapes(): void
    {
        $repo = $this->createMock(RateImportJobRepositoryInterface::class);
        $repo->expects(self::once())->method('claim')->willReturn(new RateImportClaim('2026-01-01', 1, 0));
        $repo->expects(self::never())->method('finish');
        $provider = $this->createMock(RateSetProviderInterface::class);
        $provider->expects(self::once())->method('resolve')->willThrowException(new \RuntimeException('DB'));
        $this->expectException(\RuntimeException::class);
        $this->handler($repo, $provider)(new ProcessRateImportMessage('2026-01-01', 0));
    }

    private function handler(RateImportJobRepositoryInterface $repo, RateSetProviderInterface $provider): ProcessRateImportHandler
    {
        return new ProcessRateImportHandler($repo, $provider, new RateImportSettings(20, 2, 600, 20, 120, 2, 0), new RetryPolicy(new MockClock(), 5, 1000, 60000, 0), new NullLogger(), new MockClock());
    }
}
