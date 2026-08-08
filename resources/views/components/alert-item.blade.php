@props([
    'title',
    'text' => null,
    'status' => 'Informativo',
    'icon' => 'bi bi-info-circle',
    'tone' => 'info',
])

<div class="alert-item">
    <div class="alert-item-main">
        <span class="alert-item-icon kpi-icon {{ $tone }}">
            <i class="{{ $icon }}"></i>
        </span>
        <div>
            <div class="alert-item-title">{{ $title }}</div>
            @if ($text)
                <div class="alert-item-text">{{ $text }}</div>
            @endif
        </div>
    </div>
    <x-status-badge :status="$status" />
</div>
