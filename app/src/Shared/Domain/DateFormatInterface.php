<?php

declare(strict_types=1);

namespace App\Shared\Domain;

interface DateFormatInterface
{
    public const string UI = 'd.m.Y';

    public const string ISO = 'Y-m-d';
}
