{{--
    One cell, in the two shapes it can take.

    Both are buttons: a locked grid still selects, because understanding why a
    cell is the way it is is reading, not operating. What the locked one does not
    do is cycle — and neither does a cell whose rule this screen can read and
    cannot draw, which is the one people most need to be able to ask about.

    The state is drawn from `data-*` for the eye and said in the spans below for
    everything else. They are separate spans and not one sentence on purpose: the
    accessibility tree joins the text itself, so neither php nor the browser ever
    composes a name — each half only indexes the map php filled in. The words
    themselves are `GridView::states()`, and the first three of it are the cycle.
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
    data-until="{{ $cell->until?->toIso8601String() }}"
    data-lent="{{ $cell->inheritedFrom }}"
    x-bind:data-state="drawn(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    x-bind:data-broader="reached(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    x-bind:data-selected="selected && selected.row === @js($cell->row) && selected.action === @js($cell->action) ? 'true' : 'false'"
    @unless ($cell->isLocked())
        x-bind:data-noted="narrowedAt(@js($cell->row), @js($cell->action)) ? 'true' : 'false'"
    @endunless
    x-bind:data-until="untilAt(@js($cell->row), @js($cell->action))"
    x-bind:data-lent="inheritedAt(@js($cell->row), @js($cell->action))?.role"
    @if ($cell->isOperable($interactive))
        x-on:click="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        x-on:keydown.enter.prevent="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
        x-on:keydown.space.prevent="pick(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name), $event.shiftKey)"
    @else
        x-on:click="select(@js($cell->row), @js($cell->action), @js($label), @js($cell->entry?->name))"
    @endif
>
    <span class="fw-sr">{{ $label }}</span>
    <span
        class="fw-sr"
        x-text="stateOf(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    >{{ $states[$cell->answers()->value] }}</span>
    <span
        class="fw-sr"
        x-text="reachedMark(@js($cell->row), @js($cell->action), @js($cell->entry?->name))"
    >{{ $cell->isBroader() ? $states['broader'] : '' }}</span>
    @if ($cell->isLocked())
        {{-- Locked never changes in the browser, so it is written once and not bound. --}}
        <span class="fw-sr">{{ $states['locked'] }}</span>
    @else
        <span
            class="fw-sr"
            x-text="markOf(@js($cell->row), @js($cell->action))"
        >{{ $cell->isNarrowed() ? $states['narrowed'] : '' }}</span>
    @endif

    {{--
        The two marks 3.0 adds. Elements and not pseudo-elements, because a
        button has exactly two of those and the glyph and the first corner have
        them — and because a pseudo-element cannot carry a name, while these have
        a word each, said in the same list as the four above.

        `x-show` and not `@if`: the server draws the first reading and the
        browser redraws every one after it, so a mark that only existed in php
        would vanish on the first click and one that only existed in alpine would
        be missing until something was clicked.
    --}}
    <span
        class="fw-mark fw-mark-time"
        aria-hidden="true"
        x-show="untilAt(@js($cell->row), @js($cell->action)) !== null"
        @unless ($cell->until) style="display: none" @endunless
    ></span>
    <span
        class="fw-sr"
        x-text="timeMark(@js($cell->row), @js($cell->action))"
    >{{ $cell->until ? $states['expires'] : '' }}</span>

    <span
        class="fw-mark fw-mark-lent"
        aria-hidden="true"
        x-show="inheritedAt(@js($cell->row), @js($cell->action)) !== null"
        @unless ($cell->inheritedFrom) style="display: none" @endunless
    ></span>
    <span
        class="fw-sr"
        x-text="lentMark(@js($cell->row), @js($cell->action))"
    >{{ $cell->inheritedFrom ? $states['inherited'] : '' }}</span>
</button>
