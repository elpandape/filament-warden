{{--
    One cell, in the two shapes it can take.

    A locked grid is read, not operated, so its cells are not controls at all —
    not disabled buttons. `$interactive` is a value the caller already worked out.
--}}
@if ($interactive)
    <button
        type="button"
        class="fw-box"
        data-fw-row="{{ $cell->row }}"
        data-fw-action="{{ $cell->action }}"
        data-state="{{ $cell->drawn() }}"
        data-broader="{{ $cell->broader() }}"
        x-bind:data-state="drawn(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
        x-bind:data-broader="reached(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
        x-on:click="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
        x-on:keydown.enter.prevent="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
        x-on:keydown.space.prevent="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
        @disabled($cell->narrowed)
        aria-label="{{ $label }}"
    >
        @if ($cell->narrowed)
            <span class="fw-noted"></span>
        @endif
    </button>
@else
    <span
        class="fw-box fw-locked"
        data-fw-row="{{ $cell->row }}"
        data-fw-action="{{ $cell->action }}"
        data-state="{{ $cell->drawn() }}"
        data-broader="{{ $cell->broader() }}"
        role="img"
        aria-label="{{ $label }}"
    >
        @if ($cell->narrowed)
            <span class="fw-noted"></span>
        @endif
    </span>
@endif
