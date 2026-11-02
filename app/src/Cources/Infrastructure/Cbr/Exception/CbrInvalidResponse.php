<?php

declare(strict_types=1);

namespace App\Cources\Infrastructure\Cbr\Exception;

use App\Cources\Application\Contract\RateImportPermanentFailure;

final class CbrInvalidResponse extends CbrGatewayException implements RateImportPermanentFailure
{
}
