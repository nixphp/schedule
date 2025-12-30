<?php

declare(strict_types=1);

namespace NixPHP\Schedule\Commands;

use NixPHP\CLI\Core\AbstractCommand;
use NixPHP\CLI\Core\Input;
use NixPHP\CLI\Core\Output;
use NixPHP\Schedule\Core\Scheduler;
use function NixPHP\app;
use function NixPHP\log;

class ScheduleTickerCommand extends AbstractCommand
{
    public const string NAME = 'schedule:ticker';

    /** @var array<int, resource> */
    private array $workers = [];

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
            ->addOption('max-runtime', null, true)
            ->addOption('workers', null, true);
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->getOption('help')) {
            $this->showHelp($output);

            return self::SUCCESS;
        }

        $jobCount    = 0;
        $maxJobs     = $input->getOption('max-jobs') ?? 0;
        $maxRuntime  = $input->getOption('max-runtime') ?? 0;
        $workerCount = (int)($input->getOption('workers') ?? 0);

        $timeStarted = time();

        for ($i = 0; $i < $workerCount; $i++) {
            $this->workers[$i] = $this->spawnQueueWorker($i, $maxJobs, $maxRuntime);
        }

        $output->writeLine('Schedule ticker started at ' . date('Y-m-d H:i:s', $timeStarted));

        if ($workerCount > 0) {
            $output->writeLine("{$workerCount} Queue workers started at " . date('Y-m-d H:i:s', $timeStarted));
        }

        while (true) {
            if ($maxJobs && $jobCount >= $maxJobs) {
                $msg = 'NixPHP Schedule Worker: Max jobs reached... Quitting.';
                $output->writeLine($msg);
                log()->info($msg);
                $this->terminateAllWorkers();
                break;
            }

            if ($maxRuntime && time() >= ($timeStarted + $maxRuntime)) {
                $msg = 'NixPHP Schedule Worker: Max runtime reached... Quitting.';
                $output->writeLine($msg);
                log()->info($msg);
                $this->terminateAllWorkers();
                break;
            }

            $jobCount = $this->scheduler->tick($output);

            if (!empty($this->workers)) {
                $this->superviseWorkers($maxJobs, $maxRuntime, $output);
            }

            usleep(500_000);
        }

        return self::SUCCESS;
    }

    private function superviseWorkers(int $maxJobs, int $maxRuntime, Output $output)
    {
        foreach ($this->workers as $i => $proc) {
            if (!is_resource($proc)) { // Restart dead workers
                $this->workers[$i] = $this->spawnQueueWorker($i, $maxJobs, $maxRuntime);
                continue;
            }

            $status = proc_get_status($proc);

            if (($status['running'] ?? false) === false) {
                $output->writeLine("Queue worker #{$i} stopped, restarting...");
                @proc_close($proc);
                $this->workers[$i] = $this->spawnQueueWorker($i, $maxJobs, $maxRuntime);
            }
        }
    }

    /**
     * Spawn a queue worker whose stdout/stderr are redirected into per-worker log files.
     *
     * @return resource
     */
    private function spawnQueueWorker(int $id, ?int $maxJobs = null, ?int $maxRuntime = null)
    {
        $command = 'vendor/bin/nix queue:worker';

        if ($maxJobs) {
            $command .= ' --max-jobs=' . $maxJobs;
        }

        if ($maxRuntime) {
            $command .= ' --max-runtime=' . $maxRuntime;
        }

        $cwd    = app()->getBasePath();
        $logDir = log()->getLogDir();

        // Prefer a project-local log directory
        $logDir = $logDir . '/queue';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],                                   // stdin (we close immediately)
            1 => ['file', "{$logDir}/worker-{$id}.out.log", 'a'], // stdout
            2 => ['file', "{$logDir}/worker-{$id}.err.log", 'a'], // stderr
        ];

        $pipes = [];
        $proc  = proc_open($command, $descriptorSpec, $pipes, $cwd);

        if (!is_resource($proc)) {
            throw new \RuntimeException("Failed to spawn queue worker #{$id}");
        }

        // stdin not needed
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        return $proc;
    }

    private function terminateAllWorkers(): void
    {
        foreach ($this->workers as $i => $proc) {
            if (is_resource($proc)) {
                @proc_terminate($proc);
                @proc_close($proc);
            }
            unset($this->workers[$i]);
        }
    }
}
