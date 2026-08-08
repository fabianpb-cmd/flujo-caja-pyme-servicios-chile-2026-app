@props([
    'instance' => 'default',
    'navigation' => [],
    'currentResource' => null,
    'currentRoute' => null,
])

@php
    $matchesItem = function (array $item) use ($currentResource): bool {
        $patterns = $item['route_patterns'] ?? [$item['route']];
        if (request()->routeIs($patterns)) {
            return true;
        }

        return (bool) ($item['operational_fallback'] ?? false)
            && ($item['resource'] ?? null) === $currentResource
            && request()->routeIs(['operational.index', 'operational.create', 'operational.show', 'operational.edit']);
    };
@endphp

<div class="app-sidebar-content">
    <div class="app-sidebar-brand">
        <span class="app-sidebar-brand-icon">
            <i class="bi bi-safe2"></i>
        </span>
        <div class="app-sidebar-brand-text">Flujo de Caja Pyme</div>
    </div>

    <div class="app-sidebar-nav" data-sidebar-scroll>
        @foreach ($navigation as $sectionIndex => $section)
            <div class="sidebar-section">
                <div class="sidebar-section-title">{{ $section['title'] }}</div>

                @foreach ($section['items'] ?? [] as $item)
                    @php
                        $isActive = $matchesItem($item);
                    @endphp
                    <a
                        class="app-sidebar-link {{ $isActive ? 'sidebar-link-active' : '' }}"
                        href="{{ route($item['route'], $item['params'] ?? []) }}"
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
                        $hasActiveChild = collect($group['items'])->contains(fn (array $item): bool => $matchesItem($item));
                        $collapseId = 'sidebarGroup'.$instance.$sectionIndex.$groupIndex;
                    @endphp
                    <div class="sidebar-subgroup">
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
                                        $isActive = $matchesItem($item);
                                    @endphp
                                    <a
                                        class="app-sidebar-link sidebar-subgroup-link {{ $isActive ? 'sidebar-link-active' : '' }}"
                                        href="{{ route($item['route'], $item['params'] ?? []) }}"
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
