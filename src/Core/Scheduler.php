<?php

declare(strict_types=1);

namespace NixPHP\Schedule\Core;

use DateTimeImmutable;
use NixPHP\CLI\Core\Output;
use NixPHP\Queue\Core\Queue;
use NixPHP\Schedule\Support\CronParser;
use function NixPHP\app;

class Scheduler
{
    private string $stateFile;
    private array $lastRun = [];

    public function __construct(
        private readonly Queue         $queue,
        private readonly JobRepository $jobs,
        private readonly CronParser    $cronParser,
        ?string $stateFile = null,
    ) {
        $this->stateFile = $stateFile ?? sys_get_temp_dir() . '/nixphp-schedule-state.json';
        $this->loadState();
    }

    public function addScheduledJob(string $scheduledJob, array $payload = []): void
    {
        $this->jobs->add($scheduledJob, $payload);
    }

    public function tick(Output $output): void
    {
        $now = new DateTimeImmutable();

        foreach ($this->jobs->all() as $jobClass => $payload) {
            $jobInstance = app()->container()->make($jobClass, $payload);

            if (!$jobInstance instanceof ScheduledJobInterface) {
                continue;
            }

            $expression = $jobInstance->getCronExpression();

            if (!$this->cronParser->isDue($expression, $now)) {
                continue;
            }

            $currentMinute = $now->format('Y-m-d H:i');
            $jobKey = $jobClass . ':' . $expression;

            if (isset($this->lastRun[$jobKey]) && $this->lastRun[$jobKey] === $currentMinute) {
                continue;
            }

            $this->lastRun[$jobKey] = $currentMinute;
            $this->saveState();

            echo "Pushing job: {$jobClass} at {$currentMinute}\n";

            $this->queue->push(get_class($jobInstance), $payload);
        }
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $data = file_get_contents($this->stateFile);
            $this->lastRun = json_decode($data, true) ?? [];
        }
    }

    private function saveState(): void
    {
        file_put_contents($this->stateFile, json_encode($this->lastRun));
    }
}