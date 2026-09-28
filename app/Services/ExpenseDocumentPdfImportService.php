<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\ExpenseDocument;
use App\Models\ExpenseSourceDocument;
use App\Models\User;
use App\Support\ChileanRut;
use App\Support\MassAssignment;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseDocumentPdfImportService
{
    private const SESSION_KEY = 'expense_pdf_imports';

    public function __construct(private readonly OpenAiExpenseDocumentExtractor $extractor, private readonly PayablesService $payables, private readonly AuditService $audit) {}

    public function analyze(int $companyId, User $user, UploadedFile $file): array
    {
        $this->cleanup();
        $this->validatePdf($file);
        $this->rateLimit($user);
        $content = file_get_contents($file->getRealPath());
        if ($content === false) throw new DomainException('No se pudo leer el PDF del gasto.');
        $sha256 = hash('sha256', $content);
        if (ExpenseSourceDocument::query()->forCompany($companyId)->where('sha256', $sha256)->exists()) {
            throw new DomainException('Este PDF ya está asociado a un gasto.');
        }
        $path = 'expense-pdf/tmp/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $content);
        try {
            $extracted = $this->normalize($this->extractor->extract($file->getClientOriginalName(), $content));
            if (($extracted['currency_code'] ?? null) && strtoupper((string) $extracted['currency_code']) !== 'CLP') {
                $extracted['warnings'][] = 'La moneda indicada no es CLP. La creación automática está bloqueada.';
            }
            if (filled($extracted['supplier_tax_id'] ?? null) && filled($extracted['document_number'] ?? null) && filled($extracted['document_type'] ?? null)
                && ExpenseSourceDocument::query()->forCompany($companyId)->where('supplier_tax_id', $extracted['supplier_tax_id'])->where('document_number', $extracted['document_number'])->where('document_type', $extracted['document_type'])->where('sha256', '!=', $sha256)->exists()) {
                throw new DomainException('Ya existe un documento del mismo proveedor, número y tipo con otro PDF. Revise el posible duplicado antes de continuar.');
            }
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        return ['token' => Str::random(64), 'user_id' => $user->id, 'company_id' => $companyId, 'temporary_path' => $path, 'sha256' => $sha256, 'original_filename' => $file->getClientOriginalName(), 'mime_type' => 'application/pdf', 'file_size' => strlen($content), 'extracted' => $extracted, 'timestamp' => now()->toIso8601String()];
    }

    public function sessionState(array $imports, string $token, int $companyId, User $user): array
    {
        $state = $imports[$token] ?? null;
        if (! is_array($state) || (int) ($state['user_id'] ?? 0) !== $user->id || (int) ($state['company_id'] ?? 0) !== $companyId) throw new DomainException('La vista previa del gasto no está disponible para esta sesión.');
        if (Carbon::parse((string) ($state['timestamp'] ?? now()->subHours(3)))->lt(now()->subHours(2))) throw new DomainException('La vista previa del gasto expiró. Analice el documento nuevamente.');
        if (! Storage::disk('local')->exists((string) ($state['temporary_path'] ?? ''))) throw new DomainException('El archivo temporal del gasto ya no está disponible.');
        return $state;
    }

    public function documentTypeMatch(int $companyId, ?string $label): ?DocumentType
    {
        if (blank($label)) return null;
        $needle = $this->normalizeText($label);
        return DocumentType::query()->forCompany($companyId)->where('domain', 'expense')->get()->first(fn (DocumentType $type): bool => in_array($needle, [$this->normalizeText($type->name), $this->normalizeText($type->code)], true));
    }

    public function attach(array $state, ExpenseDocument $expense, User $user): ExpenseSourceDocument
    {
        if (ExpenseSourceDocument::query()->forCompany($expense->company_id)->where('sha256', $state['sha256'])->exists()) throw new DomainException('Este PDF ya está asociado a un gasto.');
        $disk = Storage::disk('local');
        $source = (string) $state['temporary_path'];
        $target = sprintf('expense-source-documents/%d/%d/%s.pdf', $expense->company_id, $expense->id, Str::uuid());
        if (! $disk->exists($source) || ! $disk->move($source, $target)) throw new DomainException('No se pudo almacenar de forma segura el documento fuente.');
        try {
            $document = MassAssignment::create(ExpenseSourceDocument::class, ['company_id' => $expense->company_id, 'expense_document_id' => $expense->id, 'document_type' => $state['extracted']['document_type'] ?? null, 'document_number' => $state['extracted']['document_number'] ?? null, 'supplier_tax_id' => $state['extracted']['supplier_tax_id'] ?? null, 'original_filename' => $state['original_filename'], 'storage_path' => $target, 'mime_type' => $state['mime_type'], 'file_size' => $state['file_size'], 'sha256' => $state['sha256'], 'extracted_payload' => $state['extracted'], 'extraction_model' => config('assistant.model'), 'created_by' => $user->id]);
        } catch (\Throwable $exception) {
            $disk->delete($target);
            throw $exception;
        }
        $this->audit->record('expense.source_document.attached', $document, $user, null, ['expense_document_id' => $expense->id, 'sha256' => $state['sha256']]);
        return $document;
    }

    public function deleteOwnedSourceFiles(array $paths, int $expenseId): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable) {
                Log::warning('Expense source document cleanup failed after expense deletion', ['expense_document_id' => $expenseId, 'path_prefix' => 'expense-source-documents/']);
            }
        }
    }

    private function normalize(array $data): array
    {
        $data['document_type'] = filled($data['document_type'] ?? null) ? trim((string) $data['document_type']) : null;
        $data['supplier_tax_id'] = ChileanRut::normalize($data['supplier_tax_id'] ?? null);
        foreach (['issue_date', 'due_date'] as $field) $data[$field] = $this->date($data[$field] ?? null)?->toDateString();
        $data['currency_code'] = filled($data['currency_code'] ?? null) ? strtoupper(trim((string) $data['currency_code'])) : null;
        $data['warnings'] = array_values(array_unique((array) ($data['warnings'] ?? [])));
        $data['date_source'] = $data['due_date'] ? 'EXPLICIT' : 'UNRESOLVED';
        if (! $data['due_date'] && $data['issue_date'] && is_numeric($data['payment_terms_days'] ?? null)) { $data['due_date'] = Carbon::parse($data['issue_date'])->addDays((int) $data['payment_terms_days'])->toDateString(); $data['date_source'] = 'CALCULATED'; }
        return $data;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') return null;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) { try { $date = Carbon::createFromFormat($format, trim($value)); if ($date && $date->format($format) === trim($value)) return $date->startOfDay(); } catch (\Throwable) {} }
        return null;
    }

    private function normalizeText(string $value): string
    {
        return mb_strtoupper(trim(strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'])));
    }

    private function validatePdf(UploadedFile $file): void
    {
        if (! $file->isValid() || strtolower((string) $file->getClientOriginalExtension()) !== 'pdf' || ! in_array(strtolower((string) $file->getMimeType()), ['application/pdf'], true) || $file->getSize() === false || $file->getSize() > 10 * 1024 * 1024 || file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') throw new DomainException('Seleccione un PDF válido de hasta 10 MB.');
    }

    private function rateLimit(User $user): void
    {
        $minute = 'expense-pdf:minute:'.$user->id; $day = 'expense-pdf:day:'.$user->id;
        if (RateLimiter::tooManyAttempts($minute, 3) || RateLimiter::tooManyAttempts($day, 50)) throw new DomainException('Se alcanzó el límite temporal de análisis de gastos.');
        RateLimiter::hit($minute, 60); RateLimiter::hit($day, 86400);
    }

    private function cleanup(): void
    {
        $disk = Storage::disk('local'); $cutoff = now()->subHours(2)->timestamp;
        foreach ($disk->allFiles('expense-pdf/tmp') as $path) if ($disk->lastModified($path) < $cutoff) $disk->delete($path);
    }
}
