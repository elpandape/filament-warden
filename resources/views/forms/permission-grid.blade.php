{{--
    The field wrapper, and the grid inside it. Everything the grid draws lives in
    the shared partial, which the read-only screen includes too.
--}}
@php
    $grid = $getGrid();
    // A locked grid is read, not operated: its cells select, they do not cycle.
    $interactive = ! $isDisabled();
    $binding = '$wire.'.$applyStateBindingModifiers("\$entangle('{$getStatePath()}')");
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @include('filament-warden::grid', [
        'grid' => $grid,
        'interactive' => $interactive,
        'binding' => $binding,
        'componentKey' => $getKey(),
    ])
</x-dynamic-component>
