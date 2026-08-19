{{--
    One cell, in the two shapes it can take.

    Both are buttons: a locked grid still selects, because understanding why a
    cell is the way it is is reading, not operating. What the locked one does not
    do is cycle — and neither does a cell whose rule this screen can read and
    cannot draw, which is the one people most need to be able to ask about.
--}}
<button
    type="button"
    class="fw-box @unless ($interactive) fw-locked @endunless"
    data-fw-row="{{ $cell->row }}"
    data-fw-action="{{ $cell->action }}"
    data-state="{{ $cell->drawn() }}"
    data-broader="{{ $cell->broader() }}"
    data-noted="{{ $cell->isNarrowed() ? 'true' : 'false' }}"
    data-locked="{{ $cell->isLocked() ? 'true' : 'false' }}"
    x-bind:data-state="drawn(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    x-bind:data-broader="reached(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    x-bind:data-selected="selected && selected.row === @js($cell->row) && selected.action === @js($cell->action) ? 'true' : 'false'"
    @unless ($cell->isLocked())
        x-bind:data-noted="narrowedAt(@js($cell->row), @js($cell->action)) ? 'true' : 'false'"
    @endunless
    @if ($interactive && ! $cell->isLocked())
        x-on:click="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        x-on:keydown.enter.prevent="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        x-on:keydown.space.prevent="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
    @else
        x-on:click="select(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name))"
    @endif
    aria-label="{{ $label }}"
></button>
