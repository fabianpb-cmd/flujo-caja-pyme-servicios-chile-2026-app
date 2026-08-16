<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    public static function vigentStatusCodes(): array
    {
        return config('operational.projects.vigent_status_codes', ['active']);
    }

    public static function isVigentStatusCode(?string $code): bool
    {
        $normalized = strtolower(trim((string) $code));

        return in_array($normalized, array_map(fn (string $statusCode): string => strtolower(trim($statusCode)), static::vigentStatusCodes()), true);
    }

    protected $guarded = ['company_id', 'code', 'vat_rate', 'sale_total'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'invoice_date' => 'date',
            'projected_collection_date' => 'date',
            'sales_currency_id' => 'integer',
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

    public function salesCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'sales_currency_id');
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

    public function getSalesCurrencyDisplayCurrencyAttribute(): mixed
    {
        return $this->salesCurrency ?: 'CLP';
    }
}
