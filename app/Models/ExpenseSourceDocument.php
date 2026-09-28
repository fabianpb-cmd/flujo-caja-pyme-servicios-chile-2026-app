<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseSourceDocument extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;

    protected $guarded = ['company_id', 'expense_document_id', 'storage_path', 'sha256', 'created_by'];

    protected function casts(): array
    {
        return ['extracted_payload' => 'array'];
    }

    public function expenseDocument(): BelongsTo
    {
        return $this->belongsTo(ExpenseDocument::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
