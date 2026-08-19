{{--
    One cell, in the two shapes it can take.

    Both are buttons: a locked grid still selects, because understanding why a
    cell is the way it is is reading, not operating. What the locked one does not
    do is cycle.
--}}
<button
    type="button"
    class="fw-box @unless ($interactive) fw-locked @endunless"
    data-fw-row="{{ $cell->row }}"
    data-fw-action="{{ $cell->action }}"
    data-state="{{ $cell->drawn() }}"
    data-broader="{{ $cell->broader() }}"
    x-bind:data-state="drawn(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    x-bind:data-broader="reached(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    x-bind:data-selected="selected && selected.row === @js($cell->row) && selected.action === @js($cell->action) ? 'true' : 'false'"
    @if ($interactive)
        x-on:click="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        x-on:keydown.enter.prevent="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        x-on:keydown.space.prevent="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        @disabled($cell->narrowed)
    @else
        x-on:click="select(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name))"
    @endif
    aria-label="{{ $label }}"
>
    @if ($cell->narrowed)
        <span class="fw-noted"></span>
    @endif
</button>
