@props([
    'id',
    'title' => '¿Cómo usar esta pantalla?',
    'bullets' => [],
])

<div class="app-panel p-3 mb-4 page-help-shell">
    <button class="page-help-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $id }}" aria-expanded="false" aria-controls="{{ $id }}">
        <i class="bi bi-question-circle"></i>
        <span>{{ $title }}</span>
        <i class="bi bi-chevron-down small"></i>
    </button>

    <div class="collapse mt-3" id="{{ $id }}">
        @if (! empty($bullets))
            <ul class="small text-muted mb-0 ps-3">
                @foreach ($bullets as $bullet)
                    <li>{{ $bullet }}</li>
                @endforeach
            </ul>
        @else
            {{ $slot }}
        @endif
    </div>
</div>
