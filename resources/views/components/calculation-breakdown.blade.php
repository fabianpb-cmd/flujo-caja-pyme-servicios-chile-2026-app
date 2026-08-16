@props([
    'id',
    'title',
    'subtitle' => null,
    'triggerLabel' => 'Ver cálculo',
    'triggerClass' => 'btn btn-sm btn-outline-primary',
    'summaryLabel' => null,
    'summaryValue' => null,
    'breakdown' => [],
])

@php
    $sections = $breakdown['sections'] ?? [];
    $warnings = $breakdown['warnings'] ?? [];
    $parameters = $breakdown['parameters'] ?? [];
    $result = $breakdown['result'] ?? null;
@endphp

<button
    type="button"
    class="{{ $triggerClass }}"
    data-bs-toggle="modal"
    data-bs-target="#{{ $id }}"
>
    <i class="bi bi-info-circle me-1"></i>{{ $triggerLabel }}
</button>

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="{{ $id }}Label">{{ $title }}</h5>
                    @if ($subtitle)
                        <div class="text-muted small">{{ $subtitle }}</div>
                    @endif
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                @if ($summaryLabel !== null && $summaryValue !== null)
                    <div class="app-panel p-3 mb-3">
                        <div class="text-muted small">{{ $summaryLabel }}</div>
                        <div class="fw-semibold">{{ $summaryValue }}</div>
                    </div>
                @endif

                @if (! empty($warnings))
                    <div class="alert alert-warning">
                        <ul class="mb-0 ps-3">
                            @foreach ($warnings as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($result)
                    <div class="app-panel p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <div class="text-muted small">{{ $result['label'] ?? 'Resultado' }}</div>
                                <div class="fw-semibold">{{ $result['value'] ?? '—' }}</div>
                            </div>
                            @if (! empty($result['note']))
                                <div class="small text-muted text-end">{{ $result['note'] }}</div>
                            @endif
                        </div>
                    </div>
                @endif

                @foreach ($sections as $section)
                    <div class="mb-3">
                        <div class="section-title mb-2">{{ $section['title'] ?? '' }}</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                @foreach (($section['rows'] ?? []) as $row)
                                    <tr>
                                        <th class="text-muted fw-normal" style="width: 50%;">{{ $row['label'] ?? '' }}</th>
                                        <td class="text-end {{ $row['strong'] ?? false ? 'fw-semibold' : '' }}">{{ $row['value'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach

                @if (! empty($parameters))
                    <div class="mt-4">
                        <div class="section-title mb-2">Parámetros utilizados</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th>Parámetro</th>
                                    <th>Valor</th>
                                    <th>Vigencia</th>
                                    <th>Fuente</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($parameters as $parameter)
                                    <tr>
                                        <td>{{ $parameter['label'] ?? '—' }}</td>
                                        <td class="text-end">{{ $parameter['value'] ?? '—' }}</td>
                                        <td>{{ $parameter['validity'] ?? '—' }}</td>
                                        <td>
                                            @if (! empty($parameter['source_url']))
                                                <a href="{{ $parameter['source_url'] }}" target="_blank" rel="noopener">Fuente oficial</a>
                                            @else
                                                {{ $parameter['source'] ?? '—' }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
