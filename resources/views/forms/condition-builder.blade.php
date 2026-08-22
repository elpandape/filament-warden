{{--
    The condition builder as a field of its own.

    It draws nothing when the permission has no model behind it: a condition
    compares attributes of a row, and there is no row to look at. The form says
    why in the field's own hint, where a person is already reading.
--}}
@php
    // A bare `$entangle(...)` is a magic that Livewire's own directive
    // registers, not a name Alpine resolves inside x-data. Scoping it to the
    // component with `$wire.` is what `permission-grid.blade.php` already
    // does; without it this x-data expression throws before Alpine can build
    // it, and the whole component — clauses, source, preview — never mounts.
    $binding = '$wire.'.$applyStateBindingModifiers("\$entangle('{$getStatePath()}')");
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($getEntity() !== null)
        <div
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('permission-grid', 'elpandape/filament-warden') }}"
            x-data="wardenPermissionGrid({
                builder: true,
                state: {{ $binding }},
                words: @js($getWords()),
                source: @js($getSource()),
                interactive: @js(! $isDisabled()),
            })"
            class="fw-grid"
        >
            @include('filament-warden::conditions')
        </div>
    @endif
</x-dynamic-component>
