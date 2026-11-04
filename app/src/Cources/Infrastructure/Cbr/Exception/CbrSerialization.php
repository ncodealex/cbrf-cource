<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use App\Shared\Contracts\Rates\RateImportPermanentFailure;

final class CbrSerialization extends CbrGatewayException implements RateImportPermanentFailure
{
}
