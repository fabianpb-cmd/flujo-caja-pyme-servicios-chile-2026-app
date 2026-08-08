@props([
    'title',
    'value',
    'icon' => 'bi bi-circle',
    'tone' => 'primary',
    'subtitle' => null,
])

<div {{ $attributes->class(['app-panel', 'kpi-card']) }}>
    <div class="kpi-icon {{ $tone }}">
        <i class="{{ $icon }}"></i>
    </div>
    <div class="min-w-0">
        <div class="kpi-label">{{ $title }}</div>
        <div class="kpi-value">{{ $value }}</div>
        @if ($subtitle !== null && $subtitle !== '')
            <div class="kpi-subtitle">{{ $subtitle }}</div>
        @endif
    </div>
</div>
