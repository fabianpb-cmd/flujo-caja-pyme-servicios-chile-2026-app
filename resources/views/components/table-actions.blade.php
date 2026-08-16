@props([
    'showUrl' => null,
    'editUrl' => null,
    'toggleUrl' => null,
    'active' => true,
])

<div class="table-actions">
    @if ($showUrl)
        <a class="btn btn-sm btn-outline-secondary" href="{{ $showUrl }}">Ver</a>
    @endif

    @if ($editUrl)
        <a class="btn btn-sm btn-outline-primary" href="{{ $editUrl }}">Editar</a>
    @endif

    @if ($toggleUrl)
        <form method="POST" action="{{ $toggleUrl }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-sm {{ $active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                {{ $active ? 'Desactivar' : 'Activar' }}
            </button>
        </form>
    @endif
</div>
