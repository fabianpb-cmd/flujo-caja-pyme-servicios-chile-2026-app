<?php

namespace App\Services;

use App\Models\ContractType;
use App\Models\Project;
use DomainException;

class BillingStrategyService
{
    public const CLOSED_PROJECT = 'CLOSED_PROJECT';
    public const HOURLY = 'HOURLY';
    public const UNSUPPORTED = 'UNSUPPORTED';

    public function __construct(private readonly ProjectBillingMilestoneService $milestones) {}

    public function forProject(Project $project): string
    {
        $contract = $project->relationLoaded('contractType') ? $project->contractType : $project->contractType()->first();
        if (! $contract instanceof ContractType || $contract->domain !== 'commercial') {
            return self::UNSUPPORTED;
        }

        $code = strtoupper(trim((string) $contract->code));
        $name = $this->normalize((string) $contract->name);
        if ($code === 'PROYECTO_CERRADO' || $name === 'PROYECTO CERRADO') {
            return self::CLOSED_PROJECT;
        }
        if (in_array($code, ['POR_HORA', 'POR_HORAS', 'HOURLY'], true) || in_array($name, ['POR HORA', 'POR HORAS'], true)) {
            return self::HOURLY;
        }

        return self::UNSUPPORTED;
    }

    public function assertSupported(Project $project): string
    {
        $strategy = $this->forProject($project);
        if ($strategy === self::UNSUPPORTED) {
            throw new DomainException('El tipo de contrato no tiene una estrategia de facturación configurada.');
        }

        return $strategy;
    }

    public function validateProject(Project $project, array $milestones = []): string
    {
        $strategy = $this->assertSupported($project);
        if ($strategy === self::HOURLY) {
            if (! $project->sales_currency_id || (float) $project->contracted_hourly_rate <= 0) {
                throw new DomainException('Los proyectos Por Hora requieren moneda comercial y una Tarifa comercial HH mayor que cero.');
            }
            if ($milestones !== []) {
                throw new DomainException('Los proyectos Por Hora no utilizan hitos de facturación.');
            }
            return $strategy;
        }

        if (! $project->sales_currency_id || (float) $project->sale_net <= 0 || $milestones === []) {
            throw new DomainException('Los proyectos Proyecto cerrado requieren venta neta, moneda comercial y al menos un hito de facturación.');
        }
        $total = 0.0;
        $sequences = [];
        $previousPlannedDate = null;
        foreach ($milestones as $milestone) {
            $sequence = (int) ($milestone['sequence'] ?? 0);
            $percentage = (float) ($milestone['percentage'] ?? 0);
            if ($sequence <= 0 || in_array($sequence, $sequences, true) || trim((string) ($milestone['name'] ?? '')) === '' || $percentage <= 0) {
                throw new DomainException('Cada hito requiere orden único, nombre y porcentaje mayor que cero.');
            }
            $plannedDate = $milestone['planned_invoice_date'] ?? null;
            if (Project::isVigentStatusCode($project->projectStatus?->code) && blank($plannedDate)) {
                throw new DomainException('Cada hito de un proyecto vigente requiere fecha prevista de facturación.');
            }
            if ($plannedDate && $previousPlannedDate && $plannedDate < $previousPlannedDate) {
                throw new DomainException('Las fechas previstas deben respetar el orden cronológico de los hitos.');
            }
            $previousPlannedDate = $plannedDate ?: $previousPlannedDate;
            $sequences[] = $sequence;
            $total += $percentage;
        }
        if ($total > 100.00001) {
            throw new DomainException('La suma de porcentajes del plan no puede superar 100%.');
        }
        if (Project::isVigentStatusCode($project->projectStatus?->code) && abs($total - 100) > 0.00001) {
            throw new DomainException('El plan de facturación de un proyecto vigente debe sumar exactamente 100%.');
        }
        return $strategy;
    }

    public function syncMilestones(Project $project, array $rows): void
    {
        $this->validateProject($project->loadMissing('projectStatus'), $rows);
        $this->milestones->syncPlan($project, $rows);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        return strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }
}
