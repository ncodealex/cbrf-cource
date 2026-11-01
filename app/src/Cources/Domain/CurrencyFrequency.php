<?php

declare(strict_types=1);

namespace App\Cources\Domain;

/**
 * Периодичность установления курса валюты Банком России (справочник XML_valFull).
 */
enum CurrencyFrequency: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
}
