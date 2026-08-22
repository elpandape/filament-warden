<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

/**
 * Where an entry came from, which is what the permission screen shows as its
 * provenance.
 *
 * The audit command does not read this: it tells an unused permission the
 * catalogue declares from one nothing declares by the entry KEY, not by where the
 * entry came from.
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
