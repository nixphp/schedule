<?php

declare(strict_types=1);

namespace NixPHP\Schedule\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Optimized cron expression parser for NixPHP.
 *
 * Supported syntax (classic 5-part cron):
 *
 *   ┌───────────── minute (0 - 59)
 *   │ ┌─────────── hour (0 - 23)
 *   │ │ ┌───────── day of month (1 - 31)
 *   │ │ │ ┌─────── month (1 - 12)
 *   │ │ │ │ ┌───── day of week (0 - 6, 0 = Sunday)
 *   │ │ │ │ │
 *   * * * * *
 *
 * Supported patterns: *, single values, lists (,), ranges (-), steps (* /)
*/
final class CronParser
{
    private array $cache = [];

    /**
     * Check if the given cron expression is due at the specified DateTime.
     */
    public function isDue(string $expression, ?DateTimeImmutable $dateTime = null): bool
    {
        $dateTime ??= new DateTimeImmutable();
        $parts = $this->parse($expression);

        $minute  = (int) $dateTime->format('i');
        $hour    = (int) $dateTime->format('G');
        $day     = (int) $dateTime->format('j');
        $month   = (int) $dateTime->format('n');
        $weekday = (int) $dateTime->format('w');

        return $this->match($parts[0], $minute,  0, 59)
            && $this->match($parts[1], $hour,    0, 23)
            && $this->match($parts[2], $day,     1, 31)
            && $this->match($parts[3], $month,   1, 12)
            && $this->match($parts[4], $weekday, 0, 6);
    }

    /**
     * Find the next DateTime where the expression is due.
     */
    public function nextRun(string $expression, ?DateTimeImmutable $from = null): DateTimeImmutable
    {
        $date = ($from ?? new DateTimeImmutable())->modify('+1 minute');
        $date = $date->setTime((int) $date->format('G'), (int) $date->format('i'), 0);

        for ($i = 0; $i < 525_600; $i++) {
            if ($this->isDue($expression, $date)) {
                return $date;
            }
            $date = $date->modify('+1 minute');
        }

        throw new RuntimeException("Unable to find next run time for: {$expression}");
    }

    /**
     * Parse and cache the expression parts.
     *
     * @return array<int, string>
     */
    private function parse(string $expression): array
    {
        if (isset($this->cache[$expression])) {
            return $this->cache[$expression];
        }

        $normalized = preg_replace('/\s+/', ' ', trim($expression));
        $parts = explode(' ', $normalized);

        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Invalid cron expression: {$expression}");
        }

        return $this->cache[$expression] = $parts;
    }

    /**
     * Match a cron field against a value.
     */
    private function match(string $expr, int $value, int $min, int $max): bool
    {
        // Fast path: wildcard
        if ($expr === '*') {
            return true;
        }

        // Fast path: single digit
        if (ctype_digit($expr)) {
            $int = (int) $expr;
            return $int >= $min && $int <= $max && $value === $int;
        }

        // Step: */5
        if (str_starts_with($expr, '*/')) {
            $step = (int) substr($expr, 2);
            return $step > 0 && ($value - $min) % $step === 0;
        }

        // List: 1,2,3
        if (str_contains($expr, ',')) {
            foreach (explode(',', $expr) as $part) {
                if ($this->match(trim($part), $value, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        // Range: 5-10
        if (str_contains($expr, '-')) {
            [$start, $end] = explode('-', $expr, 2);
            $start = max((int) $start, $min);
            $end = min((int) $end, $max);
            return $value >= $start && $value <= $end;
        }

        return false;
    }
}