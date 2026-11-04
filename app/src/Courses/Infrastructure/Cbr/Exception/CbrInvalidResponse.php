<?php

declare(strict_types=1);

namespace App\Courses\Infrastructure\Cbr\Exception;

use App\Shared\Contracts\Rates\RateImportPermanentFailure;

final class CbrInvalidResponse extends CbrGatewayException implements RateImportPermanentFailure
{
}
