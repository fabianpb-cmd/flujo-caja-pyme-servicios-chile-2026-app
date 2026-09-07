<?php

namespace App\Services;

use App\Models\SalesDocument;
use DomainException;
use Illuminate\Support\Facades\DB;

class SalesDocumentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function confirm(SalesDocument $document, ?\App\Models\User $user = null): SalesDocument
    {
        return DB::transaction(function () use ($document, $user): SalesDocument {
            $locked = SalesDocument::query()->whereKey($document->id)->where('company_id', $document->company_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Borrador') throw new DomainException('Solo una factura en Borrador puede emitirse.');
            if ($locked->is_voided) throw new DomainException('Una factura anulada no puede emitirse.');
            if (! $locked->document_type_id) throw new DomainException('La factura requiere tipo de documento.');
            if (blank($locked->document_number)) throw new DomainException('La factura requiere número de documento.');
            if (blank($locked->issue_date)) throw new DomainException('La factura requiere fecha de emisión.');
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'Pendiente'])->save();
            $this->audit->record('sales_document.confirmed', $locked->refresh(), $user, $before);
            return $locked;
        });
    }
}
