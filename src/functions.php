<?php

declare(strict_types=1);

namespace NixPHP\Schedule;

use NixPHP\Schedule\Core\Scheduler;
use function NixPHP\app;

/**
 * @return Scheduler
 */
function scheduler(): Scheduler
{
    return app()->container()->get(Scheduler::class);
}
