<?php

declare(strict_types=1);

namespace NixPHP\Schedule\Commands;

use DateTimeImmutable;
use NixPHP\CLI\Core\AbstractCommand;
use NixPHP\CLI\Core\Input;
use NixPHP\CLI\Core\Output;
use NixPHP\Schedule\Core\JobRepository;
use NixPHP\Schedule\Core\ScheduledJobInterface;
use NixPHP\Schedule\Support\CronParser;
use Throwable;
use function NixPHP\app;

final class ScheduleListCommand extends AbstractCommand
{
    public const string NAME = 'schedule:list';

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly CronParser    $cronParser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setTitle('NixPHP Schedule')
            ->setDescription('List scheduled jobs and their next run time.')
            ->addOption('from', null, true)
            ->addOption('no-sort', null);
    }

    public function run(Input $input, Output $output): int
    {
        $from   = $this->parseFrom($input->getOption('from'));
        $noSort = (bool) $input->getOption('no-sort');
        $nowTs  = time();

        $rows = [];

        foreach ($this->jobs->all() as $jobClass => $payload) {
            try {
                $job = app()->container()->make($jobClass, $payload);

                if (!$job instanceof ScheduledJobInterface) {
                    continue;
                }

                $expr = $job->getCronExpression();
                $next = $this->cronParser->nextRun($expr, $from);
                $ts   = $next->getTimestamp();

                $rows[] = [
                    'job'     => $jobClass,
                    'next'    => $next->format('Y-m-d H:i:s'),
                    'in'      => $this->formatDelta($ts - $nowTs),
                    'next_ts' => $ts,
                ];
            } catch (Throwable $e) {
                $rows[] = [
                    'job'     => $jobClass,
                    'next'    => 'ERROR',
                    'in'      => $e->getMessage(),
                    'next_ts' => PHP_INT_MAX,
                ];
            }
        }

        if ($rows === []) {
            $output->writeLine('No scheduled jobs registered.');
            return self::SUCCESS;
        }

        if (!$noSort) {
            usort($rows, static fn ($a, $b) => $a['next_ts'] <=> $b['next_ts']);
        }

        $jobWidth  = min(max(array_map(fn ($r) => strlen($r['job']), $rows)), 80);
        $timeWidth = 19;
        $inWidth   = 12;

        $output->writeLine(
            str_pad('Job', $jobWidth) .
            ' | ' . str_pad('Next run', $timeWidth) .
            ' | In'
        );
        $output->writeLine(str_repeat('-', $jobWidth + $timeWidth + $inWidth + 6));

        foreach ($rows as $r) {
            $output->writeLine(
                str_pad($r['job'], $jobWidth) .
                ' | ' . str_pad($r['next'], $timeWidth) .
                ' | ' . $r['in']
            );
        }

        return self::SUCCESS;
    }

    private function parseFrom(?string $from): DateTimeImmutable
    {
        if (!$from) {
            return new DateTimeImmutable();
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $from)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $from);

        return $dt ?: new DateTimeImmutable();
    }

    /**
     * Human-ish delta formatter for debug output.
     */
    private function formatDelta(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'now';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm';
        }

        if ($seconds < 86_400) {
            $h = floor($seconds / 3600);
            $m = floor(($seconds % 3600) / 60);
            return $h . 'h ' . $m . 'm';
        }

        $d = floor($seconds / 86_400);
        $h = floor(($seconds % 86_400) / 3600);
        return $d . 'd ' . $h . 'h';
    }
}
