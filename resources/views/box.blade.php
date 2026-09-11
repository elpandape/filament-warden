{{--
    One cell, in the two shapes it can take.

    Both are buttons and neither is ever `disabled`: a cell that may not be
    changed still selects, because understanding why a cell is the way it is is
    reading, not operating — and the one this screen cannot draw is the one
    people most need to ask about.

    The state is drawn from `data-*` for the eye and said in the `fw-sr` spans
    for everything else, one span per word rather than one sentence: the
    accessibility tree joins them itself, so nothing composes a name, and each
    word after the label is a lookup in `GridView::states()`.
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
        Spans and not pseudo-elements: `::before` and `::after` already draw
        the glyph and the first corner.

        `x-show` beside a server-drawn `display: none`: the server gets the
        reading right before alpine boots and alpine keeps it right after every
        click, and a mark drawn by only one of them is wrong on the other side.
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
