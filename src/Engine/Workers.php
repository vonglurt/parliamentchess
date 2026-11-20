<?php
declare(strict_types=1);

namespace Parliament\Engine;

/**
 * One Orrery lane, every core: a job that is one PHP process uses 1 of a machine's 8–24 threads,
 * so label and gauntlet jobs fan out a worker per core and merge what they made.
 */
final class Workers
{
    /** The machine's hardware threads (nproc, or sysctl on macOS). */
    public static function cores(): int
    {
        $n = (int)trim((string)@shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null'));
        return max(1, $n);
    }

    /**
     * Run $script once per worker with its own arguments ($argsFor(i) => string[]), all at once,
     * through bin/php (JIT where the host has it). Returns each worker's exit code.
     */
    public static function run(string $script, int $n, callable $argsFor, string $logDir): array
    {
        $root = dirname(__DIR__, 2);
        $procs = [];
        @mkdir($logDir, 0775, true);
        for ($i = 0; $i < $n; $i++) {
            $cmd = array_merge(['sh', "$root/bin/php", $script], $argsFor($i));
            $log = "$logDir/worker-$i.log";
            $procs[$i] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        }
        $codes = [];
        foreach ($procs as $i => $p) {
            $codes[$i] = proc_close($p);
        }
        return $codes;
    }
}
