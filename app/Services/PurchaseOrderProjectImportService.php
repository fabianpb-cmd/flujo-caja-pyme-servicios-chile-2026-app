<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Currency;
use App\Models\PaymentTerm;
use App\Models\Project;
use App\Models\ProjectSourceDocument;
use App\Models\User;
use App\Support\ChileanRut;
use App\Support\MassAssignment;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PurchaseOrderProjectImportService
{
    private const SESSION_KEY = 'project_oc_imports';

    public function __construct(
        private readonly OpenAiPurchaseOrderExtractor $extractor,
        private readonly AuditService $audit,
    ) {
    }

    public function analyze(int $companyId, User $user, UploadedFile $file): array
    {
        $this->cleanupExpiredTemporaryFiles();
        $this->validatePdf($file);
        $this->rateLimit($user);
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new DomainException('No se pudo leer el PDF de la OC.');
        }

        $sha256 = hash('sha256', $content);
        if (ProjectSourceDocument::query()->forCompany($companyId)->where('sha256', $sha256)->exists()) {
            throw new DomainException('Esta OC ya está asociada a un proyecto.');
        }

        $token = Str::random(64);
        $path = 'project-oc/tmp/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $content);
        try {
            $extracted = $this->extractor->extract($file->getClientOriginalName(), $content);
            if (($extracted['document_type'] ?? 'UNKNOWN') !== 'PURCHASE_ORDER') {
                throw new DomainException('El documento analizado no corresponde a una orden de compra.');
            }
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return [
            'token' => $token,
            'user_id' => $user->id,
            'company_id' => $companyId,
            'temporary_path' => $path,
            'sha256' => $sha256,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'extracted' => $this->normalizeMilestones($extracted),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function sessionState(array $imports, string $token, int $companyId, User $user): array
    {
        $state = $imports[$token] ?? null;
        if (! is_array($state) || (int) ($state['user_id'] ?? 0) !== $user->id || (int) ($state['company_id'] ?? 0) !== $companyId) {
            throw new DomainException('La vista previa de OC no está disponible para esta sesión.');
        }
        if (Carbon::parse((string) ($state['timestamp'] ?? now()->subHours(3)))->lt(now()->subHours(2))) {
            Storage::disk('local')->delete((string) ($state['temporary_path'] ?? ''));
            throw new DomainException('La vista previa de OC expiró. Analice el documento nuevamente.');
        }
        if (! Storage::disk('local')->exists((string) ($state['temporary_path'] ?? ''))) {
            throw new DomainException('El archivo temporal de la OC ya no está disponible.');
        }

        return $state;
    }

    public function matches(int $companyId, array $extracted): array
    {
        $taxId = ChileanRut::normalize($extracted['buyer_tax_id'] ?? null);
        $clients = Client::query()->forCompany($companyId)->get();
        $matchedClient = $taxId
            ? $clients->filter(fn (Client $client): bool => ChileanRut::normalize($client->tax_id) === $taxId)
            : collect();
        $reason = $matchedClient->count() === 1 ? 'Coincidencia exacta por RUT' : null;
        if ($matchedClient->count() !== 1 && filled($extracted['buyer_name'] ?? null)) {
            $normalized = $this->normalizeName((string) $extracted['buyer_name']);
            $matchedClient = $clients->filter(fn (Client $client): bool => $this->normalizeName($client->legal_name) === $normalized);
            $reason = $matchedClient->count() === 1 ? 'Coincidencia exacta por nombre' : null;
        }

        $currency = filled($extracted['currency_code'] ?? null)
            ? Currency::query()->forCompany($companyId)->where('code', strtoupper((string) $extracted['currency_code']))->first()
            : null;
        $paymentTerms = is_numeric($extracted['payment_terms_days'] ?? null)
            ? PaymentTerm::query()->forCompany($companyId)->where('days', (int) $extracted['payment_terms_days'])->get()
            : collect();
        $paymentTerm = $paymentTerms->count() === 1 ? $paymentTerms->first() : null;

        return [
            'client' => $matchedClient->count() === 1 ? $matchedClient->first() : null,
            'client_reason' => $reason,
            'currency' => $currency,
            'payment_term' => $paymentTerm,
        ];
    }

    public function attach(array $state, Project $project, User $user): ProjectSourceDocument
    {
        if (ProjectSourceDocument::query()->forCompany($project->company_id)->where('sha256', $state['sha256'])->exists()) {
            throw new DomainException('Esta OC ya está asociada a un proyecto.');
        }
        $disk = Storage::disk('local');
        $source = (string) $state['temporary_path'];
        $target = sprintf('project-source-documents/%d/%d/%s.pdf', $project->company_id, $project->id, Str::uuid());
        if (! $disk->exists($source) || ! $disk->move($source, $target)) {
            throw new DomainException('No se pudo almacenar de forma segura el documento fuente de la OC.');
        }

        try {
            /** @var ProjectSourceDocument $document */
            $document = MassAssignment::create(ProjectSourceDocument::class, [
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'document_type' => 'PURCHASE_ORDER',
                'document_number' => $state['extracted']['purchase_order_number'] ?? null,
                'original_filename' => $state['original_filename'],
                'storage_path' => $target,
                'mime_type' => $state['mime_type'],
                'file_size' => $state['file_size'],
                'sha256' => $state['sha256'],
                'extracted_payload' => $state['extracted'],
                'extraction_model' => config('assistant.model'),
                'created_by' => $user->id,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($target);
            throw $exception;
        }

        // Auditing records metadata only; the extracted payload and PDF remain private.
        $this->audit->record('project.source_document.attached', $document, $user, null, ['project_id' => $project->id, 'sha256' => $state['sha256']]);

        return $document;
    }

    public function defaults(array $state, array $matches): array
    {
        $data = $state['extracted'];
        return array_filter([
            'client_id' => $matches['client']?->id,
            'sales_currency_id' => $matches['currency']?->id,
            'payment_term_id' => $matches['payment_term']?->id,
            'name' => isset($data['service_description']) ? mb_substr((string) $data['service_description'], 0, 255) : null,
            'start_date' => $data['service_start_date'] ?? null,
            'end_date' => $data['service_end_date'] ?? null,
            'sale_net' => $data['net_amount'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function normalizeMilestones(array $extracted): array
    {
        $netAmount = is_numeric($extracted['net_amount'] ?? null) ? (float) $extracted['net_amount'] : null;
        $extracted['billing_milestones'] = collect($extracted['billing_milestones'] ?? [])
            ->values()
            ->map(function (array $milestone, int $index) use ($netAmount): array {
                $amount = is_numeric($milestone['amount'] ?? null) ? (float) $milestone['amount'] : null;
                $percentage = is_numeric($milestone['percentage'] ?? null) ? (float) $milestone['percentage'] : null;
                if ($percentage === null && $amount !== null && $netAmount !== null && $netAmount > 0) {
                    $percentage = round(($amount / $netAmount) * 100, 4);
                }
                return [
                    'sequence' => (int) ($milestone['sequence'] ?? ($index + 1)),
                    'name' => (string) ($milestone['name'] ?? ''),
                    'source' => ($milestone['source'] ?? 'SUGGESTED') === 'EXPLICIT' ? 'EXPLICIT' : 'SUGGESTED',
                    'percentage' => $percentage,
                    'amount' => $amount,
                    'planned_invoice_date' => $milestone['planned_invoice_date'] ?? null,
                    'evidence' => $milestone['evidence'] ?? null,
                    'confidence' => (float) ($milestone['confidence'] ?? 0),
                ];
            })->all();
        return $extracted;
    }

    private function validatePdf(UploadedFile $file): void
    {
        $detectedMime = strtolower((string) $file->getMimeType());
        $declaredMime = strtolower((string) $file->getClientMimeType());
        if (! $file->isValid() || strtolower((string) $file->getClientOriginalExtension()) !== 'pdf' || ! in_array('application/pdf', [$detectedMime, $declaredMime], true) || $file->getSize() === false || $file->getSize() > 10 * 1024 * 1024) {
            throw new DomainException('Seleccione un PDF válido de hasta 10 MB.');
        }
        $content = file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($content !== '%PDF-') {
            throw new DomainException('El archivo no tiene una estructura PDF válida.');
        }
    }

    private function rateLimit(User $user): void
    {
        $minute = 'purchase-order:minute:'.$user->id;
        $day = 'purchase-order:day:'.$user->id;
        if (RateLimiter::tooManyAttempts($minute, (int) config('assistant.purchase_order_per_minute')) || RateLimiter::tooManyAttempts($day, (int) config('assistant.purchase_order_per_day'))) {
            throw new DomainException('Se alcanzó el límite temporal de análisis de órdenes de compra.');
        }
        RateLimiter::hit($minute, 60);
        RateLimiter::hit($day, 86400);
    }

    private function cleanupExpiredTemporaryFiles(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(2)->timestamp;

        foreach ($disk->allFiles('project-oc/tmp') as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }

    private function normalizeName(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?: ''));
    }
}
