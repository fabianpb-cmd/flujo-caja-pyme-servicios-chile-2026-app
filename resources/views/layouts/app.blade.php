<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Flujo de Caja') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app-dashboard.css') }}" rel="stylesheet">
    @stack('styles')
</head>
@php
    $routeExists = fn (string $name): bool => \Illuminate\Support\Facades\Route::has($name);
    $sidebarItem = function (string $label, string $icon, string $route, array $params = [], array $options = []) {
        return array_merge([
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'params' => $params,
            'route_patterns' => [$route],
            'resource' => null,
            'operational_fallback' => false,
        ], $options);
    };
    $currentResource = request()->route('resource');
    $currentRouteName = request()->route()?->getName();
    $resourceConfig = $currentResource ? config("operational.$currentResource") : null;
    $resourceTitle = $resourceConfig['title'] ?? null;
    $navigation = [
        [
            'title' => 'Dashboard',
            'items' => [
                $sidebarItem('Dashboard', 'bi bi-speedometer2', 'dashboard'),
            ],
        ],
        [
            'title' => 'Operación',
            'items' => [
                $sidebarItem('Clientes', 'bi bi-people', 'operational.index', ['clients'], ['resource' => 'clients', 'operational_fallback' => true]),
                $sidebarItem('Proyectos', 'bi bi-folder2-open', 'operational.index', ['projects'], ['resource' => 'projects', 'operational_fallback' => true]),
                $sidebarItem('Personal', 'bi bi-person-badge', 'operational.index', ['people'], ['resource' => 'people', 'operational_fallback' => true]),
                $sidebarItem('Asignaciones', 'bi bi-diagram-3', 'operational.index', ['assignments'], ['resource' => 'assignments', 'operational_fallback' => true]),
                $sidebarItem('Horas', 'bi bi-clock-history', 'operational.index', ['time-entries'], ['resource' => 'time-entries', 'operational_fallback' => true]),
                $sidebarItem('Remuneraciones', 'bi bi-cash-stack', 'operational.index', ['payroll-records'], ['resource' => 'payroll-records', 'operational_fallback' => true]),
            ],
        ],
        [
            'title' => 'Ventas',
            'items' => [
                $sidebarItem('Facturas / Ingresos', 'bi bi-receipt', 'sales-documents.index', [], [
                    'resource' => 'sales-documents',
                    'operational_fallback' => true,
                ]),
                $sidebarItem('Cuentas por cobrar', 'bi bi-currency-dollar', 'receivables.index'),
            ],
        ],
        [
            'title' => 'Gastos',
            'items' => [
                $sidebarItem('Egresos / Gastos', 'bi bi-cart3', 'expense-documents.index', [], [
                    'resource' => 'expense-documents',
                    'operational_fallback' => true,
                ]),
                $sidebarItem('Cuentas por pagar', 'bi bi-wallet2', 'payables.index'),
            ],
        ],
        [
            'title' => 'Tesorería',
            'items' => [
                $sidebarItem('Cuentas', 'bi bi-bank', 'operational.index', ['cash-accounts'], ['resource' => 'cash-accounts', 'operational_fallback' => true]),
                $sidebarItem('Movimientos de caja', 'bi bi-arrow-left-right', 'operational.index', ['cash-movements'], ['resource' => 'cash-movements', 'operational_fallback' => true]),
            ],
        ],
        [
            'title' => 'Gestión',
            'items' => [
                $sidebarItem('Obligaciones', 'bi bi-clipboard2-check', 'management.obligations'),
                $sidebarItem('Presupuesto', 'bi bi-calculator', 'management.budgets'),
                $sidebarItem('Flujo de caja', 'bi bi-graph-up-arrow', 'management.flows'),
                $sidebarItem('Rentabilidad', 'bi bi-bar-chart-line', 'management.profitability'),
                $sidebarItem('Escenarios', 'bi bi-sliders2', 'operational.index', ['scenarios'], ['resource' => 'scenarios', 'operational_fallback' => true]),
            ],
        ],
    ];
    if (auth()->check() && auth()->user()->role === 'admin') {
        $navigation[] = [
            'title' => 'Administración',
            'groups' => [
                [
                    'title' => 'Configuración',
                    'items' => [
                        $sidebarItem('Escenarios', 'bi bi-sliders2', 'operational.index', ['scenarios'], ['resource' => 'scenarios', 'operational_fallback' => true]),
                    ],
                ],
                [
                    'title' => 'Organización',
                    'items' => [
                        $sidebarItem('Responsables', 'bi bi-person-lines-fill', 'operational.index', ['project-managers'], ['resource' => 'project-managers', 'operational_fallback' => true]),
                        $sidebarItem('Centros de costo', 'bi bi-diagram-2', 'operational.index', ['cost-centers'], ['resource' => 'cost-centers', 'operational_fallback' => true]),
                    ],
                ],
                [
                    'title' => 'Personas',
                    'items' => [
                        $sidebarItem('Cargos', 'bi bi-briefcase', 'operational.index', ['positions'], ['resource' => 'positions', 'operational_fallback' => true]),
                        $sidebarItem('Modalidades', 'bi bi-person-workspace', 'operational.index', ['employment-modes'], ['resource' => 'employment-modes', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de contrato', 'bi bi-file-earmark-text', 'operational.index', ['contract-types'], ['resource' => 'contract-types', 'operational_fallback' => true]),
                        $sidebarItem('AFP', 'bi bi-shield-check', 'operational.index', ['afps'], ['resource' => 'afps', 'operational_fallback' => true]),
                        $sidebarItem('Sistemas de salud', 'bi bi-heart-pulse', 'operational.index', ['health-systems'], ['resource' => 'health-systems', 'operational_fallback' => true]),
                    ],
                ],
                [
                    'title' => 'Comercial',
                    'items' => [
                        $sidebarItem('Tipos de cliente', 'bi bi-person-vcard', 'operational.index', ['client-types'], ['resource' => 'client-types', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de proyecto', 'bi bi-kanban', 'operational.index', ['project-types'], ['resource' => 'project-types', 'operational_fallback' => true]),
                        $sidebarItem('Estados de proyecto', 'bi bi-ui-checks-grid', 'operational.index', ['record-statuses'], ['resource' => 'record-statuses', 'operational_fallback' => true]),
                        $sidebarItem('Condiciones de pago', 'bi bi-cash-coin', 'operational.index', ['payment-terms'], ['resource' => 'payment-terms', 'operational_fallback' => true]),
                        $sidebarItem('Monedas', 'bi bi-currency-exchange', 'operational.index', ['currencies'], ['resource' => 'currencies', 'operational_fallback' => true]),
                    ],
                ],
                [
                    'title' => 'Finanzas',
                    'items' => [
                        $sidebarItem('Actividades', 'bi bi-list-task', 'operational.index', ['activities'], ['resource' => 'activities', 'operational_fallback' => true]),
                        $sidebarItem('Aprobación horas', 'bi bi-check2-circle', 'operational.index', ['approval-statuses'], ['resource' => 'approval-statuses', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de gasto', 'bi bi-bag', 'operational.index', ['expense-types'], ['resource' => 'expense-types', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de movimiento', 'bi bi-arrow-down-up', 'operational.index', ['cash-movement-types'], ['resource' => 'cash-movement-types', 'operational_fallback' => true]),
                        $sidebarItem('Bancos', 'bi bi-bank', 'operational.index', ['banks'], ['resource' => 'banks', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de cuenta bancaria', 'bi bi-credit-card', 'operational.index', ['bank-account-types'], ['resource' => 'bank-account-types', 'operational_fallback' => true]),
                        $sidebarItem('Medios de pago', 'bi bi-credit-card-2-front', 'operational.index', ['payment-methods'], ['resource' => 'payment-methods', 'operational_fallback' => true]),
                        $sidebarItem('Categorías', 'bi bi-tags', 'operational.index', ['expense-categories'], ['resource' => 'expense-categories', 'operational_fallback' => true]),
                        $sidebarItem('Subcategorías', 'bi bi-tag', 'operational.index', ['expense-subcategories'], ['resource' => 'expense-subcategories', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de documento', 'bi bi-receipt-cutoff', 'operational.index', ['document-types'], ['resource' => 'document-types', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de obligación', 'bi bi-clipboard2-data', 'operational.index', ['obligation-types'], ['resource' => 'obligation-types', 'operational_fallback' => true]),
                        $sidebarItem('Organismos', 'bi bi-buildings', 'operational.index', ['legal-organizations'], ['resource' => 'legal-organizations', 'operational_fallback' => true]),
                        $sidebarItem('Mutualidades', 'bi bi-life-preserver', 'operational.index', ['occupational-insurance-entities'], ['resource' => 'occupational-insurance-entities', 'operational_fallback' => true]),
                    ],
                ],
            ],
        ];
    }
    $navigation = collect($navigation)->map(function (array $section) use ($routeExists) {
        if (! empty($section['groups'])) {
            $section['groups'] = collect($section['groups'])
                ->map(function (array $group) use ($routeExists) {
                    $group['items'] = collect($group['items'])
                        ->filter(fn (array $item): bool => $routeExists($item['route']))
                        ->values()
                        ->all();

                    return $group;
                })
                ->filter(fn (array $group): bool => ! empty($group['items']))
                ->values()
                ->all();
        }

        if (! empty($section['items'])) {
            $section['items'] = collect($section['items'])
                ->filter(fn (array $item): bool => $routeExists($item['route']))
                ->values()
                ->all();
        }

        return $section;
    })->filter(function (array $section): bool {
        return ! empty($section['items']) || ! empty($section['groups']);
    })->values()->all();
    $breadcrumb = null;
    if ($currentRouteName === 'dashboard') {
        $breadcrumb = null;
    } elseif (str_starts_with((string) $currentRouteName, 'operational.') && $resourceTitle) {
        $action = match ($currentRouteName) {
            'operational.create' => 'Nuevo',
            'operational.edit' => 'Editar',
            'operational.show' => 'Detalle',
            default => null,
        };
        $section = $resourceConfig['section'] ?? 'Operación';
        $group = $resourceConfig['breadcrumb_group'] ?? null;
        $breadcrumb = collect([$section, $group, $resourceTitle, $action])->filter()->values()->all();
    } else {
        $managementMap = [
            'management.obligations' => ['Gestión', 'Obligaciones'],
            'management.budgets' => ['Gestión', 'Presupuesto'],
            'management.flows' => ['Gestión', 'Flujo de caja'],
            'management.profitability' => ['Gestión', 'Rentabilidad'],
        ];
        $breadcrumb = $managementMap[$currentRouteName] ?? null;
    }
    $errorMessages = [];
    if (isset($errors)) {
        if (is_object($errors) && method_exists($errors, 'all')) {
            $errorMessages = $errors->all();
        } elseif (is_array($errors)) {
            $errorMessages = array_values($errors);
        }
    }
@endphp
<body>
<div class="app-shell">
    @auth
        <div class="app-layout">
            <aside class="app-sidebar d-none d-md-flex">
                <x-sidebar instance="desktop" :navigation="$navigation" :current-resource="$currentResource" :current-route="$currentRouteName" />
            </aside>

            <div class="offcanvas offcanvas-start app-sidebar-offcanvas" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
                <div class="offcanvas-header">
                    <h5 class="offcanvas-title" id="mobileSidebarLabel">Flujo de Caja Pyme</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <div class="offcanvas-body p-0">
                    <x-sidebar instance="mobile" :navigation="$navigation" :current-resource="$currentResource" :current-route="$currentRouteName" />
                </div>
            </div>

            <div class="app-main">
                <x-topbar :user="auth()->user()" />

                <main class="app-content">
                    @if ($breadcrumb)
                        <div class="page-breadcrumb">
                            {{ implode(' / ', $breadcrumb) }}
                        </div>
                    @endif

                    @if (session('status'))
                        <div class="alert alert-success app-panel">{{ session('status') }}</div>
                    @endif

                    @if (! empty($errorMessages))
                        <div class="alert alert-danger app-panel">
                            <ul class="mb-0">
                                @foreach ($errorMessages as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </main>
            </div>
        </div>
    @else
        <main class="container py-4">
            @yield('content')
        </main>
    @endauth
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        const collapseKey = 'sidebarCollapsed';
        const scrollKey = 'sidebarScrollTop';
        const body = document.body;
        const toggle = document.getElementById('sidebarCollapseToggle');
        const desktopBreakpoint = window.matchMedia('(min-width: 768px)');
        const wideBreakpoint = window.matchMedia('(min-width: 1200px)');
        const sidebarNavs = Array.from(document.querySelectorAll('[data-sidebar-scroll]'));
        const mobileOffcanvasElement = document.getElementById('mobileSidebar');
        const mobileOffcanvas = mobileOffcanvasElement ? bootstrap.Offcanvas.getOrCreateInstance(mobileOffcanvasElement) : null;

        const getStoredCollapsed = () => window.localStorage.getItem(collapseKey);
        const setCollapsed = (collapsed) => {
            if (!desktopBreakpoint.matches) {
                body.classList.remove('sidebar-collapsed');
                return;
            }

            body.classList.toggle('sidebar-collapsed', collapsed);
        };

        const syncSidebarTitles = () => {
            const collapsed = body.classList.contains('sidebar-collapsed') && desktopBreakpoint.matches;
            document.querySelectorAll('[data-sidebar-label]').forEach((link) => {
                link.title = collapsed ? link.dataset.sidebarLabel : '';
            });
        };

        const applyResponsiveSidebarState = () => {
            const stored = getStoredCollapsed();
            const collapsed = stored === null ? !wideBreakpoint.matches : stored === '1';
            setCollapsed(collapsed);
            syncSidebarTitles();
        };

        const getVisibleSidebarNav = () => sidebarNavs.find((nav) => nav.offsetParent !== null) ?? null;

        const restoreSidebarScroll = () => {
            const sidebar = getVisibleSidebarNav();
            if (!sidebar) {
                return;
            }

            const saved = window.sessionStorage.getItem(scrollKey);
            if (saved !== null) {
                sidebar.scrollTop = parseInt(saved, 10) || 0;
            }

            const activeItem = sidebar.querySelector('.sidebar-link-active');
            if (!activeItem) {
                return;
            }

            const sidebarRect = sidebar.getBoundingClientRect();
            const itemRect = activeItem.getBoundingClientRect();
            if (itemRect.top < sidebarRect.top || itemRect.bottom > sidebarRect.bottom) {
                activeItem.scrollIntoView({ block: 'nearest' });
            }
        };

        const persistSidebarScroll = (sidebar) => {
            if (!sidebar) {
                return;
            }

            window.sessionStorage.setItem(scrollKey, String(sidebar.scrollTop));
        };

        applyResponsiveSidebarState();
        if (toggle) {
            toggle.addEventListener('click', () => {
                const nextState = !body.classList.contains('sidebar-collapsed');
                setCollapsed(nextState);
                window.localStorage.setItem(collapseKey, nextState ? '1' : '0');
                syncSidebarTitles();
            });
        }

        sidebarNavs.forEach((sidebar) => {
            sidebar.addEventListener('scroll', () => persistSidebarScroll(sidebar), { passive: true });
            sidebar.querySelectorAll('.app-sidebar-link[href]').forEach((link) => {
                link.addEventListener('click', () => {
                    persistSidebarScroll(sidebar);
                    if (mobileOffcanvas && !desktopBreakpoint.matches) {
                        mobileOffcanvas.hide();
                    }
                }, { passive: true });
            });
        });

        restoreSidebarScroll();
        syncSidebarTitles();
        window.addEventListener('pageshow', restoreSidebarScroll);
        window.addEventListener('resize', applyResponsiveSidebarState);
    })();
</script>
@stack('scripts')
</body>
</html>
