@extends('layouts.app')

@section('content')
@php
    $displayValue = function ($item, $field, $definition) {
        $type = $definition['type'] ?? 'text';
        if ($type === 'relation') {
            $relation = $definition['relation_name'] ?? str($field)->beforeLast('_id')->camel()->toString();
            $related = method_exists($item, $relation)
                ? data_get($item, $relation)
                : (($item->{$field} ?? null) ? $definition['model']::query()->find($item->{$field}) : null);

            return data_get($related, $definition['display']) ?? '—';
        }
        if ($type === 'checkbox') {
            return $item->{$field} ? 'Sí' : 'No';
        }
        if ($item->{$field} instanceof \Carbon\CarbonInterface) {
            return $item->{$field}->format('d-m-Y');
        }
        return $item->{$field} !== null && $item->{$field} !== '' ? $item->{$field} : '—';
    };
    $isCatalog = (bool) ($config['catalog'] ?? false);
@endphp
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $config['title'] }}</h1>
        <div class="page-subtitle">Núcleo operacional conectado a empresa y caja real.</div>
    </div>
    <div class="page-toolbar">
        <form method="GET" class="d-flex gap-2">
            <input class="form-control" type="search" name="q" value="{{ $search ?? '' }}" placeholder="Buscar">
            <button class="btn btn-outline-secondary" type="submit">Filtrar</button>
        </form>
        <a class="btn btn-primary" href="{{ route('operational.create', $resource) }}">Nuevo</a>
    </div>
</div>

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            @foreach ($config['fields'] as $field => $definition)
                <th>{{ $definition['label'] }}</th>
            @endforeach
            <th class="text-end">Acciones</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                @foreach ($config['fields'] as $field => $definition)
                    <td>
                        @if (str_contains(mb_strtolower($definition['label']), 'estado') || in_array($field, ['status', 'payment_status', 'approval_status', 'project_status', 'billing_status'], true))
                            <x-status-badge :status="$displayValue($item, $field, $definition)" />
                        @else
                            {{ $displayValue($item, $field, $definition) }}
                        @endif
                    </td>
                @endforeach
                <td class="text-end">
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary" href="{{ route('operational.show', [$resource, $item->id]) }}">Ver</a>
                        <a class="btn btn-outline-primary" href="{{ route('operational.edit', [$resource, $item->id]) }}">Editar</a>
                        @if ($isCatalog)
                            <form method="POST" action="{{ route('operational.toggle-active', [$resource, $item->id]) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-outline-warning">{{ $item->active ? 'Desactivar' : 'Activar' }}</button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($config['fields']) + 1 }}" class="text-center text-muted py-5">Sin registros.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">
    {{ $items->links() }}
</div>
@endsection
