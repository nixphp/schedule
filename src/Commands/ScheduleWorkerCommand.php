<?php

declare(strict_types=1);

namespace NixPHP\Schedule\Commands;

use NixPHP\CLI\Core\AbstractCommand;
use NixPHP\CLI\Core\Input;
use NixPHP\CLI\Core\Output;
use NixPHP\Schedule\Core\Scheduler;
use function NixPHP\app;
use function NixPHP\log;

class ScheduleWorkerCommand extends AbstractCommand
{
    public const string NAME = 'schedule:worker';

    private $workerPipes;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setTitle('NixPHP Schedule Worker')
            ->setDescription('Execute recurring tasks with cron syntax.')
            ->addOption('max-jobs', null, true)
            ->addOption('max-runtime', null, true);
    }

    public function run(Input $input, Output $output): int
    {
        $jobCount    = 0;
        $maxJobs     = $input->getOption('max-jobs') ?? null;
        $maxRuntime  = $input->getOption('max-runtime') ?? null;
        $timeStarted = time();

        $queueWorker = $this->spawnQueueWorker($maxJobs, $maxRuntime);
        
        $output->writeLine('Schedule worker started at ' . date('Y-m-d H:i:s', $timeStarted));
        $output->writeLine('Queue worker started at ' . date('Y-m-d H:i:s', $timeStarted));

        while (true) {
            if ($maxJobs && $jobCount >= $maxJobs) {
                $msg = 'NixPHP Schedule Worker: Max jobs reached.';
                $output->writeLine('NixPHP Schedule Worker: Quitting.');
                $output->writeLine($msg);
                log()->info($msg);
                break;
            }

            if ($maxRuntime && ($timeStarted + $maxRuntime) >= time()) {
                $msg = 'NixPHP Schedule Worker: Max runtime reached.';
                $output->writeLine($msg);
                $output->writeLine('NixPHP Schedule Worker: Quitting.');
                proc_terminate($queueWorker);
                log()->info($msg);
                break;
            }

            $jobCount = $this->scheduler->tick($output);

            $status = proc_get_status($queueWorker);

            if ($status['running'] === false) {
                $output->writeLine('Queue worker unexpectedly stopped at ' . date('Y-m-d H:i:s', time()));
                $queueWorker = $this->spawnQueueWorker($maxJobs, $maxRuntime);
            }

            stream_set_blocking($this->workerPipes[1], false);
            $output->writeLine(stream_get_contents($this->workerPipes[1]));

            usleep(500_000);
        }

        return self::SUCCESS;
    }

    /**
     * @return resource
     */
    private function spawnQueueWorker(?int $maxJobs = null, ?int $maxRuntime = null)
    {
        $command = 'vendor/bin/nix queue:worker';

        if ($maxJobs) {
            $command .= ' --max-jobs=' . $maxJobs;
        }

        if ($maxRuntime) {
            $command .= ' --max-runtime=' . $maxRuntime;
        }

        $cwd = app()->getBasePath();

        $descriptorSpec = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['file', '/tmp/nixphp-schedule-worker.log', 'a'],
        ];

        return proc_open($command, $descriptorSpec, $this->workerPipes, $cwd);
    }
}
