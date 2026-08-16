@props([
    'instance' => 'default',
    'navigation' => [],
    'currentResource' => null,
    'currentRoute' => null,
])

@php
    $operationalRouteNames = ['operational.index', 'operational.create', 'operational.show', 'operational.edit'];
    $currentUrl = rtrim(url()->current(), '/');
@endphp

<div class="app-sidebar-content" data-sidebar-instance="{{ $instance }}">
    <div class="app-sidebar-brand">
        <span class="app-sidebar-brand-icon">
            <i class="bi bi-safe2"></i>
        </span>
        <div class="app-sidebar-brand-text">Flujo de Caja Pyme</div>
    </div>

    <div class="app-sidebar-nav" data-sidebar-scroll="{{ $instance }}">
        @foreach ($navigation as $sectionIndex => $section)
            <div class="sidebar-section">
                <div class="sidebar-section-title">{{ $section['title'] }}</div>

                @foreach ($section['items'] ?? [] as $item)
                    @php
                        $itemHref = rtrim(route($item['route'], $item['params'] ?? []), '/');
                        $routePatterns = $item['route_patterns'] ?? [$item['route']];
                        $isActive = (bool) ($item['operational_fallback'] ?? false)
                            ? (($item['resource'] ?? null) === $currentResource
                                && (
                                    in_array($currentRoute, $operationalRouteNames, true)
                                    || request()->is('operacion/'.($item['resource'] ?? '').'*')
                                ))
                            : ($itemHref === $currentUrl
                                || collect($routePatterns)->contains(
                                    fn (string $pattern): bool => \Illuminate\Support\Str::is($pattern, (string) $currentRoute)
                                ));
                    @endphp
                    <a
                        class="app-sidebar-link sidebar-link {{ $isActive ? 'is-active' : '' }}"
                        href="{{ $itemHref }}"
                        data-sidebar-label="{{ $item['label'] }}"
                        aria-current="{{ $isActive ? 'page' : 'false' }}"
                    >
                        <span class="app-sidebar-link-icon">
                            <i class="{{ $item['icon'] }}"></i>
                        </span>
                        <span class="app-sidebar-link-text">{{ $item['label'] }}</span>
                    </a>
                @endforeach

                @foreach ($section['groups'] ?? [] as $groupIndex => $group)
                    @php
                        $hasActiveChild = collect($group['items'])->contains(function (array $item) use ($currentResource, $currentRoute, $currentUrl, $operationalRouteNames): bool {
                            $itemHref = rtrim(route($item['route'], $item['params'] ?? []), '/');
                            $routePatterns = $item['route_patterns'] ?? [$item['route']];

                            return (bool) ($item['operational_fallback'] ?? false)
                                ? (($item['resource'] ?? null) === $currentResource
                                    && (
                                        in_array($currentRoute, $operationalRouteNames, true)
                                        || request()->is('operacion/'.($item['resource'] ?? '').'*')
                                    ))
                                : ($itemHref === $currentUrl
                                    || collect($routePatterns)->contains(
                                        fn (string $pattern): bool => \Illuminate\Support\Str::is($pattern, (string) $currentRoute)
                                    ));
                        });
                        $collapseId = 'sidebarGroup'.$instance.$sectionIndex.$groupIndex;
                    @endphp
                    <div class="sidebar-subgroup sidebar-group {{ $hasActiveChild ? 'is-open' : '' }}">
                        <button
                            class="sidebar-subgroup-toggle {{ $hasActiveChild ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#{{ $collapseId }}"
                            aria-expanded="{{ $hasActiveChild ? 'true' : 'false' }}"
                            aria-controls="{{ $collapseId }}"
                        >
                            <span class="sidebar-subgroup-label">{{ $group['title'] }}</span>
                            <i class="bi bi-chevron-down sidebar-subgroup-chevron"></i>
                        </button>
                        <div id="{{ $collapseId }}" class="collapse {{ $hasActiveChild ? 'show' : '' }}">
                            <div class="sidebar-subgroup-items">
                                @foreach ($group['items'] as $item)
                                    @php
                                        $itemHref = rtrim(route($item['route'], $item['params'] ?? []), '/');
                                        $routePatterns = $item['route_patterns'] ?? [$item['route']];
                                        $isActive = (bool) ($item['operational_fallback'] ?? false)
                                            ? (($item['resource'] ?? null) === $currentResource
                                                && (
                                                    in_array($currentRoute, $operationalRouteNames, true)
                                                    || request()->is('operacion/'.($item['resource'] ?? '').'*')
                                                ))
                                            : ($itemHref === $currentUrl
                                                || collect($routePatterns)->contains(
                                                    fn (string $pattern): bool => \Illuminate\Support\Str::is($pattern, (string) $currentRoute)
                                                ));
                                    @endphp
                                    <a
                                        class="app-sidebar-link sidebar-link sidebar-subgroup-link {{ $isActive ? 'is-active' : '' }}"
                                        href="{{ $itemHref }}"
                                        data-sidebar-label="{{ $item['label'] }}"
                                        aria-current="{{ $isActive ? 'page' : 'false' }}"
                                    >
                                        <span class="app-sidebar-link-icon">
                                            <i class="{{ $item['icon'] }}"></i>
                                        </span>
                                        <span class="app-sidebar-link-text">{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="app-sidebar-footer">
        <div class="app-sidebar-footer-chip">
            <span class="app-sidebar-link-icon">
                <i class="bi bi-building"></i>
            </span>
            <div>
                <div class="app-sidebar-footer-copy">Empresa activa</div>
                <div class="app-sidebar-footer-name">{{ \App\Models\Company::query()->find(auth()->user()->company_id)?->name ?? 'Empresa activa' }}</div>
            </div>
        </div>
    </div>
</div>
