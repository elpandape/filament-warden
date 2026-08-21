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
