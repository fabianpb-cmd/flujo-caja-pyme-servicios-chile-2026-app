<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectBillingMilestone extends Model
{
    use BelongsToCompany;

    protected $guarded = ['company_id'];

    protected function casts(): array
    {
        return ['planned_invoice_date' => 'date', 'percentage' => 'decimal:4'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function salesDocuments(): HasMany { return $this->hasMany(SalesDocument::class); }
}
