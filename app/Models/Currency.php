<?php

namespace App\Models;

class Currency extends CompanyCatalog
{
    protected function casts(): array
    {
        return parent::casts() + [
            'minor_units' => 'integer',
            'is_base_currency' => 'boolean',
        ];
    }
}
