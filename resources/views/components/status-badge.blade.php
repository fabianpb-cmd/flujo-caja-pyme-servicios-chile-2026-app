@props(['status' => 'Informativo'])

@php
    $label = trim((string) $status);
    $normalized = \Illuminate\Support\Str::of($label)
        ->lower()
        ->ascii()
        ->replaceMatches('/[^a-z0-9]+/', '-')
        ->trim('-')
        ->toString();
@endphp

<span {{ $attributes->class(['status-badge', $normalized]) }}>
    {{ $label !== '' ? $label : '-' }}
</span>
