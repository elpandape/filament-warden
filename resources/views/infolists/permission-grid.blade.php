{{--
    A ViewEntry draws no wrapper of its own — no label, no layout — so this view
    renders one, and `$getEntryWrapperView()` is a component name, not a view
    path.
--}}
@php
    $grid = $getGrid();
    // A literal and not a live binding, because nothing here writes back — but
    // still the whole payload: alpine re-derives every cell from it on boot, so
    // an empty one would draw an empty grid over the server's correct one.
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
