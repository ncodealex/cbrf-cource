<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cources\Infrastructure\Cbr;

use App\Courses\Infrastructure\Cbr\RetryAfterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use const DATE_ATOM;

final class RetryAfterParserTest extends TestCase
{
    private MockClock $clock;

    private RetryAfterParser $parser;

    /**
     * @return iterable<string, array{string}>
     */
    public static function httpDates(): iterable
    {
        yield 'IMF-fixdate' => ['Wed, 21 Oct 2026 07:28:00 GMT'];
        yield 'RFC 850' => ['Wednesday, 21-Oct-26 07:28:00 GMT'];
        yield 'asctime' => ['Wed Oct 21 07:28:00 2026'];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'отсутствует' => [null];
        yield 'пустой' => [''];
        yield 'пробелы' => ['   '];
        yield 'отрицательная задержка' => ['-1'];
        yield 'дробная задержка' => ['1.5'];
        yield 'не дата' => ['завтра'];
        yield 'некорректная дата' => ['Wed, 32 Oct 2026 07:28:00 GMT'];
        yield 'переполнение' => [str_repeat('9', 100)];
    }

    public function testParsesDelaySecondsFromCurrentMoment(): void
    {
        $retryAt = $this->parser->parse('40');

        self::assertSame('2026-10-21T10:28:40+03:00', $retryAt?->format(DATE_ATOM));
    }

    public function testAcceptsZeroAndLeadingZeros(): void
    {
        self::assertSame(
            '2026-10-21T10:28:00+03:00',
            $this->parser->parse('000')?->format(DATE_ATOM),
        );
    }

    #[DataProvider('httpDates')]
    public function testParsesHttpDateAsAbsoluteMoment(string $header): void
    {
        $retryAt = $this->parser->parse($header);

        self::assertSame('2026-10-21T07:28:00+00:00', $retryAt?->format(DATE_ATOM));
    }

    #[DataProvider('invalidHeaders')]
    public function testRejectsUnreliableHeader(?string $header): void
    {
        self::assertNull($this->parser->parse($header));
    }

    protected function setUp(): void
    {
        $this->clock  = new MockClock('2026-10-21 10:28:00 Europe/Moscow');
        $this->parser = new RetryAfterParser($this->clock);
    }
}
