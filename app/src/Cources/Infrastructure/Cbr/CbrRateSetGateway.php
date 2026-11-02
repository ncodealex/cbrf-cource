<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr;

use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
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
            return $this->do($query);
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
    private function do(array $query): RateSet
    {
        try {
            $response = $this->client->request('GET', '/scripts/XML_daily.asp', ['query' => $query]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                $retryAt = $this->retryAfterParser->parse(1 === \count($retryAfter = $response->getHeaders(false)['retry-after'] ?? []) ? $retryAfter[0] : null);
                $response->cancel();
                throw new CbrRateLimited('Банк России ограничил частоту запросов', $status, nextAttempt: $retryAt);
            }
            if (200 !== $status) {
                $retryAt = 503 === $status ? $this->retryAfterParser->parse(1 === \count($retryAfter = $response->getHeaders(false)['retry-after'] ?? []) ? $retryAfter[0] : null) : null;
                $response->cancel();
                if ($status >= 500 && $status <= 599) {
                    throw new CbrUnavailable('Банк России вернул HTTP '.$status, $status, nextAttempt: $retryAt);
                }
                throw new CbrHttpFailure($status);
            }
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new CbrUnavailable('Не удалось получить курсы Банка России', previous: $e);
        }

        return $this->parse($body);
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
