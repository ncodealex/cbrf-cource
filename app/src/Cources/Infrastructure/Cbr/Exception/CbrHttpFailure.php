<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use App\Cources\Application\Contract\RateImportPermanentFailure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class CbrHttpFailure extends CbrGatewayException implements RateImportPermanentFailure
{
    public function __construct(public readonly int $httpStatus)
    {
        parent::__construct('Банк России отклонил запрос: HTTP '.$httpStatus);
    }
}
