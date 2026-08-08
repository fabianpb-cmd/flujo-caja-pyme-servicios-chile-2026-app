@extends('layouts.app')

@section('content')
@php($editing = $item->exists)
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $editing ? 'Editar' : 'Nuevo' }} {{ $config['title'] }}</h1>
        <div class="page-subtitle">Los cálculos financieros se derivan al guardar.</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('operational.index', $resource) }}">Volver</a>
</div>

<form method="POST" action="{{ $editing ? route('operational.update', [$resource, $item->id]) : route('operational.store', $resource) }}" class="app-panel p-4">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="row g-3">
        @foreach ($config['fields'] as $field => $definition)
            @php($type = $definition['type'] ?? 'text')
            @php($value = old($field, $item->{$field}))
            <div class="col-md-6">
                @if ($type === 'checkbox')
                    <div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input" id="{{ $field }}" name="{{ $field }}" value="1" @checked((bool) $value)>
                        <label class="form-check-label" for="{{ $field }}">{{ $definition['label'] }}</label>
                    </div>
                @else
                    <label for="{{ $field }}" class="form-label">{{ $definition['label'] }}</label>
                    @if (($definition['readonly'] ?? false) === true)
                        <input id="{{ $field }}" class="form-control" value="{{ $value }}" disabled>
                    @elseif ($type === 'textarea')
                        <textarea id="{{ $field }}" name="{{ $field }}" class="form-control" rows="3">{{ $value }}</textarea>
                    @elseif ($type === 'select')
                        <select id="{{ $field }}" name="{{ $field }}" class="form-select">
                            <option value="">Seleccione</option>
                            @foreach (($definition['options'] ?? []) as $key => $label)
                                <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    @elseif ($type === 'relation')
                        <select
                            id="{{ $field }}"
                            name="{{ $field }}"
                            class="form-select"
                            @if(isset($definition['depends_on']))
                                data-dependent-select="true"
                                data-parent-field="{{ $definition['depends_on'] }}"
                            @endif
                        >
                            <option value="">Seleccione</option>
                            @foreach (($options[$field] ?? []) as $key => $option)
                                @php($label = is_array($option) ? $option['label'] : $option)
                                @php($parentId = is_array($option) ? ($option['parent_id'] ?? null) : null)
                                <option value="{{ $key }}" @selected((string) $value === (string) $key) @if($parentId) data-parent-id="{{ $parentId }}" @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="{{ $field }}" name="{{ $field }}" type="{{ in_array($type, ['date', 'email', 'number'], true) ? $type : 'text' }}" class="form-control" value="{{ $value instanceof \Carbon\CarbonInterface ? $value->format('Y-m-d') : $value }}">
                    @endif
                    @error($field)
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                @endif
            </div>
        @endforeach
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('operational.index', $resource) }}">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    (() => {
        const childSelects = document.querySelectorAll('[data-dependent-select="true"]');

        childSelects.forEach((childSelect) => {
            const parentField = childSelect.dataset.parentField;
            const parentSelect = document.getElementById(parentField);
            if (!parentSelect) {
                return;
            }

            const syncOptions = () => {
                const parentValue = parentSelect.value;
                let hasSelectedVisible = false;

                Array.from(childSelect.options).forEach((option, index) => {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }

                    const optionParentId = option.dataset.parentId || '';
                    const visible = parentValue === '' || optionParentId === '' || optionParentId === parentValue;
                    option.hidden = !visible;

                    if (visible && option.selected) {
                        hasSelectedVisible = true;
                    }
                });

                if (!hasSelectedVisible) {
                    childSelect.value = '';
                }
            };

            parentSelect.addEventListener('change', syncOptions);
            syncOptions();
        });
    })();
</script>
@endpush
