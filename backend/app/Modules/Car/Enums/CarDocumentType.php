<?php

namespace App\Modules\Car\Enums;

enum CarDocumentType: string
{
    case Fitness = 'fitness';
    case TaxToken = 'tax_token';
    case Insurance = 'insurance';
    case RoutePermit = 'route_permit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fitness => 'Fitness certificate',
            self::TaxToken => 'Tax token',
            self::Insurance => 'Insurance',
            self::RoutePermit => 'Route permit',
            self::Other => 'Other',
        };
    }
}
