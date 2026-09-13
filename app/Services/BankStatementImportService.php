<?php

namespace App\Services;

use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\CashAccount;
use App\Models\User;
use App\Support\MassAssignment;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BankStatementImportService
{
    public function __construct(
        private readonly BankStatementCsvParser $parser,
        private readonly AuditService $audit,
    ) {
    }

    public function import(int $companyId, int $accountId, UploadedFile $file, ?User $user = null): BankStatementImport
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
            throw new DomainException('Conciliación bancaria V1.2 solo admite archivos CSV.');
        }
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > 2 * 1024 * 1024) {
            throw new DomainException('El archivo CSV no es válido o excede 2 MB.');
        }
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new DomainException('No se pudo leer el archivo CSV de cartola.');
        }
        $parsed = $this->parser->parse($file);
        $fileHash = hash('sha256', $content);

        return DB::transaction(function () use ($companyId, $accountId, $file, $parsed, $fileHash, $user): BankStatementImport {
            $account = $this->eligibleAccount($companyId, $accountId, true);
            if (BankStatementImport::query()->forCompany($companyId)->where('cash_account_id', $account->id)->where('file_hash', $fileHash)->exists()) {
                throw new DomainException('Esta cartola CSV ya fue importada para la cuenta seleccionada.');
            }

            /** @var BankStatementImport $import */
            $import = MassAssignment::create(BankStatementImport::class, [
                'company_id' => $companyId,
                'cash_account_id' => $account->id,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => $fileHash,
                'statement_from' => $parsed['statement_from'],
                'statement_to' => $parsed['statement_to'],
                'imported_by_user_id' => $user?->id,
                'imported_at' => now(),
                'status' => 'imported',
                'row_count' => count($parsed['rows']),
            ]);

            $occurrences = [];
            foreach ($parsed['rows'] as $row) {
                $rowHash = $this->rowHash($row, $occurrences);
                if (BankStatementLine::query()->forCompany($companyId)->where('cash_account_id', $account->id)->where('row_hash', $rowHash)->exists()) {
                    throw new DomainException('La cartola contiene una línea ya importada para esta cuenta.');
                }
                MassAssignment::create(BankStatementLine::class, array_merge($row, [
                    'company_id' => $companyId,
                    'bank_statement_import_id' => $import->id,
                    'cash_account_id' => $account->id,
                    'row_hash' => $rowHash,
                    'status' => 'unmatched',
                ]));
            }

            $this->audit->record('bank_statement.imported', $import->refresh(), $user, null, [
                'cash_account_id' => $account->id,
                'row_count' => $import->row_count,
                'file_hash' => $fileHash,
            ]);

            return $import->refresh();
        });
    }

    private function eligibleAccount(int $companyId, int $accountId, bool $lock): CashAccount
    {
        $query = CashAccount::query()->forCompany($companyId)->with('currencyCatalog');
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->findOrFail($accountId);
        $currency = strtoupper((string) ($account->currencyCatalog?->code ?: $account->currency ?: 'CLP'));
        if (! $account->is_active || $currency !== 'CLP' || ! $account->opening_balance_date) {
            throw new DomainException('Seleccione una cuenta CLP activa con fecha de saldo inicial.');
        }

        return $account;
    }

    /**
     * Reliable external IDs dedupe globally for the account. Without one, the
     * occurrence is part of the hash so legitimate repeated transactions in a
     * single statement are preserved rather than collapsed.
     *
     * @param array{transaction_date: string, value_date: ?string, description: string, reference: ?string, amount: float, direction: string, external_id: ?string} $row
     * @param array<string, int> $occurrences
     */
    private function rowHash(array $row, array &$occurrences): string
    {
        if ($row['external_id'] !== null) {
            return hash('sha256', 'external:'.mb_strtolower(trim($row['external_id'])));
        }
        $fingerprint = implode('|', [
            $row['transaction_date'],
            $row['value_date'] ?? '',
            $row['direction'],
            number_format($row['amount'], 2, '.', ''),
            $this->normalizedText($row['description']),
            $this->normalizedText($row['reference'] ?? ''),
        ]);
        $occurrences[$fingerprint] = ($occurrences[$fingerprint] ?? 0) + 1;

        return hash('sha256', $fingerprint.'|'.$occurrences[$fingerprint]);
    }

    private function normalizedText(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?: '';
    }
}
