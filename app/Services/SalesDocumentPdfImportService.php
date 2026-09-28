<?php
namespace App\Services;
use App\Models\Client;
use App\Models\DocumentType;
use App\Models\SalesDocument;
use App\Models\SalesSourceDocument;
use App\Models\User;
use App\Support\ChileanRut;
use App\Support\MassAssignment;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class SalesDocumentPdfImportService
{
    public function __construct(private readonly OpenAiSalesDocumentExtractor $extractor, private readonly AuditService $audit) {}
    public function analyze(int $companyId, User $user, UploadedFile $file): array
    {
        $this->cleanup(); $this->validatePdf($file); $this->rateLimit($user); $content = file_get_contents($file->getRealPath()); if ($content === false) throw new DomainException('No se pudo leer el PDF de la factura.');
        $sha = hash('sha256', $content); if (SalesSourceDocument::query()->forCompany($companyId)->where('sha256', $sha)->exists()) throw new DomainException('Este PDF ya está asociado a una factura.');
        $path = 'sales-pdf/tmp/'.Str::uuid().'.pdf'; Storage::disk('local')->put($path, $content);
        try { $data = $this->normalize($this->extractor->extract($file->getClientOriginalName(), $content)); if (SalesSourceDocument::query()->forCompany($companyId)->where('document_type', $data['document_type'])->where('document_number', $data['document_number'])->where('customer_tax_id', $data['customer_tax_id'])->where('sha256', '!=', $sha)->exists()) throw new DomainException('Ya existe una factura del mismo cliente, número y tipo con otro PDF.'); }
        catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return ['token' => Str::random(64), 'user_id' => $user->id, 'company_id' => $companyId, 'temporary_path' => $path, 'sha256' => $sha, 'original_filename' => $file->getClientOriginalName(), 'mime_type' => 'application/pdf', 'file_size' => strlen($content), 'extracted' => $data, 'timestamp' => now()->toIso8601String()];
    }
    public function sessionState(array $imports, string $token, int $companyId, User $user): array
    {
        $state = $imports[$token] ?? null; if (! is_array($state) || (int)($state['user_id'] ?? 0) !== $user->id || (int)($state['company_id'] ?? 0) !== $companyId) throw new DomainException('La vista previa de la factura no está disponible para esta sesión.');
        if (Carbon::parse((string)($state['timestamp'] ?? now()->subHours(3)))->lt(now()->subHours(2))) throw new DomainException('La vista previa de la factura expiró.'); if (! Storage::disk('local')->exists((string)$state['temporary_path'])) throw new DomainException('El archivo temporal ya no está disponible.'); return $state;
    }
    public function matchClient(int $companyId, ?string $rut, ?string $name): ?Client
    {
        $clients = Client::query()->forCompany($companyId)->get(); $normalizedRut = ChileanRut::normalize($rut); if ($normalizedRut) { $match = $clients->filter(fn (Client $c): bool => ChileanRut::normalize($c->tax_id) === $normalizedRut); if ($match->count() === 1) return $match->first(); }
        $needle = $this->normalizeText($name); if ($needle === '') return null; $match = $clients->filter(fn (Client $c): bool => $this->normalizeText($c->legal_name) === $needle); return $match->count() === 1 ? $match->first() : null;
    }
    public function matchDocumentType(int $companyId, ?string $label): ?DocumentType
    { $needle = $this->normalizeText($label); return DocumentType::query()->forCompany($companyId)->where('domain', 'sales')->get()->first(fn (DocumentType $t): bool => in_array($needle, [$this->normalizeText($t->name), $this->normalizeText($t->code)], true)); }
    public function compare(array $state, SalesDocument $document): array
    {
        $data = $state['extracted']; $checks = ['client' => true, 'net_amount' => !is_numeric($data['net_amount'] ?? null) || abs((float)$data['net_amount'] - (float)$document->net_amount) < .01, 'vat_amount' => !is_numeric($data['vat_amount'] ?? null) || abs((float)$data['vat_amount'] - (float)$document->vat_amount) < .01, 'total_amount' => !is_numeric($data['total_amount'] ?? null) || abs((float)$data['total_amount'] - (float)$document->gross_amount) < .01, 'issue_date' => blank($data['issue_date'] ?? null) || optional($document->issue_date)->toDateString() === $data['issue_date'], 'due_date' => blank($data['due_date'] ?? null) || blank($document->due_date) || $document->due_date->toDateString() === $data['due_date']];
        return $checks + ['ok' => !in_array(false, $checks, true)];
    }
    public function attach(array $state, SalesDocument $document, User $user): SalesSourceDocument
    {
        if (SalesSourceDocument::query()->forCompany($document->company_id)->where('sha256', $state['sha256'])->exists()) throw new DomainException('Este PDF ya está asociado a una factura.'); $disk = Storage::disk('local'); $source = $state['temporary_path']; $target = sprintf('sales-source-documents/%d/%d/%s.pdf', $document->company_id, $document->id, Str::uuid()); if (!$disk->exists($source) || !$disk->move($source, $target)) throw new DomainException('No se pudo almacenar el documento fuente.');
        try { $sourceDocument = MassAssignment::create(SalesSourceDocument::class, ['company_id' => $document->company_id, 'sales_document_id' => $document->id, 'document_type' => $state['extracted']['document_type'] ?? null, 'document_number' => $state['extracted']['document_number'] ?? null, 'customer_tax_id' => $state['extracted']['customer_tax_id'] ?? null, 'original_filename' => $state['original_filename'], 'storage_path' => $target, 'mime_type' => $state['mime_type'], 'file_size' => $state['file_size'], 'sha256' => $state['sha256'], 'extracted_payload' => $state['extracted'], 'extraction_model' => config('assistant.model'), 'created_by' => $user->id]); } catch (\Throwable $e) { $disk->delete($target); throw $e; }
        $this->audit->record('sales.source_document.attached', $sourceDocument, $user, null, ['sales_document_id' => $document->id, 'sha256' => $state['sha256']]); return $sourceDocument;
    }
    public function deleteOwnedSourceFiles(array $paths, int $documentId): void { foreach ($paths as $path) try { Storage::disk('local')->delete($path); } catch (\Throwable) { Log::warning('Sales source document cleanup failed after sales deletion', ['sales_document_id' => $documentId, 'path_prefix' => 'sales-source-documents/']); } }
    private function normalize(array $data): array { foreach (['issue_date','due_date'] as $f) $data[$f] = $this->date($data[$f] ?? null)?->toDateString(); $data['issuer_tax_id'] = ChileanRut::normalize($data['issuer_tax_id'] ?? null); $data['customer_tax_id'] = ChileanRut::normalize($data['customer_tax_id'] ?? null); $data['currency_code'] = filled($data['currency_code'] ?? null) ? strtoupper(trim((string)$data['currency_code'])) : null; return $data; }
    private function date(mixed $v): ?Carbon { if (!is_string($v) || trim($v)==='') return null; foreach(['Y-m-d','d/m/Y','d-m-Y'] as $f) try { $d=Carbon::createFromFormat($f,trim($v)); if($d && $d->format($f)===trim($v)) return $d->startOfDay(); } catch(\Throwable){} return null; }
    private function normalizeText(?string $v): string { return mb_strtoupper(trim(strtr((string)$v,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']))); }
    private function validatePdf(UploadedFile $f): void { if(!$f->isValid() || strtolower((string)$f->getClientOriginalExtension())!=='pdf' || strtolower((string)$f->getMimeType())!=='application/pdf' || $f->getSize()===false || $f->getSize()>10*1024*1024 || file_get_contents($f->getRealPath(),false,null,0,5)!=='%PDF-') throw new DomainException('Seleccione un PDF válido de hasta 10 MB.'); }
    private function rateLimit(User $u): void { $m='sales-pdf:minute:'.$u->id; $d='sales-pdf:day:'.$u->id; if(RateLimiter::tooManyAttempts($m,3)||RateLimiter::tooManyAttempts($d,50)) throw new DomainException('Se alcanzó el límite temporal de análisis de facturas.'); RateLimiter::hit($m,60); RateLimiter::hit($d,86400); }
    private function cleanup(): void { $disk=Storage::disk('local'); $cut=now()->subHours(2)->timestamp; foreach($disk->allFiles('sales-pdf/tmp') as $p) if($disk->lastModified($p)<$cut) $disk->delete($p); }
}
