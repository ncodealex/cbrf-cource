<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Endpoints\Console\RatesImport;

use App\Cources\Application\Contract\ExternalRateSetGatewayDatePolicyInterface;
use App\Cources\Application\Contract\RateImportJobRepositoryInterface;
use App\Cources\Application\Exception\GatewayNotSupportRateSetDateInterface;
use App\Cources\Application\Factory\RatesImportPeriodFactory;
use App\Cources\Application\Import\RatesImportPeriod;
use App\Cources\Application\UseCase\RatesImport\RatesImportHandler;
use App\Cources\Endpoints\Console\RatesImport\RatesImportCommand;
use App\Shared\Domain\DateFormatInterface;
use App\Shared\Domain\DatePointParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RatesImportCommandTest extends TestCase
{
    /** @param array<string, string> $options */
    #[DataProvider('validPeriods')]
    public function testPassesParsedPeriodToHandler(array $options, string $from, string $to, int $days): void
    {
        $repository = $this->createMock(RateImportJobRepositoryInterface::class);
        $repository->expects(self::once())->method('confirmedDates')
            ->with(self::callback(static function (RatesImportPeriod $period) use ($from, $to): bool {
                return $from === $period->from->format(DateFormatInterface::ISO)
                    && $to === $period->to->format(DateFormatInterface::ISO);
            }))
            ->willReturn([]);
        $repository->expects(self::once())->method('register')->willReturn([]);
        $repository->expects(self::once())->method('statistics')->willReturn([
            'waiting' => $days, 'processing' => 0, 'deferred' => 0, 'done' => 0, 'error' => 0,
        ]);
        $repository->expects(self::never())->method('delete');
        $tester = $this->tester($repository);

        self::assertSame(Command::SUCCESS, $tester->execute($options, ['capture_stderr_separately' => true]));
        $this->assertOutputValue($tester->getDisplay(), 'Календарных дней', (string) $days);
        $this->assertOutputValue($tester->getDisplay(), 'Период', \sprintf(
            '%s — %s',
            DatePointParser::parse($from)->format(DateFormatInterface::UI),
            DatePointParser::parse($to)->format(DateFormatInterface::UI),
        ));
        self::assertSame('', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{array<string, string>, string, string, int}> */
    public static function validPeriods(): iterable
    {
        yield 'По умолчанию' => [[], '2026-05-06', '2026-11-01', 180];
        yield 'Только число дней' => [['--days' => '30'], '2026-10-03', '2026-11-01', 30];
        yield 'Вперёд от даты' => [['--from' => '2026-01-01', '--days' => '30'], '2026-01-01', '2026-01-30', 30];
        yield 'Назад от даты' => [['--to' => '2026-06-30', '--days' => '30'], '2026-06-01', '2026-06-30', 30];
        yield 'Явный диапазон' => [['--from' => '2026-01-01', '--to' => '2026-06-30'], '2026-01-01', '2026-06-30', 181];
        yield 'Один день' => [['--days' => '1'], '2026-11-01', '2026-11-01', 1];
        yield 'Формат с точками' => [['--from' => '1.1.2026', '--to' => '2.1.2026'], '2026-01-01', '2026-01-02', 2];
        yield 'Формат с косой чертой' => [['--from' => '1/1/2026', '--to' => '2/1/2026'], '2026-01-01', '2026-01-02', 2];
        yield 'Формат с дефисом' => [['--from' => '1-1-2026', '--to' => '2-1-2026'], '2026-01-01', '2026-01-02', 2];
    }

    public function testDisplaysRegistrationAndAllCurrentStates(): void
    {
        $repository = $this->createMock(RateImportJobRepositoryInterface::class);
        $repository->expects(self::once())->method('confirmedDates')->willReturn([
            DatePointParser::parse('2026-01-01'), DatePointParser::parse('2026-01-02'),
        ]);
        $inserted = new RatesImportPeriod(DatePointParser::parse('2026-01-21'), DatePointParser::parse('2026-01-30'));
        $repository->expects(self::once())->method('register')->willReturn(iterator_to_array($inserted->dates(), false));
        $repository->expects(self::once())->method('statistics')->willReturn([
            'waiting' => 11, 'processing' => 3, 'deferred' => 5, 'done' => 2, 'error' => 9,
        ]);
        $tester = $this->tester($repository);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from' => '2026-01-01', '--to' => '2026-01-30',
        ], ['capture_stderr_separately' => true]));
        $output = $tester->getDisplay();
        foreach ([
            'Календарных дней' => 30,
            'Уже загружено' => 2,
            'Уже зарегистрировано' => 18,
            'Создано новых' => 10,
            'Ожидает обработки' => 11,
            'Обрабатывается' => 3,
            'Отложено' => 5,
            'Завершено' => 2,
            'Ошибка' => 9,
        ] as $label => $value) {
            $this->assertOutputValue($output, $label, (string) $value);
        }
        foreach ([
            'Импорт исторических котировок ЦБ РФ', 'Регистрация', 'Текущее состояние',
            'Задания зарегистрированы.', 'Обработка выполняется Dispatcher и Messenger Worker.',
        ] as $text) {
            self::assertStringContainsString($text, $output);
        }
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testAdjustedPeriodRequiresConsentBeforeRepositoryAccess(): void
    {
        $tester = $this->tester($this->unusedRepository());

        self::assertSame(Command::INVALID, $tester->execute([
            '--from' => '2026-11-01', '--to' => '2026-11-10',
        ], ['capture_stderr_separately' => true, 'interactive' => false]));
        self::assertStringContainsString('требуется подтверждение', $tester->getErrorOutput());
    }

    public function testYesAcceptsAdjustedPeriod(): void
    {
        $repository = $this->createMock(RateImportJobRepositoryInterface::class);
        $repository->expects(self::once())->method('confirmedDates')->with(self::callback(
            static fn (RatesImportPeriod $period): bool => '2026-11-02' === $period->to->format(DateFormatInterface::ISO),
        ))->willReturn([]);
        $repository->method('register')->willReturn([]);
        $repository->method('statistics')->willReturn([
            'waiting' => 2, 'processing' => 0, 'deferred' => 0, 'done' => 0, 'error' => 0,
        ]);
        $tester = $this->tester($repository);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from' => '2026-11-01', '--to' => '2026-11-10', '--yes' => true,
        ], ['capture_stderr_separately' => true, 'interactive' => false]));
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputWithoutCallingRepository(array $options, string $error): void
    {
        $tester = $this->tester($this->unusedRepository());

        self::assertSame(Command::INVALID, $tester->execute($options, ['capture_stderr_separately' => true]));
        self::assertStringContainsString($error, preg_replace('/\s+/u', ' ', $tester->getErrorOutput()) ?? '');
        self::assertSame('', $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidInputs(): iterable
    {
        foreach (['--from', '--to'] as $option) {
            foreach (['не дата', '2026-02-30', ''] as $date) {
                yield $option.': '.$date => [[$option => $date, '--days' => '1'], 'Некорректная календарная дата.'];
            }
        }
        foreach (['', 'abc', '1.5', '1e2', ' 30', '30 '] as $days) {
            yield 'Неверное число: '.$days => [['--days' => $days], 'Число дней должно быть целым числом.'];
        }
        yield 'Переполнение целого' => [['--days' => (string) \PHP_INT_MAX.'0'], 'Число дней должно быть целым числом в допустимом диапазоне.'];
        foreach (['0', '-1'] as $days) {
            yield 'Неположительное число: '.$days => [['--days' => $days], 'Число календарных дней должно быть положительным.'];
        }
        $combinationError = 'Укажите обе границы периода либо число дней с не более чем одной границей.';
        yield 'Только начало' => [['--from' => '2026-01-01'], $combinationError];
        yield 'Только конец' => [['--to' => '2026-01-30'], $combinationError];
        yield 'Все три опции' => [['--from' => '2026-01-01', '--to' => '2026-01-30', '--days' => '30'], $combinationError];
        yield 'Обратный диапазон' => [['--from' => '2026-01-30', '--to' => '2026-01-01'], 'Дата начала периода не может быть позже даты окончания.'];
        yield 'Будущее' => [['--from' => '2026-11-03', '--days' => '1'], 'Запрошенный период не пересекается с доступными датами источника.'];
    }

    public function testReportsDatePolicyRejectionAsInvalidInput(): void
    {
        $failure = new class('Дата недоступна у источника.') extends \RuntimeException implements GatewayNotSupportRateSetDateInterface {};
        $policy = $this->createMock(ExternalRateSetGatewayDatePolicyInterface::class);
        $policy->method('minRequestDate')->willReturn(DatePointParser::parse('1992-07-03'));
        $policy->expects(self::once())->method('assertAllowed')->willThrowException($failure);
        $tester = $this->tester($this->unusedRepository(), $policy);

        self::assertSame(Command::INVALID, $tester->execute([
            '--from' => '1992-07-03', '--to' => '1992-07-04',
        ], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Период выходит за пределы доступных дат источника котировок.', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    #[DataProvider('repositoryFailures')]
    public function testReportsRepositoryFailureWithoutSuccessMessage(string $stage): void
    {
        $repository = $this->createMock(RateImportJobRepositoryInterface::class);
        $failure = new \RuntimeException('Хранилище заданий недоступно.');
        $read = $repository->expects(self::once())->method('confirmedDates');
        if ('confirmedDates' === $stage) {
            $read->willThrowException($failure);
        } else {
            $read->willReturn([]);
        }
        $register = $repository->expects('confirmedDates' === $stage ? self::never() : self::once())->method('register');
        if ('register' === $stage) {
            $register->willThrowException($failure);
        } else {
            $register->willReturn([]);
        }
        $repository->expects('statistics' === $stage ? self::once() : self::never())->method('statistics')
            ->willThrowException($failure);
        $repository->expects(self::never())->method('delete');
        $tester = $this->tester($repository);

        self::assertSame(Command::FAILURE, $tester->execute(['--days' => '1'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString($failure->getMessage(), $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function repositoryFailures(): iterable
    {
        yield 'Чтение подтверждений' => ['confirmedDates'];
        yield 'Регистрация' => ['register'];
        yield 'Статистика' => ['statistics'];
    }

    private function tester(
        RateImportJobRepositoryInterface $repository,
        ?ExternalRateSetGatewayDatePolicyInterface $policy = null,
    ): CommandTester {
        if (null === $policy) {
            $policy = $this->createStub(ExternalRateSetGatewayDatePolicyInterface::class);
            $policy->method('minRequestDate')->willReturn(DatePointParser::parse('1992-07-03'));
        }
        // В UTC ещё предыдущий день: границы должны определяться по московскому календарю.
        $clock = new MockClock('2026-11-01 21:30:00 UTC');
        $endpoint = new RatesImportCommand(
            new RatesImportPeriodFactory($clock, $policy),
            new RatesImportHandler($repository),
        );

        return new CommandTester(new Command('app:rates:import', $endpoint));
    }

    private function unusedRepository(): RateImportJobRepositoryInterface&MockObject
    {
        $repository = $this->createMock(RateImportJobRepositoryInterface::class);
        foreach (['requeueUnconfirmedDone', 'confirmedDates', 'register', 'statistics', 'delete'] as $method) {
            $repository->expects(self::never())->method($method);
        }

        return $repository;
    }

    private function assertOutputValue(string $output, string $label, string $value): void
    {
        self::assertMatchesRegularExpression('/'.preg_quote($label, '/').'\\h+'.preg_quote($value, '/').'\\h*(?:\\R|$)/u', $output);
    }
}
