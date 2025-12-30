<?php

declare(strict_types=1);

use NixPHP\Core\Container;
use NixPHP\Queue\Core\Queue;
use NixPHP\Schedule\Commands\ScheduleListCommand;
use NixPHP\Schedule\Commands\ScheduleTickerCommand;
use NixPHP\Schedule\Core\Scheduler;
use NixPHP\Schedule\Core\JobRepository;
use NixPHP\Schedule\Support\CronParser;
use function NixPHP\app;
use function NixPHP\CLI\command;

app()->container()->set(CronParser::class, static fn() => new CronParser());

app()->container()->set(JobRepository::class, static fn() => new JobRepository());

app()->container()->set(Scheduler::class, function(Container $container) {
    $queue          = $container->get(Queue::class);
    $taskRepository = $container->get(JobRepository::class);
    $cronParser     = $container->get(CronParser::class);
    return new Scheduler($queue, $taskRepository, $cronParser);
});

command()->add(ScheduleTickerCommand::class);
command()->add(ScheduleListCommand::class);