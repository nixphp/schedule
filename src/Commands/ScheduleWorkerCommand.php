<?php

declare(strict_types=1);

namespace NixPHP\Schedule\Commands;

use NixPHP\CLI\Core\AbstractCommand;
use NixPHP\CLI\Core\Input;
use NixPHP\CLI\Core\Output;
use NixPHP\Schedule\Core\Scheduler;

class ScheduleWorkerCommand extends AbstractCommand
{
    public const string NAME = 'schedule:worker';

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setTitle('NixPHP Schedule Worker')
            ->setDescription('Execute recurring tasks with cron syntax.');
    }

    public function run(Input $input, Output $output): int
    {
        while (true) {
            $this->scheduler->tick($output);
            usleep(500_000);
        }
    }
}