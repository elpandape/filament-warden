<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

/**
 * The sheet is written by hand against the panel's own runtime variables and no
 * build step reads it, so nothing but this file ever looks at it. The contrast
 * itself cannot be asserted here — the `--gray-*` values are supplied by the
 * consuming application's theme — but which token each rule reaches for can.
 */
function stylesheet(): string
{
    return (string) file_get_contents(dirname(__DIR__).'/resources/css/permission-grid.css');
}

/**
 * The declarations of the rule whose selector list is exactly $selector.
 *
 * Anchored on a line start so that `.fw-box` does not also catch
 * `.fw-key .fw-box`, and terminated on the first `}` because no declaration
 * in this sheet contains one.
 */
function declarationsOf(string $selector): string
{
    preg_match('/(?:^|\n)'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', stylesheet(), $matches);

    return implode('', array_slice($matches, 1));
}

/**
 * The body of one at-rule, so a test can read what is inside a query rather
 * than counting how many times its selectors appear in the file.
 *
 * Terminated on a `}` at the start of a line, which in this sheet is the close
 * of the query itself: every rule nested inside one is indented.
 */
function blockOf(string $atRule): string
{
    preg_match('/'.preg_quote($atRule, '/').'\s*\{(.*?)\n\}/s', stylesheet(), $matches);

    return implode('', array_slice($matches, 1));
}

/**
 * The BYTE offset of the first match of $pattern in $haystack, from $from, or
 * null.
 *
 * `PREG_OFFSET_CAPTURE` reports byte offsets and, unlike `strpos()`, survives
 * Pint's `mb_str_functions` rule unrewritten — that rule's `mb_` substitute
 * returns CHARACTER offsets, which stop lining up with a byte-oriented walk
 * the moment anything multibyte, like this sheet's em dashes, sits earlier in
 * the string. `??` on a possibly-absent match, rather than reading `$matches[0][1]`
 * at the call site, is what lets PHPStan narrow the result to a plain `int`
 * everywhere this is used instead of the array shape `preg_match()` returns.
 */
function byteOffsetOf(string $pattern, string $haystack, int $from = 0): ?int
{
    preg_match($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE, $from);

    return $matches[0][1] ?? null;
}

test('the smallest print is drawn with the muted token', function (): void {
    expect(declarationsOf('.fw-inspector-sub'))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf(".fw-action-name,\n.fw-entity-model"))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf('.fw-void'))->toContain('color: var(--fw-muted)');
});

test('a mark that carries meaning is drawn with the muted token too', function (): void {
    expect(declarationsOf('.fw-clause'))->toContain('3px solid var(--fw-muted)');
});

test('every custom property the sheet reads is one it declares, and none is declared unread', function (): void {
    $sheet = stylesheet();

    // `[a-z0-9-]+` and not `[a-z-]+`: a digit in a token name stops the narrow
    // class short, and the two sides then disagree about the SAME token — the
    // declaration side fails to match at all while the usage side captures a
    // truncated name, so the token reads as used and never declared. Measured
    // on a `--fw-head-1` that no longer ships: 18 used against 17 declared, red
    // on a sheet with nothing wrong with it. Byte-identical on today's sheet,
    // which has no token with a digit — kept for the next one that does.

    preg_match_all('/var\((--fw-[a-z0-9-]+)/', $sheet, $uses);
    preg_match_all('/(--fw-[a-z0-9-]+)\s*:/', $sheet, $declarations);

    $used = array_values(array_unique($uses[1]));
    $declared = array_values(array_unique($declarations[1]));

    sort($used);
    sort($declared);

    expect($used)->toBe($declared);
});

test('one reading is painted at every width, and the pair turns over together', function (): void {
    // Both declarations, read: counting the rules only says the pair is named
    // twice, which stays true when one of them says the wrong thing. Setting
    // the stack to `none` inside the query leaves NEITHER reading painted below
    // the fold, and a tally cannot see that.
    $fold = blockOf('@media (max-width: 55.9375rem)');

    expect(declarationsOf('.fw-stack'))->toContain('display: none')
        ->and($fold)->toContain('.fw-scroll')
        ->and($fold)->toContain('display: none')
        ->and($fold)->toContain('.fw-stack')
        ->and($fold)->toContain('display: grid')
        // Two disjoint queries look equivalent to a base plus one and are not:
        // between their thresholds neither fires. One query flips both.
        ->and(mb_substr_count(stylesheet(), '@media (max-width: 55.9375rem)'))->toBe(1);
});

test('the folded reading draws its small print with the muted token', function (): void {
    expect(declarationsOf('.fw-stack-model'))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf('.fw-stack-action'))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf('.fw-reach-hint'))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf('.fw-reach-reason'))->toContain('color: var(--fw-muted)');
});

test('the reach rail asks its container how wide it is, not the window', function (): void {
    // It lives at two widths at once — the whole inspector, and whatever a
    // consuming application leaves it — and a window query answers "no need to
    // stack" while the container is narrow and the labels truncate.
    expect(declarationsOf('.fw-builder'))->toContain('container-type: inline-size')
        ->and(stylesheet())->toContain('@container (max-width: 27rem)');
});

test('the table fills the card and still never compresses below the matrix', function (): void {
    // Measured on a table 1010px WIDE on 2026-09-06, in a 1120px viewport
    // where the card's own right edge sits at the x-coordinate 1065px: WITH
    // a filler column the action cells stopped at x=752, 313px short of that
    // edge; WITHOUT it the entity column takes the slack instead — growing
    // from 224px to 558px wide — and the cells now end at x=1065, flush with
    // the card. It gives the slack back as the card narrows: 392px in an
    // 844px table, and it never overflows. (558px is this standalone design
    // pass; the shipped application measures 556px for the same column — see
    // the CHANGELOG, which is the number that matters to somebody reading it.)
    //
    // An older note here said the same arrangement left "the cells still
    // bunched at the left". That does not reproduce against this markup, and
    // the markup it was measured on is not recorded — so it is written down as
    // not reproducing rather than argued with.
    //
    // `min-` and never `max-`: the floor is what stops a narrow card taking the
    // column back, measured at 94px in a 520px card with only a cap declared.
    // The cap is what stopped the column claiming the slack.
    expect(declarationsOf('.fw-table'))->toContain('inline-size: 100%')
        ->and(declarationsOf('.fw-table'))->not->toContain('max-inline-size')
        ->and(declarationsOf('.fw-table'))->not->toContain('min-inline-size')
        ->and(stylesheet())->not->toContain('.fw-filler')
        ->and(declarationsOf('.fw-scroll'))->toContain('overflow-x: auto');

    $pinned = declarationsOf(".fw-table .fw-corner,\n.fw-table .fw-entity");

    expect($pinned)->toContain('min-inline-size: 14rem')
        ->and($pinned)->not->toContain('max-inline-size: 14rem');
});

test('the wide condition row resets the misfit note it would otherwise be crushed by', function (): void {
    // The reset has to outrank the base rule, which is declared later in the
    // same unlayered sheet at equal specificity: unprefixed it lost the cascade
    // and left `nowrap` in force with a full-width note taking the row.
    $wide = blockOf('@media (min-width: 56rem)');

    $sheet = stylesheet();
    $prefixed = mb_strpos($sheet, '.fw-condition .fw-misfit');
    $base = mb_strpos($sheet, "\n.fw-misfit {");

    expect($wide)->toContain('.fw-condition .fw-misfit')
        ->and($wide)->toContain('flex-basis: auto')
        // Both present before either is ordered: a missing needle is `false`,
        // and casting that to an int reads as position zero.
        ->and($prefixed)->not->toBeFalse()
        ->and($base)->not->toBeFalse()
        ->and((int) $prefixed)->toBeLessThan((int) $base);
});

test('the rule and what it means share a row, and stack when there is no room', function (): void {
    // The editor was 34rem inside a 1010px panel and the rest was empty. The
    // warning and the live preview are what the rule MEANS, so they sit beside
    // it — placed by explicit row, because an `x-for` writes the clauses and
    // there is no knowing how many there are.
    expect(declarationsOf('.fw-conditions'))->toContain('grid-template-columns: minmax(0, 34rem) minmax(0, 1fr)')
        ->and(declarationsOf('.fw-conditions > .fw-warn'))->toContain('grid-row: 1')
        ->and(declarationsOf('.fw-conditions > .fw-preview'))->toContain('grid-row: 2');

    // And they stack at the same width where the table already becomes an
    // accordion, so the screen changes face once and not twice. Below it the
    // right rail measured ~40px and the warning fell to one word per line.
    //
    // Scoped to the query's own block, not the whole file: `grid-row: auto`
    // also names the sibling reposition rule, so an unscoped substring check
    // cannot tell the collapse's own rule apart from its neighbour — deleting
    // `.fw-conditions { grid-template-columns: minmax(0, 1fr); }` still left
    // that string in the file, and nothing went red.
    $fold = blockOf('@media (max-width: 55.9375rem)');

    expect($fold)->toContain('grid-template-columns: minmax(0, 1fr)')
        ->and($fold)->toContain('grid-row: auto');
});

test('the condition controls take the shape of a panel field, and its focus ring', function (): void {
    // Three native selects and an input in a row looked like no field in the
    // rest of the panel. Nothing is imported for this: it is CSS against the
    // panel's own tokens, and the chevron is a data URI like the tick already
    // is.
    $controls = declarationsOf(".fw-condition select,\n.fw-condition input");

    expect($controls)->toContain('border-radius: 0.5rem')
        ->and(declarationsOf('.fw-condition select'))->toContain('appearance: none')
        ->and(declarationsOf(".fw-condition select:focus-visible,\n.fw-condition input:focus-visible"))
        ->toContain('outline: 2px solid var(--fw-accent)');

    // Three letters and a chevron do not fit in 3.25rem: it drew "ar" for
    // "and".
    expect(declarationsOf('.fw-condition .fw-joiner'))->toContain('flex: 0 0 4.75rem');
});

test('the head corner is opaque and still wears the head tint', function (): void {
    // It went on `--fw-surface` while the rest of the head goes on
    // `--fw-raised`, and in dark that step is visible across the table. It
    // cannot simply take `--fw-raised`: that is a `color-mix` with
    // transparency and this cell is sticky, so the body rows would show
    // through. The two are stacked instead.
    expect(declarationsOf('.fw-table thead .fw-corner'))
        ->toContain('linear-gradient(var(--fw-raised), var(--fw-raised)),')
        ->and(declarationsOf('.fw-table thead .fw-corner'))->toContain('var(--fw-surface)');
});

test('nothing that carries a category shouts it in small caps', function (): void {
    // Weight and colour separate a group heading just as well, and they read.
    // `.fw-group` shares its block with `.fw-manage`, so it is read as the
    // pair the sheet actually writes: asking `declarationsOf()` for
    // `.fw-group` alone matches no line in the file (the line reads
    // `.fw-group,`, not `.fw-group {`) and passes on the empty string it
    // gets back.
    //
    // Each `not->toContain()` is paired with a `toContain()` from the SAME
    // block: a selector that stops matching — split into two blocks, or
    // renamed — returns `''` from `declarationsOf()`, and `''` does not
    // contain `text-transform: uppercase` either. The pair proves the block
    // was actually read and not just silently empty.
    $group = declarationsOf(".fw-group,\n.fw-manage");
    $field = declarationsOf('.fw-field-label');

    expect($group)->toContain('font-weight: 600')
        ->and($group)->not->toContain('text-transform: uppercase')
        ->and($field)->toContain('font-weight: 600')
        ->and($field)->not->toContain('text-transform: uppercase');
});

test('the tabs are one strip that scrolls, never two rows', function (): void {
    // Two rows put the list rule through the middle of it. A strip keeps the
    // tablist a tablist: one tab stop, arrow keys, and each tab's
    // `aria-controls` — all of which a `<select>` would take away.
    // Scoped to the query's own block, not the unscoped `.fw-tabs`: the base
    // rule already carries `flex-wrap: wrap` before this task touches
    // anything, so reading it unscoped would still pass with the fold's own
    // `nowrap` reverted.
    $fold = blockOf('@media (max-width: 55.9375rem)');

    expect($fold)->toContain('flex-wrap: nowrap')
        ->and($fold)->toContain('mask-image: linear-gradient(to right');
});

test('the fold gives the FQCN a row of its own before it breaks mid-word', function (): void {
    // At 390px the model shared a row with the name inside two columns and
    // broke mid-word: "Permis sions". `.fw-stack-entity > summary` is a
    // grid, not a flex container: one column stacks every line full width,
    // which is what gives the FQCN's own `overflow-wrap` room to work with.
    $narrow = blockOf('@media (max-width: 30rem)');

    // The semicolon matters: the base rule's two-column
    // `minmax(0, 1fr) auto` starts with this same substring, so a needle
    // without it would still match the very layout this test exists to rule
    // out.
    expect($narrow)->toContain('.fw-stack-entity > summary')
        ->and($narrow)->toContain('grid-template-columns: minmax(0, 1fr);');
});

test('the add-condition button is enlarged by the block that actually wins', function (): void {
    // Two `.fw-add {}` blocks at equal specificity let the LATER one win the
    // cascade: an earlier block's padding and radius would sit dead in the
    // file, silently overridden by the pre-existing dashed-border rule below
    // it. Counting the blocks is what catches a second one coming back.
    expect(mb_substr_count(stylesheet(), "\n.fw-add {"))->toBe(1)
        ->and(declarationsOf('.fw-add'))->toContain('padding: 0.3125rem 0.625rem')
        ->and(declarationsOf('.fw-add'))->toContain('border-radius: 0.5rem');
});

test('the fold outranks everything it collapses, by being the last rule in the file', function (): void {
    // A media query and its base rule share specificity, so source order
    // decides which one wins — and this query used to lose. It sat two
    // hundred lines above `.fw-conditions {}`, so the unconditional root rule
    // always came later and always won, at every width, on a real screen: a
    // 390px viewport measured `grid-template-columns` as still two columns.
    //
    // Pinning the query's position against only ONE base — `.fw-conditions`,
    // what this test checked before — proves nothing about the other seven
    // this same query overrides: `.fw-scroll`, `.fw-stack`, `.fw-tabs`,
    // `.fw-tab`, `.fw-conditions`'s own two children, and the four
    // `.fw-write .fw-*` selectors. Moving any ONE of those back below the
    // query reproduces the exact defect this release fixed, on a real
    // screen, with that narrower check still green.
    //
    // `declarationsOf()` and `blockOf()` can only prove a declaration exists
    // in the sheet, never that it wins the cascade. The one check that closes
    // that gap for every base at once is the invariant the comment above the
    // query already states in prose: nothing may restate any of them at the
    // root after this point — which is the same thing as saying the query is
    // the LAST rule in the file.
    //
    // Walked brace by brace and not matched with a greedy `.*`: a pattern
    // that only checks for "some `\n}` before the end of the string" is
    // fooled by anything appended afterwards, because an appended rule ends
    // in `\n}` too — confirmed by appending one to a copy of this exact sheet
    // and watching such a pattern keep matching regardless. Only tracking
    // brace depth finds the query's OWN closing brace and nothing past it.
    //
    // `byteOffsetOf()`, not `strpos()`/`substr()`: those return and take
    // CHARACTER positions once Pint's `mb_str_functions` rule rewrites them
    // to their `mb_` form, and this sheet has multibyte em dashes throughout
    // its comments — a character offset does not line up with where `{`
    // actually sits in the byte string `preg_match_all()` reports it at.
    $sheet = stylesheet();
    $start = byteOffsetOf('/@media \(max-width: 55\.9375rem\)/', $sheet);

    expect($start)->not->toBeNull();

    preg_match_all('/[{}]/', $sheet, $braces, PREG_OFFSET_CAPTURE, (int) $start);

    $depth = 0;
    $close = null;

    foreach ($braces[0] as $brace) {
        $depth += $brace[0] === '{' ? 1 : -1;

        if ($depth === 0) {
            $close = $brace[1];

            break;
        }
    }

    // Unbalanced braces would leave this null rather than let the next check
    // compare against a wrong-but-plausible position.
    expect($close)->not->toBeNull();

    // The query's closing brace is the last non-whitespace byte in the file:
    // found independently, by looking for a non-whitespace character with no
    // OTHER non-whitespace character anywhere after it — never by slicing
    // the tail and asking whether the slice is blank, which needs the same
    // `substr()` this test is avoiding.
    expect(byteOffsetOf('/\S(?!.*\S)/s', $sheet))->toBe($close);
});

test('the panel never takes width from the matrix, and sticks until it is opened', function (): void {
    $sheet = stylesheet();

    // 3.0.0 shipped it as a column that pushed, and pushing turned out to be the
    // worse of the two costs: the matrix fell from 1008px to 512 — half of what
    // a permission grid needs — to hand 480 to a panel that most of the time
    // says one sentence. There is no second track any more, at any width.
    expect($sheet)->not->toContain("[data-open='true']")
        ->and($sheet)->not->toContain('minmax(0, 1fr) 30rem');

    expect(declarationsOf('.fw-inspector'))->toContain('position: static;')
        ->not->toContain('position: sticky;');
});

test('the condition builder is what the width was needed for, and it gets it', function (): void {
    $sheet = stylesheet();

    // The reason the panel moved below the matrix rather than shrinking it: the
    // builder's two tracks — the rule on the left, what the rule MEANS on the
    // right — are the pairing 2.11 measured, and 446px of a 30rem side column
    // could not hold them at all. Full width it has 1008px and they fit.
    //
    // The stacked reading stays for the widths where they genuinely do not:
    // 52rem = 34 for the first track, 2 for the gap, 16 the note needs to be a
    // note rather than a strip.
    expect($sheet)->toContain('grid-template-columns: minmax(0, 34rem) minmax(0, 1fr);')
        ->and($sheet)->toMatch('/@container \(width < 52rem\) \{\s*\.fw-conditions \{/');
});

test('the two marks 3.0 adds are elements, because a button has only two pseudos', function (): void {
    $sheet = stylesheet();

    // `::before` draws the glyph and `::after` draws the amber or red corner.
    // Four marks need four boxes, so these two are elements the template writes
    // — which is also what lets them carry their own screen-reader word, since a
    // pseudo-element cannot be named.
    expect($sheet)->toContain('.fw-mark-time {')
        ->and($sheet)->toContain('.fw-mark-lent {')
        ->and($sheet)->toContain('--fw-mark: var(--fw-clock);')
        ->and($sheet)->toContain('--fw-mark: var(--fw-link);')
        // The two corners the first two do not use, so no cell ever stacks two
        // marks in one place.
        ->and($sheet)->toMatch('/\.fw-mark-time \{[^}]*inset-block-end/')
        ->and($sheet)->toMatch('/\.fw-mark-lent \{[^}]*inset-block-start/');
});

test('the condition builder stacks before its second track collapses to nothing', function (): void {
    $sheet = stylesheet();

    // The two tracks were written for the builder at full width on the
    // permission's own screen, where `34rem` and whatever is left is an honest
    // split. Inside the 30rem panel it is not: measured in a browser, the first
    // track takes 414px and the second computes to ZERO, so the note beside the
    // rule renders one character wide and five hundred tall. It does not
    // overflow and it is not clipped — it is drawn, and unreadable, which is
    // worse than missing.
    //
    // 52rem = 34 for the first track + 2 for the gap + 16 the note needs to be
    // a note rather than a strip.
    expect($sheet)->toMatch('/@container \(width < 52rem\) \{\s*\.fw-conditions \{/')
        ->and($sheet)->toContain('grid-template-columns: minmax(0, 34rem) minmax(0, 1fr);');
});
