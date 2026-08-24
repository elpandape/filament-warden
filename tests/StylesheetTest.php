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
 * `.fw-legend .fw-box`, and terminated on the first `}` because no declaration
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

test('the smallest print is drawn with the muted token', function (): void {
    expect(declarationsOf('.fw-inspector-sub'))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf(".fw-action-name,\n.fw-entity-model"))->toContain('color: var(--fw-muted)')
        ->and(declarationsOf('.fw-void'))->toContain('color: var(--fw-muted)');
});

test('a mark that carries meaning is drawn with the muted token too', function (): void {
    expect(declarationsOf('.fw-why'))->toContain('3px solid var(--fw-muted)')
        ->and(declarationsOf('.fw-clause'))->toContain('3px solid var(--fw-muted)');
});

test('every custom property the sheet reads is one it declares, and none is declared unread', function (): void {
    $sheet = stylesheet();

    preg_match_all('/var\((--fw-[a-z-]+)/', $sheet, $uses);
    preg_match_all('/(--fw-[a-z-]+)\s*:/', $sheet, $declarations);

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
    // Measured in a 1041px card: `100%` alone gave the spare width to the entity
    // column, 224px to 521px, with the cells still bunched at the left; a bare
    // `max-content` left the table — and the row rules — ending mid-card. The
    // pair plus a filler column keeps both.
    //
    // `min-` and never `max-`: a maximum resolves against a scrollport already
    // clamped to the card, so the table could not overflow it, `overflow-x`
    // never engaged, and the entity column gave up the width instead.
    expect(declarationsOf('.fw-table'))->toContain('inline-size: 100%')
        // Neither bound belongs on the table: a maximum stops it overflowing a
        // scrollport already clamped to the card, and a `max-content` minimum
        // resolves against the unconstrained filler column and blows the table
        // out — measured at 9856px in a 993px card. The floor goes on the cell.
        ->and(declarationsOf('.fw-table'))->not->toContain('max-inline-size')
        ->and(declarationsOf('.fw-table'))->not->toContain('min-inline-size')
        ->and(declarationsOf('.fw-filler'))->toContain('inline-size: auto')
        ->and(declarationsOf('.fw-scroll'))->toContain('overflow-x: auto');

    // The entity column holds its width from both sides, or a narrow card takes
    // it back: measured at 94px in a 520px card with only the cap declared.
    $pinned = declarationsOf(".fw-table .fw-corner,\n.fw-table .fw-entity");

    expect($pinned)->toContain('min-inline-size: 14rem')
        ->and($pinned)->toContain('max-inline-size: 14rem');
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
