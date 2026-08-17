<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Flujo de Caja') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app-dashboard.css') }}?v={{ filemtime(public_path('css/app-dashboard.css')) }}" rel="stylesheet">
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
    $resourceSingularTitle = $resourceConfig['singular_title'] ?? null;
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
                $sidebarItem('Novedades remuneración', 'bi bi-pencil-square', 'operational.index', ['payroll-adjustments'], ['resource' => 'payroll-adjustments', 'operational_fallback' => true]),
            ],
        ],
        [
            'title' => 'Ventas',
            'items' => [
                $sidebarItem('Facturas / Ingresos', 'bi bi-receipt', 'sales-documents.index'),
                $sidebarItem('Cuentas por cobrar', 'bi bi-currency-dollar', 'receivables.index'),
            ],
        ],
        [
            'title' => 'Gastos',
            'items' => [
                $sidebarItem('Egresos / Gastos', 'bi bi-cart3', 'expense-documents.index'),
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
                    'title' => 'Acceso',
                    'items' => [
                        $sidebarItem('Usuarios', 'bi bi-person-gear', 'admin.users.index'),
                    ],
                ],
                [
                    'title' => 'Parámetros y valores',
                    'items' => [
                        $sidebarItem('UF', 'bi bi-123', 'operational.index', ['uf-values'], ['resource' => 'uf-values', 'operational_fallback' => true]),
                        $sidebarItem('UTM', 'bi bi-calendar3', 'operational.index', ['utm-values'], ['resource' => 'utm-values', 'operational_fallback' => true]),
                        $sidebarItem('Tipos de cambio', 'bi bi-currency-exchange', 'operational.index', ['exchange-rates'], ['resource' => 'exchange-rates', 'operational_fallback' => true]),
                        $sidebarItem('Parámetros legales', 'bi bi-journal-text', 'operational.index', ['legal-parameters'], ['resource' => 'legal-parameters', 'operational_fallback' => true]),
                        $sidebarItem('AFP y tasas', 'bi bi-shield-check', 'operational.index', ['afp-rates'], ['resource' => 'afp-rates', 'operational_fallback' => true]),
                        $sidebarItem('Tabla IUSC', 'bi bi-table', 'operational.index', ['income-tax-brackets'], ['resource' => 'income-tax-brackets', 'operational_fallback' => true]),
                        $sidebarItem('Parámetros empresa', 'bi bi-building-gear', 'operational.index', ['company-settings'], ['resource' => 'company-settings', 'operational_fallback' => true]),
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
            'operational.create' => $resourceSingularTitle ? 'Nueva '.Illuminate\Support\Str::lower($resourceSingularTitle) : 'Nuevo',
            'operational.edit' => $resourceSingularTitle ? 'Editar '.Illuminate\Support\Str::lower($resourceSingularTitle) : 'Editar',
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
            'admin.users.index' => ['Administración', 'Usuarios'],
            'admin.users.create' => ['Administración', 'Usuarios', 'Nuevo'],
            'admin.users.edit' => ['Administración', 'Usuarios', 'Editar'],
            'admin.users.password.edit' => ['Administración', 'Usuarios', 'Restablecer contraseña'],
        ];
        $breadcrumb = $managementMap[$currentRouteName] ?? null;
    }
    $errorMessages = [];
    $sharedErrors = $__env->getShared()['errors'] ?? null;

    if ($sharedErrors instanceof \Illuminate\Support\ViewErrorBag) {
        $errorMessages = $sharedErrors->getBag('default')->all();
    } elseif ($sharedErrors instanceof \Illuminate\Support\MessageBag) {
        $errorMessages = $sharedErrors->all();
    } elseif (isset($errors) && is_array($errors)) {
        $errorMessages = array_values($errors);
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

                    @auth
                        <div class="modal fade" id="sessionExpiryModal" tabindex="-1" aria-labelledby="sessionExpiryModalLabel" aria-hidden="true" data-session-warning-minutes="5">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="sessionExpiryModalLabel">Tu sesión está por expirar</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="mb-2">Por seguridad, la sesión se cerrará por inactividad.</p>
                                        <p class="mb-0 text-muted small">Puedes mantenerla abierta o cerrar sesión ahora.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-primary" data-session-keep-alive>
                                            Mantener sesión
                                        </button>
                                        <form method="POST" action="{{ route('logout') }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-primary">Cerrar sesión</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endauth
                </main>
            </div>
        </div>
    @else
        <main class="container py-4">
            @yield('content')
        </main>
    @endauth
</div>
<script nonce="{{ $cspNonce ?? '' }}" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="{{ $cspNonce ?? '' }}">
    (() => {
        const collapseKey = 'sidebarCollapsed';
        const scrollKeyPrefix = 'sidebarScrollTop:';
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
        const getScrollKey = (sidebar) => `${scrollKeyPrefix}${sidebar?.dataset.sidebarScroll || 'default'}`;

        const restoreSidebarScroll = () => {
            const sidebar = getVisibleSidebarNav();
            if (!sidebar) {
                return;
            }

            const saved = window.sessionStorage.getItem(getScrollKey(sidebar));
            if (saved !== null) {
                sidebar.scrollTop = parseInt(saved, 10) || 0;
            }

            const activeItem = sidebar.querySelector('.sidebar-link.is-active');
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

            window.sessionStorage.setItem(getScrollKey(sidebar), String(sidebar.scrollTop));
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
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach((trigger) => {
            bootstrap.Tooltip.getOrCreateInstance(trigger);
        });
        document.querySelectorAll('[data-submit-on-change="true"]').forEach((element) => {
            element.addEventListener('change', () => {
                element.form?.requestSubmit();
            });
        });

        const sessionModalElement = document.getElementById('sessionExpiryModal');
        if (sessionModalElement) {
            const modalInstance = bootstrap.Modal.getOrCreateInstance(sessionModalElement, {
                backdrop: 'static',
                keyboard: false,
            });
            const warningMinutes = Number(sessionModalElement.dataset.sessionWarningMinutes || '5');
            const sessionLifetimeMinutes = Number(@json((int) config('session.lifetime')));
            const warningDelayMs = Math.max((sessionLifetimeMinutes - warningMinutes) * 60 * 1000, 0);
            const keepAliveButton = sessionModalElement.querySelector('[data-session-keep-alive]');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const keepAliveUrl = @json(route('session.keep-alive'));
            let warningTimer = null;
            let warningVisible = false;

            const scheduleWarning = () => {
                if (warningTimer) {
                    window.clearTimeout(warningTimer);
                }

                if (!Number.isFinite(warningDelayMs) || warningDelayMs <= 0) {
                    return;
                }

                warningTimer = window.setTimeout(() => {
                    warningVisible = true;
                    modalInstance.show();
                }, warningDelayMs);
            };

            const resetWarningTimer = () => {
                if (warningVisible) {
                    return;
                }

                scheduleWarning();
            };

            const activityEvents = ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'];
            activityEvents.forEach((eventName) => {
                window.addEventListener(eventName, resetWarningTimer, { passive: true });
            });

            sessionModalElement.addEventListener('hidden.bs.modal', () => {
                warningVisible = false;
                scheduleWarning();
            });

            if (keepAliveButton) {
                keepAliveButton.addEventListener('click', async () => {
                    keepAliveButton.disabled = true;
                    try {
                        const response = await fetch(keepAliveUrl, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                            },
                            credentials: 'same-origin',
                        });

                        if (response.ok) {
                            warningVisible = false;
                            modalInstance.hide();
                            scheduleWarning();
                        } else {
                            window.location.href = @json(route('login'));
                        }
                    } catch (error) {
                        window.location.href = @json(route('login'));
                    } finally {
                        keepAliveButton.disabled = false;
                    }
                });
            }

            scheduleWarning();
        }
        window.addEventListener('pageshow', restoreSidebarScroll);
        window.addEventListener('resize', applyResponsiveSidebarState);
    })();
</script>
<script nonce="{{ $cspNonce ?? '' }}">
    (() => {
        const normalize = (value) => {
            const cleaned = String(value || '').toUpperCase().replace(/[^0-9K]/g, '');
            if (!cleaned) {
                return '';
            }

            const body = cleaned.slice(0, -1).replace(/^0+/, '');
            const dv = cleaned.slice(-1);

            return body ? `${body}-${dv}` : '';
        };

        const checkDigit = (body) => {
            let sum = 0;
            let multiplier = 2;

            for (let index = body.length - 1; index >= 0; index -= 1) {
                sum += Number(body[index]) * multiplier;
                multiplier = multiplier === 7 ? 2 : multiplier + 1;
            }

            const rest = 11 - (sum % 11);
            return rest === 11 ? '0' : (rest === 10 ? 'K' : String(rest));
        };

        const isValid = (value) => {
            const normalized = normalize(value);
            if (!normalized) {
                return false;
            }

            const [body, dv] = normalized.split('-');
            return /^\d+$/.test(body) && checkDigit(body) === dv;
        };

        const format = (value) => {
            const normalized = normalize(value);
            if (!normalized) {
                return '';
            }

            const [body, dv] = normalized.split('-');
            return `${Number(body).toLocaleString('es-CL')}-${dv}`;
        };

        const message = 'El RUT ingresado no es válido. Revise el número y dígito verificador.';

        const ensureFeedback = (input) => {
            let feedback = input.parentElement?.querySelector('.invalid-feedback[data-rut-feedback="true"]');
            if (!feedback) {
                feedback = document.createElement('div');
                feedback.className = 'invalid-feedback';
                feedback.dataset.rutFeedback = 'true';
                input.insertAdjacentElement('afterend', feedback);
            }

            return feedback;
        };

        const validateRutInput = (input, shouldFormat = false) => {
            const raw = input.value.trim();
            if (raw === '') {
                input.classList.remove('is-invalid');
                input.setCustomValidity('');
                const feedback = input.parentElement?.querySelector('.invalid-feedback[data-rut-feedback="true"]');
                if (feedback) {
                    feedback.remove();
                }
                return true;
            }

            const normalized = normalize(raw);
            const valid = isValid(normalized);
            const feedback = ensureFeedback(input);

            if (!valid) {
                input.classList.add('is-invalid');
                input.setCustomValidity(message);
                feedback.textContent = message;
                return false;
            }

            input.classList.remove('is-invalid');
            input.setCustomValidity('');
            if (shouldFormat) {
                input.value = format(normalized);
            }
            const existing = input.parentElement?.querySelector('.invalid-feedback[data-rut-feedback="true"]');
            if (existing) {
                existing.remove();
            }
            return true;
        };

        const bindRutInputs = () => {
            document.querySelectorAll('input[data-rut-field="true"]').forEach((input) => {
                input.addEventListener('blur', () => validateRutInput(input, true));
                input.addEventListener('input', () => {
                    if (input.classList.contains('is-invalid')) {
                        validateRutInput(input, false);
                    }
                });
                validateRutInput(input, true);
            });

            document.querySelectorAll('form').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    const rutInputs = Array.from(form.querySelectorAll('input[data-rut-field="true"]'));
                    const invalid = rutInputs.filter((input) => !validateRutInput(input, true));
                    if (invalid.length > 0) {
                        event.preventDefault();
                        invalid[0].focus();
                    }
                });
            });
        };

        window.ChileanRutUI = { normalize, format, isValid };
        bindRutInputs();
    })();
</script>
@stack('scripts')
</body>
</html>
