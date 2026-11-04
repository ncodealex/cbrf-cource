<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Endpoints;

use App\Import\Application\Contract\ImportJobQueryRepositoryInterface;
use App\Import\Application\Contract\ImportJobRepositoryInterface;
use App\Import\Application\DTO\RateImportError;
use App\Import\Application\DTO\RateImportErrorList;
use App\Import\Application\UseCase\ListRateImportErrors\ListRateImportErrorsHandler;
use App\Import\Application\UseCase\RetryRateImportErrors\RetryRateImportErrorsHandler;
use App\Import\Endpoints\Console\RateImportErrors\RateImportErrorsCommand;
use App\Import\Endpoints\Console\RetryRateImportErrors\RetryRateImportErrorsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ErrorCommandsTest extends TestCase
{
    public function testErrorTableEscapesControlsAndShowsFullUuid(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::once())->method('errors')->with('2026-01-01', 1)->willReturn(
            new RateImportErrorList(
                total: 3,
                items: [
                    new RateImportError(
                        requestedDate: '2026-01-01',
                        attempts: 5,
                        errorCode: '12345678-1234-4234-8234-123456789abc',
                        errorText: "\033[31m<error>bad</error>",
                    ),
                ],
            ),
        );
        $tester = new CommandTester(new Command()->setCode(new RateImportErrorsCommand(new ListRateImportErrorsHandler($repo))));
        self::assertSame(0, $tester->execute(['--date' => '2026-01-01', '--limit' => '1']));
        $text = $tester->getDisplay();
        self::assertStringContainsString('12345678-1234-4234-8234-123456789abc', $text);
        self::assertStringContainsString('Всего ошибочных заданий: 3', $text);
        self::assertStringNotContainsString("\033", $text);
    }

    public function testMassRetryRequiresYesWithoutInteraction(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::never())->method('retryErrors');
        $tester = new CommandTester(new Command()->setCode(new RetryRateImportErrorsCommand(new RetryRateImportErrorsHandler($repo))));
        self::assertSame(Command::INVALID, $tester->execute(['--all' => true], ['interactive' => false]));
    }

    public function testMassRetryUsesSingleOperation(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::once())->method('retryErrors')->with(null)->willReturn(12);
        $tester = new CommandTester(new Command()->setCode(new RetryRateImportErrorsCommand(new RetryRateImportErrorsHandler($repo))));
        self::assertSame(0, $tester->execute(['--all' => true, '--yes' => true], ['interactive' => false]));
        self::assertStringContainsString('12', $tester->getDisplay());
    }

    public function testMissingScopeIsRejected(): void
    {
        $repo = $this->createMockForIntersectionOfInterfaces([ImportJobRepositoryInterface::class, ImportJobQueryRepositoryInterface::class]);
        $repo->expects(self::never())->method('retryErrors');
        $tester = new CommandTester(new Command()->setCode(new RetryRateImportErrorsCommand(new RetryRateImportErrorsHandler($repo))));
        self::assertSame(Command::INVALID, $tester->execute([]));
    }
}
