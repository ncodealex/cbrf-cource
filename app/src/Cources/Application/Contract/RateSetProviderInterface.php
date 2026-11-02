<?php

declare(strict_types=1);

namespace App\Cources\Application\Contract;

use App\Cources\Domain\Entity\RateSet;
use Symfony\Component\Clock\DatePoint;

/**
 *
 */
interface RateSetProviderInterface
{
    public function resolve(DatePoint $requestDate): RateSet;
}
