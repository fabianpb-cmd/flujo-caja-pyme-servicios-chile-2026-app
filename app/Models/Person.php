<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use App\Support\ChileanRut;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['company_id', 'code'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'additional_health_plan' => 'decimal:2',
            'monthly_value' => 'decimal:2',
            'hourly_value' => 'decimal:2',
            'hourly_rate_currency_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $person): void {
            if ($person->first_names || $person->paternal_surname || $person->maternal_surname) {
                $person->name = trim(collect([
                    $person->first_names,
                    $person->paternal_surname,
                    $person->maternal_surname,
                ])->filter()->implode(' '));
            }

            if ($person->rut !== null) {
                $person->rut = ChileanRut::normalize($person->rut);
            }

            $person->phone_country_code = $person->phone_country_code ?: '+56';
            $person->phone_number = self::normalizeDigits($person->phone_number);
            $person->secondary_phone = self::normalizeDigits($person->secondary_phone);
            $person->emergency_contact_phone = self::normalizeDigits($person->emergency_contact_phone);
            $person->bank_account_holder_rut = ChileanRut::normalize($person->bank_account_holder_rut);
        });
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employmentMode(): BelongsTo
    {
        return $this->belongsTo(EmploymentMode::class);
    }

    public function employmentContractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function afp(): BelongsTo
    {
        return $this->belongsTo(Afp::class);
    }

    public function workerStatus(): BelongsTo
    {
        return $this->belongsTo(RecordStatus::class);
    }

    public function healthSystemCatalog(): BelongsTo
    {
        return $this->belongsTo(HealthSystem::class, 'health_system_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function hourlyRateCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'hourly_rate_currency_id');
    }

    public function bankAccountType(): BelongsTo
    {
        return $this->belongsTo(BankAccountType::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(ProjectAssignment::class)->latestOfMany()->with('project');
    }

    public function getFullNameAttribute(): string
    {
        $nameParts = array_filter([
            $this->first_names,
            $this->paternal_surname,
            $this->maternal_surname,
        ]);

        $fullName = trim(implode(' ', $nameParts));

        return $fullName !== '' ? $fullName : (string) $this->name;
    }

    public function getRutFormattedAttribute(): ?string
    {
        return ChileanRut::format($this->rut);
    }

    public function getBankAccountHolderRutFormattedAttribute(): ?string
    {
        return ChileanRut::format($this->bank_account_holder_rut);
    }

    public function getCurrentAssignmentLabelAttribute(): string
    {
        $assignment = $this->relationLoaded('currentAssignment')
            ? $this->currentAssignment
            : $this->currentAssignment()->with('project')->first();

        if (! $assignment) {
            return '—';
        }

        return trim((string) ($assignment->project?->code ?: $assignment->code).' '.(string) ($assignment->project?->name ?: ''));
    }

    public function getHourlyRateDisplayCurrencyAttribute(): mixed
    {
        if ($this->hourly_rate_unit_type === 'UF') {
            return 'UF';
        }

        return $this->hourlyRateCurrency ?: 'CLP';
    }

    private static function normalizeDigits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return $digits === '' ? null : $digits;
    }
}
