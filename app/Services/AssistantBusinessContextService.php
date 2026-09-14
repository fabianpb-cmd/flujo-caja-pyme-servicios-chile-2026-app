<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\User;
use App\Support\UiFormatter;
use DomainException;

class AssistantBusinessContextService
{
    public function __construct(
        private readonly BillingStrategyService $strategies,
        private readonly ProjectBillingMilestoneService $milestones,
    ) {
    }

    public function forUser(User $user, array $ids): array
    {
        $projectId = $ids['project_id'] ?? null;
        if (! $projectId) {
            return [];
        }

        $project = Project::query()->forCompany($user->company_id)->with(['salesCurrency', 'paymentTerm', 'client.paymentTerm', 'contractType', 'billingMilestones.salesDocuments'])->find($projectId);
        if (! $project) {
            return [];
        }

        $strategy = $this->strategies->forProject($project);
        $context = [
            'project' => [
                'strategy' => $strategy,
                'currency_code' => UiFormatter::currencyCode($project->salesCurrency ?: 'CLP'),
                'has_payment_term' => (bool) ($project->payment_term_id || $project->client?->payment_term_id),
                'pending_milestones' => $project->billingMilestones->filter(fn (ProjectBillingMilestone $milestone) => ! $milestone->salesDocuments->where('is_voided', false)->isNotEmpty())->count(),
                'contracted_hourly_rate' => in_array($strategy, [BillingStrategyService::HOURLY, BillingStrategyService::HOURS_BANK, BillingStrategyService::MONTHLY_RECURRING], true) ? (float) $project->contracted_hourly_rate : null,
            ],
        ];

        if ($strategy !== BillingStrategyService::CLOSED_PROJECT || empty($ids['milestone_id']) || empty($ids['issue_date'])) {
            return $context;
        }

        $milestone = ProjectBillingMilestone::query()->forCompany($user->company_id)->where('project_id', $project->id)->find($ids['milestone_id']);
        if (! $milestone) {
            return $context;
        }

        try {
            $preview = $this->milestones->preview($milestone, $ids['issue_date']);
            $context['milestone_preview'] = [
                'source_id' => 'CONTEXT-MILESTONE-PREVIEW',
                'percentage' => (float) $milestone->percentage,
                'contractual_amount' => $preview['contractual_amount'],
                'contractual_currency' => $preview['contractual_currency'],
                'converted_amount' => $preview['conversion']['converted_amount'],
                'vat_rate' => $preview['amounts']['vat_rate'],
                'vat_amount' => $preview['amounts']['vat_amount'],
                'gross_amount' => $preview['amounts']['gross_amount'],
                'due_date' => $preview['due_date']?->toDateString(),
                'projected_collection_date' => $preview['due_date']?->toDateString(),
            ];
        } catch (DomainException) {
            // Missing or non-billable data is intentionally omitted from read-only context.
        }

        return $context;
    }
}
