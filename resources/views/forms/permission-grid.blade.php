@php
    $grid = $getGrid();
    // Read off the view model rather than `$isDisabled()`: the field hands its
    // answer there, so the cells and the read-only notice share one value.
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
