{{--
    The field wrapper, and the grid inside it. Everything the grid draws lives in
    the shared partial, which the read-only screen includes too.
--}}
@php
    $grid = $getGrid();
    // A locked grid is read, not operated: its cells select, they do not cycle.
    // The field is the only thing that knows it was disabled and it hands the
    // answer to the view model, so one rule serves both the cells and the notice.
    $interactive = $grid->isInteractive;
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
