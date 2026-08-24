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
    $sheet = stylesheet();

    // Two disjoint queries look equivalent to a base plus one and are not:
    // between their thresholds neither fires, and at that width the grid is
    // gone. The stack is off by default and a single query flips both — so the
    // fold is named in exactly one query, and never in a second one that would
    // have to agree with it.
    expect(declarationsOf('.fw-stack'))->toContain('display: none')
        ->and(mb_substr_count($sheet, '@media (max-width: 55.9375rem)'))->toBe(1)
        ->and(mb_substr_count($sheet, '.fw-stack {'))->toBe(2)
        ->and(mb_substr_count($sheet, '.fw-scroll {'))->toBe(2);
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
