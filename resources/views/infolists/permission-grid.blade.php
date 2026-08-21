{{--
    A ViewEntry draws no wrapper of its own — no label, no layout — so it has to
    render one, unlike a field. And the component name is the blade-component
    form, not the view path.
--}}
@php
    $grid = $getGrid();
    // A literal and not a live binding, because nothing here writes back. It is
    // still the whole payload: alpine re-derives every cell from it on boot, so
    // an empty object drew an empty grid over a correct one.
    $binding = \Illuminate\Support\Js::from($grid->stored);
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @include('filament-warden::grid', [
        'grid' => $grid,
        'interactive' => $grid->isInteractive,
        'binding' => $binding,
        'componentKey' => $getKey(),
    ])
</x-dynamic-component>
