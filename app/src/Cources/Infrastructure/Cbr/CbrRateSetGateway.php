<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr;

use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
use App\Cources\Application\Import\RateImportIncident;
use App\Cources\Domain\Entity\RateSet;
use App\Cources\Infrastructure\Cbr\Exception\CbrGatewayException;
use App\Cources\Infrastructure\Cbr\Exception\CbrHttpFailure;
use App\Cources\Infrastructure\Cbr\Exception\CbrInvalidResponse;
use App\Cources\Infrastructure\Cbr\Exception\CbrRateLimited;
use App\Cources\Infrastructure\Cbr\Exception\CbrRequestRateLimitExceeded;
use App\Cources\Infrastructure\Cbr\Exception\CbrSerialization;
use App\Cources\Infrastructure\Cbr\Exception\CbrUnavailable;
use App\Cources\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Cources\Infrastructure\Cbr\Response\RateSetResponse;
use App\Shared\Domain\DateFormatInterface;
use App\Support\RateLimiter\Application\RequestRateLimiter;
use App\Support\Retry\Application\RetryableFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(ExternalRateSetGatewayInterface::class)]
final readonly class CbrRateSetGateway implements ExternalRateSetGatewayInterface
{
    public function __construct(
        #[Autowire(service: 'cbr.client')]
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private SerializerInterface $serializer,
        private CbrRateSetResponseMapper $mapper,
        private RequestRateLimiter $rateLimiter,
        private RetryAfterParser $retryAfterParser,
        #[Autowire(service: 'monolog.logger.cbrf')]
        private ?LoggerInterface $httpLogger = null,
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
    ) {
    }

    /**
     * @throws RetryableFailure
     */
    public function fetch(DatePoint $requestDate): RateSet
    {
        return $this->request(['date_req' => $requestDate->format('d/m/Y')], $requestDate);
    }

    /**
     * @param array<string, string> $query
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws RetryableFailure
     * @throws ServerExceptionInterface
     */
    private function request(array $query, ?DatePoint $requestedDate = null): RateSet
    {
        $decision = $this->rateLimiter->acquire();
        if (!$decision->accepted) {
            $this->logger->notice('Достигнут глобальный лимит обращений к ЦБ', [
                'requested_date' => $requestedDate?->format(DateFormatInterface::ISO),
                'retry_at' => $decision->retryAt->format(\DATE_ATOM),
                'exception_class' => CbrRequestRateLimitExceeded::class,
            ]);
            throw new CbrRequestRateLimitExceeded($decision->retryAt);
        }
        try {
            return $this->do($query, $requestedDate);
        } catch (CbrGatewayException $e) {
            $this->logger->warning('Ошибка получения официального набора курсов ЦБ', [
                'requested_date' => $requestedDate?->format(DateFormatInterface::ISO),
                'http_status' => $e->httpStatus ?? null,
                'retry_at' => $e instanceof RetryableFailure ? $e->retryAt()?->format(\DATE_ATOM) : null,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    private function do(array $query, ?DatePoint $requestedDate): RateSet
    {
        $requestId = RateImportIncident::create();
        $start = hrtime(true);
        $context = ['request_id' => $requestId, 'method' => 'GET', 'endpoint' => '/scripts/XML_daily.asp', 'requested_date' => $requestedDate?->format(DateFormatInterface::ISO)];
        $this->logHttp('info', 'cbr.request', $context);
        $body = null;
        $status = null;
        try {
            try {
                $response = $this->client->request('GET', '/scripts/XML_daily.asp', ['query' => $query]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                if (429 === $status || 503 === $status) {
                    $retryAfter = $response->getHeaders(false)['retry-after'] ?? [];
                    $retryAt = $this->retryAfterParser->parse(1 === \count($retryAfter) ? $retryAfter[0] : null);
                    if (429 === $status) {
                        throw new CbrRateLimited('Банк России ограничил частоту запросов', $status, nextAttempt: $retryAt);
                    }
                    throw new CbrUnavailable('Банк России вернул HTTP 503', $status, nextAttempt: $retryAt);
                }
                if (200 !== $status) {
                    if ($status >= 500 && $status <= 599) {
                        throw new CbrUnavailable('Банк России вернул HTTP '.$status, $status);
                    }
                    throw new CbrHttpFailure($status);
                }
            } catch (TransportExceptionInterface $e) {
                throw new CbrUnavailable('Не удалось получить курсы Банка России', previous: $e);
            }
            $rateSet = $this->parse($body);
        } catch (\Throwable $e) {
            $this->logHttp('error', 'cbr.failure', [...$context, ...$this->responseMetadata($body, $status, $start), 'exception_class' => $e::class, 'retryAt' => $e instanceof RetryableFailure ? $e->retryAt()?->format(\DATE_ATOM) : null]);
            throw $e;
        }
        $this->logHttp('info', 'cbr.response', [...$context, ...$this->responseMetadata($body, $status, $start), 'effective_date' => $rateSet->date()]);

        return $rateSet;
    }

    /** @return array<string, mixed> */
    private function responseMetadata(?string $body, ?int $status, int $start): array
    {
        $metadata = ['http_status' => $status, 'duration_ms' => (hrtime(true) - $start) / 1000000, 'response_bytes' => null === $body ? 0 : \strlen($body)];
        if (null !== $body) {
            $metadata['sha256'] = hash('sha256', $body);
            if ('dev' === $this->environment) {
                $metadata['xml'] = $body;
                if (1 !== preg_match('//u', $body)) {
                    // JSON требует UTF-8; оригинальные байты сохраняются без потерь отдельно.
                    $metadata['xml'] = @iconv('Windows-1251', 'UTF-8', $body) ?: $body;
                    $metadata['xml_base64'] = base64_encode($body);
                }
            }
        }

        return $metadata;
    }

    /** @param array<string, mixed> $context */
    private function logHttp(string $level, string $event, array $context): void
    {
        try {
            $this->httpLogger?->log($level, $event, $context);
        } catch (\Throwable) { /* Ошибка журнала не изменяет результат внешней операции. */
        }
    }

    private function parse(string $body): RateSet
    {
        if ('' === trim($body)) {
            throw new CbrInvalidResponse('Пустое тело ответа Банка России');
        }
        try {
            $response = $this->serializer->deserialize($body, RateSetResponse::class, 'xml', [
                XmlEncoder::TYPE_CAST_ATTRIBUTES => false,
                XmlEncoder::LOAD_OPTIONS => \LIBXML_NONET | \LIBXML_NOBLANKS,
            ]);
        } catch (SerializerException $e) {
            throw new CbrSerialization('Ошибка сериализации ответа от ЦБ РФ', 0, $e);
        }

        return $this->mapper->map($response);
    }

    /**
     * @throws RetryableFailure
     */
    public function fetchLatest(): RateSet
    {
        return $this->request([]);
    }
}
