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
        <div class="page-subtitle">{{ $item->{$config['display']} ?? $item->code ?? 'Registro' }}</div>
    </div>
    <div class="page-toolbar">
        <a class="btn btn-outline-secondary" href="{{ route('operational.index', $resource) }}">Volver</a>
        <a class="btn btn-primary" href="{{ route('operational.edit', [$resource, $item->id]) }}">Editar</a>
    </div>
</div>

<div class="app-panel p-4">
    <dl class="row mb-0">
        @foreach ($config['fields'] as $field => $definition)
            <dt class="col-sm-4">{{ $definition['label'] }}</dt>
            <dd class="col-sm-8">
                @if (str_contains(mb_strtolower($definition['label']), 'estado') || in_array($field, ['status', 'payment_status', 'approval_status', 'project_status', 'billing_status'], true))
                    <x-status-badge :status="$displayValue($item, $field, $definition)" />
                @else
                    {{ $displayValue($item, $field, $definition) }}
                @endif
            </dd>
        @endforeach
    </dl>

    @if ($isCatalog)
        <form method="POST" action="{{ route('operational.toggle-active', [$resource, $item->id]) }}" class="mt-4">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-outline-warning">{{ $item->active ? 'Desactivar' : 'Activar' }}</button>
        </form>
    @else
        <form method="POST" action="{{ route('operational.destroy', [$resource, $item->id]) }}" class="mt-4">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">Eliminar</button>
        </form>
    @endif
</div>
@endsection
