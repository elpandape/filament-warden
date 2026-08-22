<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Post extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['published' => 'boolean'];
}
