<?php

/**
 * Minimal pcntl / POSIX stubs so Laravel Horizon can run on Windows.
 * Signal handling is a no-op; workers still spawn via Symfony Process.
 */
if (PHP_OS_FAMILY !== 'Windows') {
    return;
}

$signals = [
    'SIGHUP' => 1,
    'SIGINT' => 2,
    'SIGQUIT' => 3,
    'SIGILL' => 4,
    'SIGTRAP' => 5,
    'SIGABRT' => 6,
    'SIGBUS' => 7,
    'SIGFPE' => 8,
    'SIGKILL' => 9,
    'SIGUSR1' => 10,
    'SIGSEGV' => 11,
    'SIGUSR2' => 12,
    'SIGPIPE' => 13,
    'SIGALRM' => 14,
    'SIGTERM' => 15,
    'SIGSTKFLT' => 16,
    'SIGCHLD' => 17,
    'SIGCONT' => 18,
    'SIGSTOP' => 19,
    'SIGTSTP' => 20,
    'SIGTTIN' => 21,
    'SIGTTOU' => 22,
];

foreach ($signals as $name => $value) {
    if (! defined($name)) {
        define($name, $value);
    }
}

if (! function_exists('pcntl_async_signals')) {
    function pcntl_async_signals(bool $enable = true): bool
    {
        return true;
    }
}

if (! function_exists('pcntl_signal')) {
    function pcntl_signal(int $signal, callable|int $handler, bool $restart_syscalls = true): bool
    {
        return true;
    }
}

if (! function_exists('pcntl_signal_dispatch')) {
    function pcntl_signal_dispatch(): bool
    {
        return true;
    }
}

if (! function_exists('posix_getpid')) {
    function posix_getpid(): int
    {
        return getmypid() ?: 0;
    }
}

if (! function_exists('posix_getppid')) {
    function posix_getppid(): int
    {
        // Keep Horizon workers from thinking their parent died (orphan check).
        return 2;
    }
}

if (! function_exists('posix_kill')) {
    function posix_kill(int $process_id, int $signal): bool
    {
        if ($process_id <= 0) {
            return false;
        }

        // Best-effort terminate on Windows for horizon:terminate / pause.
        if (in_array($signal, [SIGTERM, SIGINT, SIGKILL, SIGQUIT], true)) {
            exec('taskkill /PID '.((int) $process_id).' /F 2>NUL', $output, $code);

            return $code === 0;
        }

        return true;
    }
}

if (! function_exists('posix_get_last_error')) {
    function posix_get_last_error(): int
    {
        return 0;
    }
}

if (! function_exists('posix_strerror')) {
    function posix_strerror(int $error_code): string
    {
        return 'Unknown error';
    }
}
