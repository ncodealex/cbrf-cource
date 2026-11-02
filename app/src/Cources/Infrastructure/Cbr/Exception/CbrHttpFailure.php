<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class CbrHttpFailure extends CbrGatewayException
{
    public function __construct(public readonly int $httpStatus)
    {
        parent::__construct('Банк России отклонил запрос: HTTP '.$httpStatus);
    }
}
