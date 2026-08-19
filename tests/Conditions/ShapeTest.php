<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('only the three a person can choose may be written', function (): void {
    expect(Shape::All->isEditable())->toBeTrue()
        ->and(Shape::Owned->isEditable())->toBeTrue()
        ->and(Shape::Conditions->isEditable())->toBeTrue()
        ->and(Shape::Unreadable->isEditable())->toBeFalse()
        ->and(Shape::Tangled->isEditable())->toBeFalse();
});

test('every shape but the widest needs a record in front of it', function (): void {
    expect(Shape::All->isNarrowed())->toBeFalse();

    foreach ([Shape::Owned, Shape::Conditions, Shape::Unreadable, Shape::Tangled] as $shape) {
        expect($shape->isNarrowed())->toBeTrue();
    }
});
