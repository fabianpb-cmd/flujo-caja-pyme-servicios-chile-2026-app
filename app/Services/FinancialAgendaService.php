<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Support\UiFormatter;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinancialAgendaService
{
    public function __construct(
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly LegalObligationService $obligations,
    ) {
    }

    public function forCompany(int $companyId, ?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();
        $limit = $today->copy()->addDays(30)->endOfDay();

        $items = collect()
            ->concat($this->receivables($companyId, $today, $limit))
            ->concat($this->payables($companyId, $today, $limit))
            ->concat($this->obligationItems($companyId, $today, $limit))
            ->sortBy([
                ['is_overdue', 'desc'],
                ['due_date', 'asc'],
            ])
            ->values();

        $receivable = fn (Collection $rows): float => UiFormatter::roundAmount($rows->where('direction', 'receivable')->sum('balance'), 'CLP');
        $payable = fn (Collection $rows): float => UiFormatter::roundAmount($rows->where('direction', 'payable')->sum('balance'), 'CLP');
        $next7 = $items->filter(fn (array $item): bool => ! $item['is_overdue'] && $item['due_date']->lte($today->copy()->addDays(7)));
        $next30 = $items->filter(fn (array $item): bool => ! $item['is_overdue']);

        return [
            'items' => $items,
            'summary' => [
                'receivable_overdue' => $receivable($items->where('is_overdue', true)->where('direction', 'receivable')),
                'payable_overdue' => $payable($items->where('is_overdue', true)->where('direction', 'payable')),
                'receivable_next_7' => $receivable($next7),
                'payable_next_7' => $payable($next7),
                'net_next_30' => UiFormatter::roundAmount($receivable($next30) - $payable($next30), 'CLP'),
            ],
            'today' => $today,
        ];
    }

    private function receivables(int $companyId, Carbon $today, Carbon $limit): Collection
    {
        $documents = SalesDocument::query()
            ->forCompany($companyId)
            ->whereNotIn('status', ['Borrador', 'Anulado', 'Pagado'])
            ->where('is_voided', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit->toDateString())
            ->get();

        return $this->documentItems($documents, $today, 'receivable', fn (SalesDocument $document): float => $this->receivables->balance($document));
    }

    private function payables(int $companyId, Carbon $today, Carbon $limit): Collection
    {
        $documents = ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereNotIn('payment_status', ['Borrador', 'Anulado', 'Pagado'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit->toDateString())
            ->get();

        return $this->documentItems($documents, $today, 'payable', fn (ExpenseDocument $document): float => $this->payables->balance($document));
    }

    private function obligationItems(int $companyId, Carbon $today, Carbon $limit): Collection
    {
        return LegalObligation::query()
            ->forCompany($companyId)
            ->whereNotIn('status', ['Anulado', 'Pagado'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit->toDateString())
            ->get()
            ->map(function (LegalObligation $obligation) use ($today): ?array {
                $balance = $this->obligations->balance($obligation);
                if ($balance <= 0) {
                    return null;
                }

                return $this->item(
                    dueDate: Carbon::parse($obligation->due_date),
                    direction: 'payable',
                    type: 'Obligación',
                    code: $obligation->code,
                    counterparty: $obligation->obligation_type ?: 'Obligación legal',
                    project: null,
                    balance: $balance,
                    actionUrl: route('management.obligations', ['period' => Carbon::parse($obligation->period_date)->format('Y-m')]),
                    today: $today,
                );
            })
            ->filter()
            ->values();
    }

    private function documentItems(Collection $documents, Carbon $today, string $direction, callable $balanceResolver): Collection
    {
        $clientIds = $documents->pluck('client_id')->filter()->unique();
        $projectIds = $documents->pluck('project_id')->filter()->unique();
        $clients = Client::query()->whereIn('id', $clientIds)->get()->keyBy('id');
        $projects = Project::query()->whereIn('id', $projectIds)->get()->keyBy('id');

        return $documents->map(function (SalesDocument|ExpenseDocument $document) use ($today, $direction, $balanceResolver, $clients, $projects): ?array {
            $balance = $balanceResolver($document);
            if ($balance <= 0) {
                return null;
            }

            $client = $clients->get($document->client_id);
            $project = $projects->get($document->project_id);

            return $this->item(
                dueDate: Carbon::parse($document->due_date),
                direction: $direction,
                type: $direction === 'receivable' ? 'Factura' : 'Gasto',
                code: $document->code,
                counterparty: $direction === 'receivable' ? ($client?->legal_name ?: 'Cliente') : ($document->vendor_name ?: 'Proveedor'),
                project: $project?->name,
                balance: $balance,
                actionUrl: route('operational.show', [$direction === 'receivable' ? 'sales-documents' : 'expense-documents', $document->id]),
                today: $today,
            );
        })->filter()->values();
    }

    private function item(Carbon $dueDate, string $direction, string $type, string $code, string $counterparty, ?string $project, float $balance, string $actionUrl, Carbon $today): array
    {
        return [
            'priority' => $dueDate->lt($today) ? 'Vencido' : ($dueDate->isSameDay($today) ? 'Hoy' : ($dueDate->lte($today->copy()->addDays(7)) ? '7 días' : '30 días')),
            'is_overdue' => $dueDate->lt($today),
            'due_date' => $dueDate,
            'type' => $type,
            'code' => $code,
            'counterparty' => $counterparty,
            'project' => $project,
            'direction' => $direction,
            'balance' => UiFormatter::roundAmount($balance, 'CLP'),
            'action_url' => $actionUrl,
        ];
    }
}
