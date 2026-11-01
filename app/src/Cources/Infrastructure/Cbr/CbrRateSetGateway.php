<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr;

use App\Cources\Application\Contract\ExternalRateSetGatewayInterface;
use App\Cources\Application\Exception\CbrGatewayException;
use App\Cources\Application\Exception\CbrInvalidResponse;
use App\Cources\Application\Exception\CbrRateLimited;
use App\Cources\Application\Exception\CbrUnavailable;
use App\Cources\Domain\Entity\RateSet;
use App\Cources\Infrastructure\Cbr\Mapper\CbrRateSetResponseMapper;
use App\Cources\Infrastructure\Cbr\Response\RateSetResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\SerializerInterface;
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
    ) {
    }

    public function fetch(DatePoint $requestDate): RateSet
    {
        return $this->request(['date_req' => $requestDate->format('d/m/Y')]);
    }

    /** @param array<string, string> $query */
    private function request(array $query): RateSet
    {
        try {
            try {
                $response = $this->client->request('GET', '/scripts/XML_daily.asp', ['query' => $query]);
                $status   = $response->getStatusCode();
                if (429 === $status) {
                    $response->cancel();
                    throw new CbrRateLimited('Банк России ограничил частоту запросов', $status);
                }
                if (200 !== $status) {
                    $response->cancel();
                    throw new CbrUnavailable('Банк России вернул HTTP ' . $status, $status);
                }
                $body = $response->getContent(false);
            } catch (TransportExceptionInterface $e) {
                throw new CbrUnavailable('Не удалось получить курсы Банка России', previous: $e);
            }

            return $this->parse($body);
        } catch (CbrGatewayException $e) {
            $this->logger->warning('Ошибка получения официального набора курсов ЦБ', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
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
                XmlEncoder::LOAD_OPTIONS         => \LIBXML_NONET | \LIBXML_NOBLANKS,
            ]);
        } catch (SerializerException $e) {
            throw new CbrInvalidResponse('Некорректная структура ответа Банка России', 0, $e);
        }

        return $this->mapper->map($response);
    }

    public function fetchLatest(): RateSet
    {
        return $this->request([]);
    }
}
