<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

/**
 * Where an entry came from, which is what the permission screen shows as its
 * provenance and what the audit command reads to tell an orphan from the rest.
 */
enum Origin: string
{
    case Resource = 'resource';

    case Model = 'model';

    case Page = 'page';

    case Widget = 'widget';

    case Custom = 'custom';

    case Panel = 'panel';
}
