<?php

declare(strict_types=1);

namespace Tetthys\Pie\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for easy access in Laravel apps: Pie::publish(...), Pie::tick(...).
 */
final class Pie extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pie';
    }
}
