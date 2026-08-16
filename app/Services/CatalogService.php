<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ApprovalStatus;
use App\Models\Bank;
use App\Models\BankAccountType;
use App\Models\CashMovementType;
use App\Models\ClientType;
use App\Models\ContractType;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\EmploymentMode;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\ExpenseType;
use App\Models\HealthSystem;
use App\Models\LegalOrganization;
use App\Models\ObligationType;
use App\Models\OccupationalInsuranceEntity;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Person;
use App\Models\Position;
use App\Models\ProjectManager;
use App\Models\ProjectType;
use App\Models\RecordStatus;
use App\Models\TaxRegime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogService
{
    public function seedDefaultsForCompany(int $companyId): void
    {
        $timestamp = now();

        $this->upsertSimple($companyId, EmploymentMode::class, [
            ['code' => 'DEPENDIENTE_MENSUAL', 'name' => 'Dependiente mensual', 'sort_order' => 10],
            ['code' => 'PAGO_POR_HORA', 'name' => 'Pago por hora', 'sort_order' => 20],
            ['code' => 'HONORARIOS_MENSUAL', 'name' => 'Honorarios mensual', 'sort_order' => 30],
            ['code' => 'POR_PROYECTO', 'name' => 'Por proyecto', 'sort_order' => 40],
        ], $timestamp);

        $this->upsertDomain($companyId, ContractType::class, [
            ['domain' => 'employment', 'code' => 'INDEFINIDO', 'name' => 'Indefinido', 'sort_order' => 10],
            ['domain' => 'employment', 'code' => 'PLAZO_FIJO', 'name' => 'Plazo fijo', 'sort_order' => 20],
            ['domain' => 'employment', 'code' => 'OBRA_O_FAENA', 'name' => 'Obra o faena', 'sort_order' => 30],
            ['domain' => 'employment', 'code' => 'PRESTACION_SERVICIOS', 'name' => 'Prestación de servicios', 'sort_order' => 40],
            ['domain' => 'employment', 'code' => 'HONORARIOS', 'name' => 'Honorarios', 'sort_order' => 50],
            ['domain' => 'employment', 'code' => 'PROYECTO', 'name' => 'Por proyecto', 'sort_order' => 60],
            ['domain' => 'commercial', 'code' => 'POR_HORA', 'name' => 'Por hora', 'sort_order' => 10],
            ['domain' => 'commercial', 'code' => 'BOLSA_HORAS', 'name' => 'Bolsa de horas', 'sort_order' => 20],
            ['domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'sort_order' => 30],
            ['domain' => 'commercial', 'code' => 'MENSUAL_RECURRENTE', 'name' => 'Mensual recurrente', 'sort_order' => 40],
        ], $timestamp);

        $this->upsertSimple($companyId, Bank::class, [
            ['code' => 'BANCO_ESTADO', 'name' => 'Banco Estado', 'sort_order' => 10],
            ['code' => 'BANCO_CHILE', 'name' => 'Banco de Chile', 'sort_order' => 20],
            ['code' => 'SANTANDER', 'name' => 'Santander', 'sort_order' => 30],
            ['code' => 'BCI', 'name' => 'BCI', 'sort_order' => 40],
            ['code' => 'ITAU', 'name' => 'Itaú', 'sort_order' => 50],
            ['code' => 'BANCO_LOCAL', 'name' => 'Banco local', 'sort_order' => 60],
        ], $timestamp);

        $this->upsertSimple($companyId, BankAccountType::class, [
            ['code' => 'CORRIENTE', 'name' => 'Corriente', 'sort_order' => 10],
            ['code' => 'VISTA', 'name' => 'Vista', 'sort_order' => 20],
            ['code' => 'AHORRO', 'name' => 'Ahorro', 'sort_order' => 30],
        ], $timestamp);

        $this->upsertSimple($companyId, Currency::class, [
            ['code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'is_base_currency' => true, 'sort_order' => 10],
            ['code' => 'UF', 'name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 2, 'is_base_currency' => false, 'sort_order' => 20],
            ['code' => 'USD', 'name' => 'Dólar estadounidense', 'symbol' => 'US$', 'minor_units' => 2, 'is_base_currency' => false, 'sort_order' => 30],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'minor_units' => 2, 'is_base_currency' => false, 'sort_order' => 40],
        ], $timestamp);

        $this->upsertSimple($companyId, PaymentMethod::class, [
            ['code' => 'TRANSFERENCIA', 'name' => 'Transferencia', 'sort_order' => 10],
            ['code' => 'TARJETA', 'name' => 'Tarjeta', 'sort_order' => 20],
            ['code' => 'EFECTIVO', 'name' => 'Efectivo', 'sort_order' => 30],
            ['code' => 'CHEQUE', 'name' => 'Cheque', 'sort_order' => 40],
            ['code' => 'PAC', 'name' => 'PAC', 'sort_order' => 50],
            ['code' => 'DEPOSITO', 'name' => 'Depósito', 'sort_order' => 60],
            ['code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 70],
        ], $timestamp);

        $transferenciaId = PaymentMethod::query()
            ->where('company_id', $companyId)
            ->where('code', 'TRANSFERENCIA')
            ->value('id');

        $this->ensurePaymentTerms($companyId, [
            ['code' => 'CONTADO', 'name' => 'Contado', 'days' => 0, 'payment_method_id' => $transferenciaId, 'sort_order' => 10],
            ['code' => 'TRANSFERENCIA', 'name' => 'Transferencia', 'days' => 0, 'payment_method_id' => $transferenciaId, 'sort_order' => 20],
            ['code' => '15_DIAS', 'name' => '15 días', 'days' => 15, 'sort_order' => 30],
            ['code' => '30_DIAS', 'name' => '30 días', 'days' => 30, 'sort_order' => 40],
            ['code' => '45_DIAS', 'name' => '45 días', 'days' => 45, 'sort_order' => 50],
            ['code' => '60_DIAS', 'name' => '60 días', 'days' => 60, 'sort_order' => 60],
        ], $timestamp);

        $this->upsertSimple($companyId, ClientType::class, [
            ['code' => 'EMPRESA_PRIVADA', 'name' => 'Empresa privada', 'sort_order' => 10],
            ['code' => 'SECTOR_PUBLICO', 'name' => 'Sector público', 'sort_order' => 20],
            ['code' => 'PERSONA_NATURAL', 'name' => 'Persona natural', 'sort_order' => 30],
        ], $timestamp);

        $this->upsertSimple($companyId, ProjectType::class, [
            ['code' => 'SERVICIO_RECURRENTE', 'name' => 'Servicio recurrente', 'sort_order' => 10],
            ['code' => 'IMPLEMENTACION', 'name' => 'Implementación', 'sort_order' => 20],
            ['code' => 'SOPORTE', 'name' => 'Soporte', 'sort_order' => 30],
            ['code' => 'CONSULTORIA', 'name' => 'Consultoría', 'sort_order' => 40],
        ], $timestamp);

        $this->upsertSimple($companyId, Activity::class, [
            ['code' => 'IMPLEMENTACION', 'name' => 'Implementación', 'sort_order' => 10],
            ['code' => 'SOPORTE', 'name' => 'Soporte', 'sort_order' => 20],
            ['code' => 'ANALISIS', 'name' => 'Análisis', 'sort_order' => 30],
            ['code' => 'ADMINISTRACION', 'name' => 'Administración', 'sort_order' => 40],
        ], $timestamp);

        $this->upsertSimple($companyId, ApprovalStatus::class, [
            ['code' => 'pending', 'name' => 'Pendiente', 'sort_order' => 10],
            ['code' => 'approved', 'name' => 'Aprobado', 'sort_order' => 20],
            ['code' => 'rejected', 'name' => 'Rechazado', 'sort_order' => 30],
        ], $timestamp);

        $this->upsertSimple($companyId, ExpenseType::class, [
            ['code' => 'FIJO', 'name' => 'Fijo', 'sort_order' => 10],
            ['code' => 'VARIABLE', 'name' => 'Variable', 'sort_order' => 20],
            ['code' => 'DIRECTO', 'name' => 'Directo', 'sort_order' => 30],
            ['code' => 'INDIRECTO', 'name' => 'Indirecto', 'sort_order' => 40],
            ['code' => 'ADMINISTRATIVO', 'name' => 'Administrativo', 'sort_order' => 50],
            ['code' => 'COMERCIAL', 'name' => 'Comercial', 'sort_order' => 60],
            ['code' => 'FINANCIERO', 'name' => 'Financiero', 'sort_order' => 70],
            ['code' => 'TRIBUTARIO', 'name' => 'Tributario', 'sort_order' => 80],
            ['code' => 'OPERACIONAL', 'name' => 'Operacional', 'sort_order' => 90],
        ], $timestamp);

        $this->upsertSimple($companyId, CashMovementType::class, [
            ['code' => 'INGRESO', 'name' => 'Ingreso', 'sort_order' => 10],
            ['code' => 'EGRESO', 'name' => 'Egreso', 'sort_order' => 20],
        ], $timestamp);

        $this->upsertDomain($companyId, DocumentType::class, [
            ['domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'sort_order' => 10],
            ['domain' => 'sales', 'code' => 'BOLETA', 'name' => 'Boleta', 'sort_order' => 20],
            ['domain' => 'sales', 'code' => 'NOTA_CREDITO', 'name' => 'Nota de crédito', 'sort_order' => 30],
            ['domain' => 'sales', 'code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 40],
            ['domain' => 'sales', 'code' => 'NOTA_DEBITO', 'name' => 'Nota de débito', 'sort_order' => 50],
            ['domain' => 'expense', 'code' => 'FACTURA_COMPRA', 'name' => 'Factura', 'sort_order' => 10],
            ['domain' => 'expense', 'code' => 'BOLETA', 'name' => 'Boleta', 'sort_order' => 20],
            ['domain' => 'expense', 'code' => 'BOLETA_HONORARIOS', 'name' => 'Boleta de honorarios', 'sort_order' => 30],
            ['domain' => 'expense', 'code' => 'COMPROBANTE', 'name' => 'Comprobante', 'sort_order' => 40],
            ['domain' => 'expense', 'code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 50],
        ], $timestamp);

        $this->upsertSimple($companyId, ObligationType::class, [
            ['code' => 'IVA', 'name' => 'IVA / F29', 'sort_order' => 10],
            ['code' => 'RETENCIONES_HONORARIOS', 'name' => 'Retención honorarios / F29', 'sort_order' => 20],
            ['code' => 'PPM', 'name' => 'PPM / F29', 'sort_order' => 30],
            ['code' => 'COTIZACIONES', 'name' => 'Cotizaciones previsionales', 'sort_order' => 40],
            ['code' => 'IMPUESTO_SEGUNDA_CATEGORIA', 'name' => 'Impuesto 2a categoría / F29', 'sort_order' => 50],
            ['code' => 'OTRAS', 'name' => 'Otros legales', 'sort_order' => 60],
        ], $timestamp);

        $this->upsertDomain($companyId, RecordStatus::class, [
            ['domain' => 'client', 'code' => 'active', 'name' => 'Activo', 'sort_order' => 10],
            ['domain' => 'client', 'code' => 'inactive', 'name' => 'Inactivo', 'sort_order' => 20],
            ['domain' => 'project', 'code' => 'active', 'name' => 'Activo', 'sort_order' => 10],
            ['domain' => 'project', 'code' => 'inactive', 'name' => 'Inactivo', 'sort_order' => 20],
            ['domain' => 'project', 'code' => 'PLANIFICADO', 'name' => 'Planificado', 'sort_order' => 30],
            ['domain' => 'project', 'code' => 'EN_EJECUCION', 'name' => 'En ejecución', 'sort_order' => 40],
            ['domain' => 'project', 'code' => 'SUSPENDIDO', 'name' => 'Suspendido', 'sort_order' => 50],
            ['domain' => 'project', 'code' => 'CERRADO', 'name' => 'Cerrado', 'sort_order' => 60],
            ['domain' => 'project', 'code' => 'CANCELADO', 'name' => 'Cancelado', 'sort_order' => 70],
            ['domain' => 'billing', 'code' => 'pending', 'name' => 'Pendiente', 'sort_order' => 10],
            ['domain' => 'billing', 'code' => 'partial', 'name' => 'Parcial', 'sort_order' => 20],
            ['domain' => 'billing', 'code' => 'paid', 'name' => 'Pagado', 'sort_order' => 30],
            ['domain' => 'worker', 'code' => 'active', 'name' => 'Activo', 'sort_order' => 10],
            ['domain' => 'worker', 'code' => 'inactive', 'name' => 'Inactivo', 'sort_order' => 20],
            ['domain' => 'assignment', 'code' => 'active', 'name' => 'Activo', 'sort_order' => 10],
            ['domain' => 'assignment', 'code' => 'inactive', 'name' => 'Inactivo', 'sort_order' => 20],
        ], $timestamp);

        $this->upsertSimple($companyId, ExpenseCategory::class, [
            ['code' => 'REMUNERACIONES', 'name' => 'Remuneraciones', 'sort_order' => 10],
            ['code' => 'HONORARIOS', 'name' => 'Honorarios', 'sort_order' => 20],
            ['code' => 'COTIZACIONES', 'name' => 'Cotizaciones', 'sort_order' => 30],
            ['code' => 'IMPUESTOS', 'name' => 'Impuestos', 'sort_order' => 40],
            ['code' => 'ARRIENDO', 'name' => 'Arriendo', 'sort_order' => 50],
            ['code' => 'SOFTWARE_Y_LICENCIAS', 'name' => 'Software y licencias', 'sort_order' => 60],
            ['code' => 'SERVICIOS_BASICOS', 'name' => 'Servicios básicos', 'sort_order' => 70],
            ['code' => 'CONTABILIDAD', 'name' => 'Contabilidad', 'sort_order' => 80],
            ['code' => 'MARKETING', 'name' => 'Marketing', 'sort_order' => 90],
            ['code' => 'TRANSPORTE', 'name' => 'Transporte', 'sort_order' => 100],
            ['code' => 'EQUIPAMIENTO', 'name' => 'Equipamiento', 'sort_order' => 110],
            ['code' => 'COMISIONES_BANCARIAS', 'name' => 'Comisiones bancarias', 'sort_order' => 120],
            ['code' => 'PROYECTO', 'name' => 'Proyecto', 'sort_order' => 130],
            ['code' => 'OTROS', 'name' => 'Otros', 'sort_order' => 140],
        ], $timestamp);

        $this->upsertSimple($companyId, HealthSystem::class, [
            ['code' => 'FONASA', 'name' => 'Fonasa', 'sort_order' => 10],
            ['code' => 'ISAPRE', 'name' => 'Isapre', 'sort_order' => 20],
            ['code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 30],
        ], $timestamp);

        $this->upsertSimple($companyId, TaxRegime::class, [
            ['code' => 'PRO_PYME_GENERAL', 'name' => 'Pro Pyme General', 'sort_order' => 10],
            ['code' => 'PRO_PYME_TRANSPARENTE', 'name' => 'Pro Pyme Transparente', 'sort_order' => 20],
            ['code' => 'REGIMEN_GENERAL', 'name' => 'Régimen General', 'sort_order' => 30],
            ['code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 40],
        ], $timestamp);

        $this->upsertSimple($companyId, LegalOrganization::class, [
            ['code' => 'SII', 'name' => 'SII', 'sort_order' => 10],
            ['code' => 'PREVIRED', 'name' => 'Previred', 'sort_order' => 20],
            ['code' => 'TGR', 'name' => 'Tesorería General de la República', 'sort_order' => 30],
            ['code' => 'AFC', 'name' => 'AFC', 'sort_order' => 40],
            ['code' => 'AFP', 'name' => 'AFP', 'sort_order' => 50],
            ['code' => 'FONASA_ISAPRE', 'name' => 'Fonasa / Isapre', 'sort_order' => 60],
            ['code' => 'MUTUALIDAD', 'name' => 'Mutualidad', 'sort_order' => 70],
            ['code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 80],
        ], $timestamp);

        $this->upsertSimple($companyId, OccupationalInsuranceEntity::class, [
            ['code' => 'ACHS', 'name' => 'ACHS', 'sort_order' => 10],
            ['code' => 'MUTUAL_SEGURIDAD', 'name' => 'Mutual de Seguridad', 'sort_order' => 20],
            ['code' => 'IST', 'name' => 'IST', 'sort_order' => 30],
            ['code' => 'ISL', 'name' => 'ISL', 'sort_order' => 40],
            ['code' => 'OTRO', 'name' => 'Otro', 'sort_order' => 50],
        ], $timestamp);
    }

    public function backfillCompany(int $companyId): array
    {
        $this->seedDefaultsForCompany($companyId);

        $report = ['mapped' => [], 'ambiguous' => []];

        $report['mapped']['project_managers'] = $this->backfillSimpleCatalog($companyId, 'projects', 'manager', ProjectManager::class, 'manager_id');
        $report['mapped']['positions'] = $this->backfillSimpleCatalog($companyId, 'people', 'role', Position::class, 'position_id');
        $report['mapped']['employment_modes'] = $this->backfillMappedCatalog($companyId, 'people', 'modality', EmploymentMode::class, 'employment_mode_id', [
            'dependiente mensual' => 'DEPENDIENTE_MENSUAL',
            'pago por hora' => 'PAGO_POR_HORA',
            'honorarios mensual' => 'HONORARIOS_MENSUAL',
            'honorarios' => 'HONORARIOS_MENSUAL',
            'por proyecto' => 'POR_PROYECTO',
        ]);
        $report['mapped']['contract_types_employment'] = $this->backfillDomainCatalog($companyId, 'people', 'contract_type', ContractType::class, 'employment_contract_type_id', 'employment');
        $report['mapped']['contract_types_commercial'] = $this->backfillDomainCatalog($companyId, 'projects', 'contract_type', ContractType::class, 'contract_type_id', 'commercial');
        $report['mapped']['health_systems'] = $this->backfillMappedCatalog($companyId, 'people', 'health_system', HealthSystem::class, 'health_system_id', [
            'fonasa' => 'FONASA',
            'isapre' => 'ISAPRE',
            'otro' => 'OTRO',
        ]);
        $report['mapped']['client_payment_terms'] = $this->backfillPaymentTermsByDays($companyId);
        $report['mapped']['project_payment_terms'] = $this->backfillMappedCatalog($companyId, 'projects', 'payment_form', PaymentTerm::class, 'payment_term_id', [
            'contado' => 'CONTADO',
            'transferencia' => 'TRANSFERENCIA',
            '15 dias' => '15_DIAS',
            '15 días' => '15_DIAS',
            '30 dias' => '30_DIAS',
            '30 días' => '30_DIAS',
            '45 dias' => '45_DIAS',
            '45 días' => '45_DIAS',
            '60 dias' => '60_DIAS',
            '60 días' => '60_DIAS',
        ]);
        $report['mapped']['cost_centers_assignments'] = $this->backfillSimpleCatalog($companyId, 'project_assignments', 'cost_center', CostCenter::class, 'cost_center_id');
        $report['mapped']['cost_centers_time_entries'] = $this->backfillSimpleCatalog($companyId, 'time_entries', 'cost_center', CostCenter::class, 'cost_center_id');
        $report['mapped']['activities'] = $this->backfillSimpleCatalog($companyId, 'time_entries', 'activity', Activity::class, 'activity_id');
        $report['mapped']['approval_statuses'] = $this->backfillMappedCatalog($companyId, 'time_entries', 'approval_status', ApprovalStatus::class, 'approval_status_id', [
            'pending' => 'pending',
            'approved' => 'approved',
            'rejected' => 'rejected',
        ]);
        $report['mapped']['banks'] = $this->backfillSimpleCatalog($companyId, 'cash_accounts', 'institution', Bank::class, 'bank_id');
        $report['mapped']['bank_account_types'] = $this->backfillMappedCatalog($companyId, 'cash_accounts', 'account_type', BankAccountType::class, 'bank_account_type_id', [
            'corriente' => 'CORRIENTE',
            'vista' => 'VISTA',
            'ahorro' => 'AHORRO',
        ]);
        $report['mapped']['currencies'] = $this->backfillMappedCatalog($companyId, 'cash_accounts', 'currency', Currency::class, 'currency_id', [
            'clp' => 'CLP',
            'uf' => 'UF',
            'usd' => 'USD',
            'eur' => 'EUR',
        ]);
        $report['mapped']['payment_methods'] = $this->backfillMappedCatalog($companyId, 'cash_movements', 'payment_method', PaymentMethod::class, 'payment_method_id', [
            'transferencia' => 'TRANSFERENCIA',
            'efectivo' => 'EFECTIVO',
            'cheque' => 'CHEQUE',
            'tarjeta' => 'TARJETA',
            'deposito' => 'DEPOSITO',
            'depósito' => 'DEPOSITO',
        ]);
        $report['mapped']['cash_movement_types'] = $this->backfillMappedCatalog($companyId, 'cash_movements', 'movement_type', CashMovementType::class, 'movement_type_id', [
            'ingreso' => 'INGRESO',
            'egreso' => 'EGRESO',
        ]);
        $report['mapped']['expense_categories'] = $this->backfillSimpleCatalog($companyId, 'expense_documents', 'category', ExpenseCategory::class, 'expense_category_id');
        $report['mapped']['expense_subcategories'] = $this->backfillExpenseSubcategories($companyId);
        $report['mapped']['expense_types'] = $this->backfillSimpleCatalog($companyId, 'expense_documents', 'expense_type', ExpenseType::class, 'expense_type_id');
        $report['mapped']['document_types_sales'] = $this->backfillMappedDomainCatalog($companyId, 'sales_documents', 'document_type', DocumentType::class, 'document_type_id', 'sales', [
            'factura' => 'FACTURA',
            'boleta' => 'BOLETA',
            'nota de credito' => 'NOTA_CREDITO',
            'nota de crédito' => 'NOTA_CREDITO',
            'nota de debito' => 'NOTA_DEBITO',
            'nota de débito' => 'NOTA_DEBITO',
        ]);
        $report['mapped']['document_types_expense'] = $this->backfillMappedDomainCatalog($companyId, 'expense_documents', 'document_type', DocumentType::class, 'document_type_id', 'expense', [
            'factura' => 'FACTURA_COMPRA',
            'factura compra' => 'FACTURA_COMPRA',
            'boleta' => 'BOLETA',
            'boleta honorarios' => 'BOLETA_HONORARIOS',
            'boleta de honorarios' => 'BOLETA_HONORARIOS',
            'otro' => 'OTRO',
        ]);
        $report['mapped']['obligation_types'] = $this->backfillMappedCatalog($companyId, 'legal_obligations', 'obligation_type', ObligationType::class, 'obligation_type_id', [
            'iva' => 'IVA',
            'iva / f29' => 'IVA',
            'iva_/_f29' => 'IVA',
            'retencion honorarios' => 'RETENCIONES_HONORARIOS',
            'retención honorarios' => 'RETENCIONES_HONORARIOS',
            'retencion honorarios / f29' => 'RETENCIONES_HONORARIOS',
            'retención honorarios / f29' => 'RETENCIONES_HONORARIOS',
            'retenciones honorarios' => 'RETENCIONES_HONORARIOS',
            'retenciones_honorarios' => 'RETENCIONES_HONORARIOS',
            'retención_honorarios / f29' => 'RETENCIONES_HONORARIOS',
            'retencion_honorarios / f29' => 'RETENCIONES_HONORARIOS',
            'retención_honorarios_/_f29' => 'RETENCIONES_HONORARIOS',
            'ppm' => 'PPM',
            'cotizaciones' => 'COTIZACIONES',
            'impuesto segunda categoria' => 'IMPUESTO_SEGUNDA_CATEGORIA',
            'impuesto segunda categoría' => 'IMPUESTO_SEGUNDA_CATEGORIA',
            'otras' => 'OTRAS',
        ]);
        $report['mapped']['client_statuses'] = $this->backfillMappedDomainCatalog($companyId, 'clients', 'status', RecordStatus::class, 'client_status_id', 'client', [
            'active' => 'active',
            'inactive' => 'inactive',
        ]);
        $report['mapped']['project_statuses'] = $this->backfillMappedDomainCatalog($companyId, 'projects', 'project_status', RecordStatus::class, 'project_status_id', 'project', [
            'active' => 'active',
            'inactive' => 'inactive',
        ]);
        $report['mapped']['billing_statuses'] = $this->backfillMappedDomainCatalog($companyId, 'projects', 'billing_status', RecordStatus::class, 'billing_status_id', 'billing', [
            'pending' => 'pending',
            'parcial' => 'partial',
            'partial' => 'partial',
            'pagado' => 'paid',
            'paid' => 'paid',
        ]);
        $report['mapped']['worker_statuses'] = $this->backfillMappedDomainCatalog($companyId, 'people', 'status', RecordStatus::class, 'worker_status_id', 'worker', [
            'active' => 'active',
            'inactive' => 'inactive',
        ]);
        $report['mapped']['assignment_statuses'] = $this->backfillMappedDomainCatalog($companyId, 'project_assignments', 'status', RecordStatus::class, 'assignment_status_id', 'assignment', [
            'active' => 'active',
            'inactive' => 'inactive',
        ]);
        $report['mapped']['people_identity'] = $this->backfillPeopleIdentityFields($companyId);

        return $report;
    }

    public function syncLegacyFields(string $resource, array $data): array
    {
        return match ($resource) {
            'clients' => $this->syncClientFields($data),
            'projects' => $this->syncProjectFields($data),
            'people' => $this->syncPeopleFields($data),
            'assignments' => $this->syncAssignmentFields($data),
            'time-entries' => $this->syncTimeEntryFields($data),
            'sales-documents' => $this->syncDocumentField($data, 'document_type_id', 'document_type'),
            'expense-documents' => $this->syncExpenseFields($data),
            'cash-accounts' => $this->syncCashAccountFields($data),
            'cash-movements' => $this->syncCashMovementFields($data),
            'legal-obligations' => $this->syncCatalogCode($data, 'obligation_type_id', ObligationType::class, 'obligation_type'),
            default => $data,
        };
    }

    private function syncClientFields(array $data): array
    {
        $data = $this->syncRecordStatus($data, 'client_status_id', 'client', 'status');

        if (! empty($data['payment_term_id'])) {
            $term = PaymentTerm::query()->find($data['payment_term_id']);
            if ($term) {
                $data['payment_term_days'] = $term->days ?? 0;
            }
        }

        return $data;
    }

    private function syncProjectFields(array $data): array
    {
        $data = $this->syncCatalogName($data, 'manager_id', ProjectManager::class, 'manager');
        $data = $this->syncContractType($data, 'contract_type_id', 'commercial', 'contract_type');
        $data = $this->syncRecordStatus($data, 'project_status_id', 'project', 'project_status');
        $data = $this->syncRecordStatus($data, 'billing_status_id', 'billing', 'billing_status');

        if (! empty($data['payment_term_id'])) {
            $data = $this->syncCatalogName($data, 'payment_term_id', PaymentTerm::class, 'payment_form');
        }

        return $data;
    }

    private function syncPeopleFields(array $data): array
    {
        if (empty($data['name']) && (filled($data['first_names'] ?? null) || filled($data['paternal_surname'] ?? null) || filled($data['maternal_surname'] ?? null))) {
            $data['name'] = trim(collect([
                $data['first_names'] ?? null,
                $data['paternal_surname'] ?? null,
                $data['maternal_surname'] ?? null,
            ])->filter()->implode(' '));
        }

        $data = $this->syncCatalogName($data, 'position_id', Position::class, 'role');
        $data = $this->syncCatalogName($data, 'employment_mode_id', EmploymentMode::class, 'modality');
        $data = $this->syncContractType($data, 'employment_contract_type_id', 'employment', 'contract_type');
        $data = $this->syncCatalogName($data, 'health_system_id', HealthSystem::class, 'health_system');

        return $this->syncRecordStatus($data, 'worker_status_id', 'worker', 'status');
    }

    private function backfillPeopleIdentityFields(int $companyId): int
    {
        return Person::query()
            ->forCompany($companyId)
            ->whereNull('first_names')
            ->whereNotNull('name')
            ->update([
                'first_names' => DB::raw('name'),
                'paternal_surname' => DB::raw('NULL'),
                'maternal_surname' => DB::raw('NULL'),
            ]);
    }

    private function syncAssignmentFields(array $data): array
    {
        $data = $this->syncCatalogName($data, 'cost_center_id', CostCenter::class, 'cost_center');

        return $this->syncRecordStatus($data, 'assignment_status_id', 'assignment', 'status');
    }

    private function syncTimeEntryFields(array $data): array
    {
        $data = $this->syncCatalogName($data, 'cost_center_id', CostCenter::class, 'cost_center');
        $data = $this->syncCatalogName($data, 'activity_id', Activity::class, 'activity');

        if (! empty($data['approval_status_id'])) {
            $data = $this->syncCatalogCode($data, 'approval_status_id', ApprovalStatus::class, 'approval_status');
        }

        return $data;
    }

    private function syncExpenseFields(array $data): array
    {
        $data = $this->syncCatalogName($data, 'expense_category_id', ExpenseCategory::class, 'category');
        $data = $this->syncCatalogName($data, 'expense_subcategory_id', ExpenseSubcategory::class, 'subcategory');
        $data = $this->syncDocumentField($data, 'document_type_id', 'document_type');

        if (! empty($data['expense_type_id'])) {
            $data = $this->syncCatalogName($data, 'expense_type_id', ExpenseType::class, 'expense_type');
        }

        return $data;
    }

    private function syncCashAccountFields(array $data): array
    {
        $data = $this->syncCatalogName($data, 'bank_id', Bank::class, 'institution');
        $data = $this->syncCatalogName($data, 'bank_account_type_id', BankAccountType::class, 'account_type');

        if (! empty($data['currency_id'])) {
            $data = $this->syncCatalogCode($data, 'currency_id', Currency::class, 'currency');
        }

        return $data;
    }

    private function syncCashMovementFields(array $data): array
    {
        $data = $this->syncCatalogName($data, 'payment_method_id', PaymentMethod::class, 'payment_method');

        if (! empty($data['movement_type_id'])) {
            $data = $this->syncCatalogName($data, 'movement_type_id', CashMovementType::class, 'movement_type');
        }

        return $data;
    }

    private function syncDocumentField(array $data, string $field, string $legacyField): array
    {
        return $this->syncCatalogName($data, $field, DocumentType::class, $legacyField);
    }

    private function syncContractType(array $data, string $field, string $domain, string $legacyField): array
    {
        if (! empty($data[$field])) {
            $type = ContractType::query()->whereKey($data[$field])->where('domain', $domain)->first();
            if ($type) {
                $data[$legacyField] = $type->name;
            }
        }

        return $data;
    }

    private function syncRecordStatus(array $data, string $field, string $domain, string $legacyField): array
    {
        if (! empty($data[$field])) {
            $status = RecordStatus::query()->whereKey($data[$field])->where('domain', $domain)->first();
            if ($status) {
                $data[$legacyField] = $status->code;
            }
        }

        return $data;
    }

    private function syncCatalogName(array $data, string $field, string $modelClass, string $legacyField): array
    {
        if (! empty($data[$field])) {
            $model = $modelClass::query()->find($data[$field]);
            if ($model) {
                $data[$legacyField] = $model->name;
            }
        }

        return $data;
    }

    private function syncCatalogCode(array $data, string $field, string $modelClass, string $legacyField): array
    {
        if (! empty($data[$field])) {
            $model = $modelClass::query()->find($data[$field]);
            if ($model) {
                $data[$legacyField] = $model->code;
            }
        }

        return $data;
    }

    private function backfillSimpleCatalog(int $companyId, string $table, string $legacyColumn, string $modelClass, string $foreignKey): int
    {
        $count = 0;

        $this->distinctValues($companyId, $table, $legacyColumn)->each(function (string $value, int $index) use ($companyId, $modelClass) {
            $modelClass::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $this->buildCode($value)],
                ['name' => $value, 'active' => true, 'sort_order' => ($index + 1) * 10]
            );
        });

        $catalog = $modelClass::query()->where('company_id', $companyId)->get()->keyBy(fn ($item) => $this->normalize($item->name));

        DB::table($table)->where('company_id', $companyId)->orderBy('id')->get()->each(function (object $row) use ($table, $legacyColumn, $foreignKey, $catalog, &$count) {
            $normalized = $this->normalize($row->{$legacyColumn});
            if ($normalized === null || ! isset($catalog[$normalized])) {
                return;
            }

            DB::table($table)->where('id', $row->id)->update([$foreignKey => $catalog[$normalized]->id]);
            $count++;
        });

        return $count;
    }

    private function backfillDomainCatalog(int $companyId, string $table, string $legacyColumn, string $modelClass, string $foreignKey, string $domain): int
    {
        $count = 0;

        $this->distinctValues($companyId, $table, $legacyColumn)->each(function (string $value, int $index) use ($companyId, $modelClass, $domain) {
            $modelClass::query()->firstOrCreate(
                ['company_id' => $companyId, 'domain' => $domain, 'code' => $this->buildCode($value)],
                ['name' => $value, 'active' => true, 'sort_order' => ($index + 1) * 10]
            );
        });

        $catalog = $modelClass::query()
            ->where('company_id', $companyId)
            ->where('domain', $domain)
            ->get()
            ->keyBy(fn ($item) => $this->normalize($item->name));

        DB::table($table)->where('company_id', $companyId)->orderBy('id')->get()->each(function (object $row) use ($table, $legacyColumn, $foreignKey, $catalog, &$count) {
            $normalized = $this->normalize($row->{$legacyColumn});
            if ($normalized === null || ! isset($catalog[$normalized])) {
                return;
            }

            DB::table($table)->where('id', $row->id)->update([$foreignKey => $catalog[$normalized]->id]);
            $count++;
        });

        return $count;
    }

    private function backfillMappedCatalog(int $companyId, string $table, string $legacyColumn, string $modelClass, string $foreignKey, array $map): int
    {
        $catalog = $modelClass::query()->where('company_id', $companyId)->get()->keyBy('code');
        $count = 0;

        DB::table($table)->where('company_id', $companyId)->orderBy('id')->get()->each(function (object $row) use ($table, $legacyColumn, $foreignKey, $map, $catalog, &$count) {
            $normalized = $this->normalize($row->{$legacyColumn});
            if ($normalized === null) {
                return;
            }

            $code = $map[$normalized] ?? null;
            if ($code === null || ! isset($catalog[$code])) {
                return;
            }

            DB::table($table)->where('id', $row->id)->update([$foreignKey => $catalog[$code]->id]);
            $count++;
        });

        return $count;
    }

    private function backfillMappedDomainCatalog(int $companyId, string $table, string $legacyColumn, string $modelClass, string $foreignKey, string $domain, array $map): int
    {
        $catalog = $modelClass::query()->where('company_id', $companyId)->where('domain', $domain)->get()->keyBy('code');
        $count = 0;

        DB::table($table)->where('company_id', $companyId)->orderBy('id')->get()->each(function (object $row) use ($table, $legacyColumn, $foreignKey, $map, $catalog, &$count) {
            $normalized = $this->normalize($row->{$legacyColumn});
            if ($normalized === null) {
                return;
            }

            $code = $map[$normalized] ?? null;
            if ($code === null || ! isset($catalog[$code])) {
                return;
            }

            DB::table($table)->where('id', $row->id)->update([$foreignKey => $catalog[$code]->id]);
            $count++;
        });

        return $count;
    }

    private function backfillPaymentTermsByDays(int $companyId): int
    {
        $terms = PaymentTerm::query()->where('company_id', $companyId)->whereNotNull('days')->get()->keyBy('days');
        $count = 0;

        DB::table('clients')->where('company_id', $companyId)->orderBy('id')->get()->each(function (object $row) use ($terms, &$count) {
            if ($row->payment_term_days === null || ! isset($terms[$row->payment_term_days])) {
                return;
            }

            DB::table('clients')->where('id', $row->id)->update(['payment_term_id' => $terms[$row->payment_term_days]->id]);
            $count++;
        });

        return $count;
    }

    private function backfillExpenseSubcategories(int $companyId): int
    {
        $genericCategory = ExpenseCategory::query()->firstOrCreate(
            ['company_id' => $companyId, 'code' => 'SIN_CATEGORIA'],
            ['name' => 'Sin categoría', 'active' => true, 'sort_order' => 999]
        );

        $count = 0;

        DB::table('expense_documents')->where('company_id', $companyId)->whereNotNull('subcategory')->orderBy('id')->get()->each(function (object $row) use ($companyId, $genericCategory, &$count) {
            $subcategory = $this->canonical($row->subcategory);
            if ($subcategory === null) {
                return;
            }

            $category = ! empty($row->expense_category_id)
                ? ExpenseCategory::query()->find($row->expense_category_id)
                : null;

            $category ??= $genericCategory;

            $expenseSubcategory = ExpenseSubcategory::query()->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'expense_category_id' => $category->id,
                    'code' => $this->buildCode($subcategory),
                ],
                [
                    'name' => $subcategory,
                    'active' => true,
                    'sort_order' => 10,
                ]
            );

            DB::table('expense_documents')->where('id', $row->id)->update(['expense_subcategory_id' => $expenseSubcategory->id]);
            $count++;
        });

        return $count;
    }

    private function distinctValues(int $companyId, string $table, string $column): Collection
    {
        return DB::table($table)
            ->where('company_id', $companyId)
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn ($value) => $this->canonical($value))
            ->filter()
            ->unique(fn ($value) => $this->normalize($value))
            ->values();
    }

    private function upsertSimple(int $companyId, string $modelClass, array $rows, $timestamp): void
    {
        collect($rows)->each(function (array $row) use ($companyId, $modelClass, $timestamp) {
            $modelClass::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'active' => $row['active'] ?? true,
                    'sort_order' => $row['sort_order'] ?? null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        });
    }

    private function upsertDomain(int $companyId, string $modelClass, array $rows, $timestamp): void
    {
        collect($rows)->each(function (array $row) use ($companyId, $modelClass, $timestamp) {
            $modelClass::query()->firstOrCreate(
                ['company_id' => $companyId, 'domain' => $row['domain'], 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'active' => $row['active'] ?? true,
                    'sort_order' => $row['sort_order'] ?? null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        });
    }

    private function ensurePaymentTerms(int $companyId, array $rows, $timestamp): void
    {
        collect($rows)->each(function (array $row) use ($companyId, $timestamp) {
            PaymentTerm::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'days' => $row['days'] ?? null,
                    'payment_method_id' => $row['payment_method_id'] ?? null,
                    'description' => $row['description'] ?? null,
                    'active' => $row['active'] ?? true,
                    'sort_order' => $row['sort_order'] ?? null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        });
    }

    private function canonical(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value));

        return $clean === '' ? null : $clean;
    }

    private function normalize(?string $value): ?string
    {
        $clean = $this->canonical($value);

        return $clean === null ? null : mb_strtolower($clean);
    }

    private function buildCode(string $value): string
    {
        $slug = Str::upper(Str::slug($value, '_'));

        return $slug !== '' ? $slug : 'ITEM_'.Str::upper(Str::random(8));
    }
}
