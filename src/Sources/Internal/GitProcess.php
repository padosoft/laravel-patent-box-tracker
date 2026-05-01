<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources\Internal;

use RuntimeException;

/**
 * Minimal git wrapper around `proc_open()`. Used by the collectors so the
 * package does not need a hard dependency on symfony/process beyond what
 * Laravel already pulls in transitively. Captures stdout and stderr,
 * streams stdout to a string, and throws on non-zero exit codes (the exit
 * code is consumed for error wrapping but is not returned to callers).
 *
 * Internal — not part of the public API surface.
 */
final class GitProcess
{
    /**
     * Run a git command in the given working directory and return stdout.
     *
     * @param  list<string>  $args  Command arguments AFTER `git`. Each arg is passed
     *                              as a separate proc_open argument so shell quoting
     *                              is unnecessary.
     *
     * @throws RuntimeException When git exits with non-zero status, or when the
     *                          working directory is not a git repository.
     */
    public static function run(string $cwd, array $args, int $timeoutSeconds = 60): string
    {
        if (! is_dir($cwd)) {
            throw new RuntimeException(sprintf(
                'GitProcess: working directory does not exist: "%s".',
                $cwd,
            ));
        }

        $command = array_merge(['git'], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RuntimeException(sprintf(
                'GitProcess: failed to launch git in "%s" with args [%s].',
                $cwd,
                implode(' ', $args),
            ));
        }

        // We never write to stdin.
        fclose($pipes[0]);

        // Use stream_set_blocking + select so a runaway git command can be
        // bounded by $timeoutSeconds.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1_000_000);

            $changed = @stream_select($read, $write, $except, $sec, $usec);
            if ($changed === false) {
                // stream_select() returned an error. Treat it as a hard
                // failure: terminate the subprocess, drain pipes, and
                // throw with the command + cwd so the caller does not
                // hang on `proc_close()` waiting for a runaway git that
                // the OS can no longer signal.
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException(sprintf(
                    'GitProcess: stream_select() failed while running git [%s] in "%s".',
                    implode(' ', $args),
                    $cwd,
                ));
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }

            $status = proc_get_status($process);
            if (! $status['running']) {
                // Drain remaining output.
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException(sprintf(
                    'GitProcess: timed out after %d seconds running [%s] in "%s".',
                    $timeoutSeconds,
                    implode(' ', $args),
                    $cwd,
                ));
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                'GitProcess: git [%s] in "%s" exited with code %d. stderr: %s',
                implode(' ', $args),
                $cwd,
                $exitCode,
                trim($stderr),
            ));
        }

        return $stdout;
    }

    /**
     * Best-effort detection of whether a directory is a git repository.
     */
    public static function isRepository(string $cwd): bool
    {
        if (! is_dir($cwd)) {
            return false;
        }

        try {
            self::run($cwd, ['rev-parse', '--git-dir'], 5);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
