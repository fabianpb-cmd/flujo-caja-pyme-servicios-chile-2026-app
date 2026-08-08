<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends CompanyCatalog
{
    public function expenseSubcategories(): HasMany
    {
        return $this->hasMany(ExpenseSubcategory::class);
    }
}
