@props([
    'text',
    'label' => 'Ayuda',
    'placement' => 'top',
])

<button
    type="button"
    class="field-help"
    data-bs-toggle="tooltip"
    data-bs-trigger="hover focus"
    data-bs-placement="{{ $placement }}"
    data-bs-title="{{ $text }}"
    aria-label="{{ $label }}: {{ $text }}"
>
    <i class="bi bi-info-circle-fill"></i>
</button>
