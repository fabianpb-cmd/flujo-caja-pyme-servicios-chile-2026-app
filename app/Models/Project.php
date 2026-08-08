<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'invoice_date' => 'date',
            'projected_collection_date' => 'date',
            'sale_net' => 'decimal:2',
            'vat_rate' => 'decimal:6',
            'sale_total' => 'decimal:2',
            'contracted_hourly_rate' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(ProjectManager::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function projectStatus(): BelongsTo
    {
        return $this->belongsTo(RecordStatus::class);
    }

    public function billingStatus(): BelongsTo
    {
        return $this->belongsTo(RecordStatus::class);
    }

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }
}
