{{--
    A ViewEntry draws no wrapper of its own — no label, no layout — so it has to
    render one, unlike a field. And the component name is the blade-component
    form, not the view path.
--}}
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @include('filament-warden::grid', [
        'grid' => $getGrid(),
        'interactive' => false,
        'binding' => '{}',
        'componentKey' => $getKey(),
    ])
</x-dynamic-component>
