<div align="center" style="text-align: center;">

![Logo](https://nixphp.github.io/docs/assets/nixphp-logo-small-square.png)

[![NixPHP Schedule Plugin](https://github.com/nixphp/schedule/actions/workflows/php.yml/badge.svg)](https://github.com/nixphp/schedule/actions/workflows/php.yml)

</div>

[← Back to NixPHP](https://github.com/nixphp/framework)

---

# nixphp/schedule

> **Minimalistic scheduling for NixPHP – cron-based, predictable, and queue-aware.**

This plugin provides a lightweight scheduler for recurring tasks using cron expressions.
It is designed to work seamlessly with the NixPHP Queue plugin, without introducing hidden magic or unnecessary complexity.

> 🧩 Part of the official NixPHP plugin collection.  
> Use it when you want to run recurring jobs reliably – without relying on system cron files or external schedulers.

---

## Features

* Cron-based scheduling (`* * * * *` syntax)
* Central scheduler loop (`schedule:ticker`)
* Queue-backed execution (no direct execution in the ticker)
* **Automatic backlog prevention** (latest run wins)
* Persistent scheduler state (prevents duplicate runs)
* Works with any queue driver
* No marker interfaces, no job annotations
* Fully deterministic and testable

---

## 📥 Installation

```bash
composer require nixphp/schedule
````

The plugin will be autoloaded automatically.

---

## Basic idea

The scheduler **does not execute jobs directly**.

Instead:

1. The scheduler *decides* which jobs are due
2. Due jobs are **queued**
3. Queue workers execute them asynchronously

This keeps scheduling and execution cleanly separated.

---

## Defining scheduled jobs

A scheduled job is a normal queue job that additionally implements
`ScheduledJobInterface`:

```php
use NixPHP\Schedule\Core\ScheduledJobInterface;
use NixPHP\Queue\QueueJobInterface;

class CleanupTempFiles implements ScheduledJobInterface, QueueJobInterface
{
    public function getCronExpression(): string
    {
        return '0 * * * *'; // every hour
    }

    public function execute(): void
    {
        // cleanup logic
    }
}
```

---

## Registering scheduled jobs

Register scheduled jobs via the `Scheduler`:

```php
$scheduler->addScheduledJob(CleanupTempFiles::class);
```

Payloads can be passed as well:

```php
$scheduler->addScheduledJob(CleanupTempFiles::class, [
    'path' => '/tmp'
]);
```

---

## Running the scheduler

Start the scheduler ticker via CLI:

```bash
./bin/nix schedule:ticker
```

The ticker:

* checks all registered jobs
* evaluates cron expressions
* queues jobs that are due

It runs continuously and is usually managed by Supervisor or systemd.

---

## Backlog handling (important)

### The problem

If the scheduler runs but queue workers are down, scheduled jobs can pile up.
In most cases this is **not desired**:
polling, syncing, rebuilding, or checking tasks usually only need to run *once* with the latest state.

### The solution: automatic coalescing

By default, the scheduler **coalesces scheduled jobs**:

> If a job was scheduled multiple times while workers were unavailable,
> only the **latest execution** is kept in the queue.

This prevents useless backlogs and keeps execution predictable.

### How it works (internally)

The scheduler assigns a **deterministic job ID** based on:

* job class
* cron expression

This causes older queued runs to be replaced automatically.

No special configuration is required.
Queue drivers remain fully generic.

---

## Configuration

You can control this behavior via configuration:

```php
// app/config.php
return [
    'queue' => [
        // If true (default): only the latest scheduled job is kept
        // If false: every scheduled run is queued and processed
        'coalesce' => true,
    ],
];
```

### When to disable coalescing

Set `coalesce` to `false` if:

* every scheduled run must be processed (e.g. audits, snapshots)
* time-based effects must not be skipped
* backlog processing is intentional

---

## Duplicate execution prevention

The scheduler also tracks its own state internally to ensure that:

* a job is only queued **once per cron minute**
* restarts do not cause duplicate enqueues

State is stored in a small JSON file (configurable).

---

## Worker interaction

The scheduler **does not spawn queue workers by default**.

You are expected to run queue workers independently, e.g. via Supervisor:

```bash
./bin/nix queue:worker
```

This keeps the system flexible and avoids hidden background processes.

---

## Supervisor example (optional)

```ini
[program:nixphp-scheduler]
command=php bin/nix schedule:ticker
directory=/path/to/your/app
autostart=true
autorestart=true
stderr_logfile=/var/log/nixphp/scheduler.err.log
stdout_logfile=/var/log/nixphp/scheduler.out.log
```

---

## Requirements

* `nixphp/framework` ^0.1.0
* `nixphp/queue` ^0.1.0
* `nixphp/cli` ^0.1.0

---

## 📄 License

MIT License.