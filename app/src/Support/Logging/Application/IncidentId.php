<?php

declare(strict_types=1);

namespace App\Support\Logging\Application;

final readonly class IncidentId
{
    public static function create(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 15) | 64);
        $bytes[8] = \chr((\ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
