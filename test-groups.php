<?php

/**
 * Grouped test runner for Laravel / PHPUnit / Pest projects.
 *
 * Runs your test suite one group (sub-folder or test file) at a time, each in
 * its own child process. Memory stays flat on big suites and a crash in one
 * group can't take the rest down.
 *
 * Usage (run from your project root):
 *   php test-groups.php                    run every discovered group
 *   php test-groups.php --list             dry run: show what would run
 *   php test-groups.php --fail-fast        stop after the first failing group
 *   php test-groups.php --only=Admin,Auth  groups whose path contains any of these
 *   php test-groups.php --only=tests/Feature/Admin/FooTest.php
 *                                          one specific file or nested folder
 *   php test-groups.php --skip=Payment     leave out matching groups
 *   php test-groups.php --all              ignore skip lists (config and --skip)
 *   php test-groups.php --roots=tests/Unit,tests/Feature
 *   php test-groups.php --cmd="vendor/bin/pest"
 *   php test-groups.php --help
 *
 * Optional config file `test-groups.json` in the project root:
 *   {
 *     "roots": ["tests/Unit", "tests/Feature"],
 *     "skip":  ["tests/Feature/Payment"],
 *     "command": "php artisan test"
 *   }
 * Command-line flags override the config file.
 *
 * Exit codes: 0 all passed, 1 at least one group failed, 2 nothing matched.
 * Failure details are also saved to test-groups-<timestamp>.log (or
 * storage/logs/ when that folder exists).
 */

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

// Everything is resolved relative to the folder you run the script from.
$root = getcwd();

// Composer's autoloader provides Symfony Process (already present in Laravel
// and most PHPUnit projects). We do NOT boot any framework in this process.
if (! is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php not found. Run this from your project root after `composer install`.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Options: defaults < test-groups.json < command line
// ---------------------------------------------------------------------------

// Supported flags. A trailing colon means the flag takes a value.
$opts = getopt('', ['all', 'only:', 'skip:', 'roots:', 'cmd:', 'fail-fast', 'list', 'help']);

// --help: print the usage block from the docblock above, so the help text and
// the documentation can never drift apart.
if (isset($opts['help'])) {
    preg_match('/\/\*\*(.*?)\*\//s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^\s*\* ?/m', '', trim($m[1] ?? '')) . "\n";
    exit(0);
}

/** Split "a, b,c" into ['a', 'b', 'c'] (trimmed, empty entries dropped). */
$csv = fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v))));

// Optional project config.
$config = [];
if (is_file($root . '/test-groups.json')) {
    $config = json_decode((string) file_get_contents($root . '/test-groups.json'), true);
    if (! is_array($config)) {
        fwrite(STDERR, "test-groups.json is not valid JSON.\n");
        exit(2);
    }
}

// Directories whose sub-folders and *Test.php files become groups.
$roots = isset($opts['roots']) ? $csv($opts['roots']) : ($config['roots'] ?? ['tests/Unit', 'tests/Feature']);

// Groups left out of a default run (substring match on the path).
$skip = isset($opts['skip']) ? $csv($opts['skip']) : ($config['skip'] ?? []);

$includeSkipped = isset($opts['all']);   // run the skipped groups anyway
$failFast = isset($opts['fail-fast']);   // stop after the first failing group
$only = isset($opts['only']) ? $csv($opts['only']) : [];

// The command that runs one group. Default: artisan test, else pest, else phpunit.
$php = (new PhpExecutableFinder())->find() ?: PHP_BINARY;
$cmdString = $opts['cmd'] ?? $config['command'] ?? null;
if ($cmdString !== null) {
    $baseCommand = preg_split('/\s+/', trim($cmdString));
    if ($baseCommand[0] === 'php') {
        $baseCommand[0] = $php;          // same PHP that launched this script
    }
} elseif (is_file($root . '/artisan')) {
    $baseCommand = [$php, 'artisan', 'test'];
} elseif (is_file($root . '/vendor/bin/pest')) {
    $baseCommand = [$php, 'vendor/bin/pest'];
} else {
    $baseCommand = [$php, 'vendor/bin/phpunit'];
}

// --only entries that are real paths (a single test file, or any nested folder)
// run directly; everything else is a substring filter over discovered groups.
$direct = [];
$needles = [];
foreach ($only as $item) {
    // Normalise: strip a leading "./" and any trailing slash.
    $path = rtrim(preg_replace('#^\./#', '', $item), '/');
    if ($path !== '' && file_exists($root . '/' . $path)) {
        $direct[] = $path;
    } else {
        $needles[] = $item;
    }
}

// ---------------------------------------------------------------------------
// Discover groups (so new test folders are never silently left out)
// ---------------------------------------------------------------------------

// A "group" is one path handed to the test command: either a sub-folder or a
// single *Test.php file sitting directly in a root.
$groups = [];

foreach ($roots as $dir) {
    $dir = rtrim($dir, '/');

    if (! is_dir($root . '/' . $dir)) {
        continue;
    }

    $before = count($groups);

    foreach (scandir($root . '/' . $dir) as $entry) {
        // Skip ".", ".." and hidden files such as .DS_Store.
        if ($entry[0] === '.') {
            continue;
        }

        $path = "{$dir}/{$entry}";

        if (is_dir($root . '/' . $path)) {
            $groups[] = $path;                       // sub-folder: one group each
        } elseif (str_ends_with($entry, 'Test.php')) {
            $groups[] = $path;                       // loose test file: its own group
        }
    }

    // Fallback: a root with no sub-folders and no tests of its own is run as a
    // whole, so nothing under it is ever silently ignored.
    if (count($groups) === $before) {
        $groups[] = $dir;
    }
}

// Stable, alphabetical order so runs are comparable between machines.
sort($groups);

/** True if $path contains any of the substrings in $needles. */
$matches = function (string $path, array $needles): bool {
    foreach ($needles as $needle) {
        if (stripos($path, $needle) !== false) {
            return true;
        }
    }

    return false;
};

// Filled below so the summary can report what was left out.
$skippedGroups = [];

// Decide which discovered groups actually run.
$groups = array_values(array_filter($groups, function ($g) use ($matches, $skip, $includeSkipped, $only, $needles, &$skippedGroups) {
    // --only given: keep groups matching a needle (this ignores the skip list,
    // so --only=Payment works without --all).
    if ($only) {
        return $matches($g, $needles);
    }

    // Default run: drop anything on the skip list and remember it for the summary.
    if (! $includeSkipped && $skip && $matches($g, $skip)) {
        $skippedGroups[] = $g;

        return false;
    }

    return true;
}));

// Add the explicit paths from --only (single files, nested folders). They need
// not be discovered groups, and array_unique avoids running one twice.
if ($only) {
    $groups = array_values(array_unique(array_merge($direct, $groups)));
}

// Nothing to run (typo in --only, empty tests folder...): exit 2 = usage error,
// distinct from exit 1 = tests failed.
if (! $groups) {
    fwrite(STDERR, "No test groups matched.\n");
    exit(2);
}

// --list: dry run, show the plan and stop.
if (isset($opts['list'])) {
    echo "Would run:\n", implode("\n", array_map(fn ($g) => "  {$g}", $groups)), "\n";
    if ($skippedGroups) {
        echo "\nSkipped (use --all):\n", implode("\n", array_map(fn ($g) => "  {$g}", $skippedGroups)), "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

// Timestamped so repeated runs never overwrite each other's failure logs.
$logDir = is_dir($root . '/storage/logs') ? $root . '/storage/logs' : $root;
$logFile = $logDir . '/test-groups-' . date('Ymd-His') . '.log';

$results = [];   // group => ['ok' => bool, 'seconds' => float, 'output' => string]
$startedAt = microtime(true);
$total = count($groups);

// One child process per group keeps memory flat and isolates crashes.
foreach ($groups as $i => $group) {
    $n = $i + 1;
    echo str_repeat('=', 60) . "\n";
    echo "[{$n}/{$total}] {$group}\n";
    echo str_repeat('=', 60) . "\n";

    $t = microtime(true);

    // Array form (no shell), run from the project root.
    $process = new Process([...$baseCommand, $group], $root);
    $process->setTimeout(null);   // Symfony's default is 60s, which would kill slow groups
    $process->run();              // blocks until the group finishes

    $seconds = microtime(true) - $t;
    $ok = $process->isSuccessful();

    // Test runners print failure details to stdout; keep stderr too for fatals.
    $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

    $results[$group] = ['ok' => $ok, 'seconds' => $seconds, 'output' => $output];

    printf("%s %s (%.1fs)\n\n", $ok ? '✅ PASSED' : '❌ FAILED', $group, $seconds);

    // --fail-fast: abort the remaining groups; the summary reports them as "not run".
    if (! $ok && $failFast) {
        echo "Stopping early (--fail-fast).\n\n";
        break;
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

// Collect failures and total wall-clock time for the summary.
$failed = array_filter($results, fn ($r) => ! $r['ok']);
$elapsed = microtime(true) - $startedAt;

echo "\n" . str_repeat('=', 60) . "\n";
echo "TEST RUN SUMMARY\n";
echo str_repeat('=', 60) . "\n";

// Pad group names to the longest one so the timing column lines up.
$width = max(array_map('strlen', array_keys($results)));
foreach ($results as $group => $r) {
    printf("%s  %-{$width}s  %6.1fs\n", $r['ok'] ? '✅' : '❌', $group, $r['seconds']);
}

if ($skippedGroups) {
    echo "\nSkipped: " . implode(', ', $skippedGroups) . "  (use --all to include)\n";
}

// Groups not run because of --fail-fast.
$notRun = $total - count($results);
printf(
    "\n%d passed, %d failed%s in %.1fs\n",
    count($results) - count($failed),
    count($failed),
    $notRun ? ", {$notRun} not run" : '',
    $elapsed,
);

// Failures: print the details and also save them to a log file, because the
// terminal scrollback is easy to lose. Exit code 1 lets CI/scripts detect it.
if ($failed) {
    $log = '';
    foreach ($failed as $group => $r) {
        $log .= "--- [ {$group} ] ---\n{$r['output']}\n\n";
    }

    echo "\n" . $log;

    // @ is intentional: failing to write the log must not hide the test results.
    if (@file_put_contents($logFile, $log) !== false) {
        echo 'Full failure log: ' . str_replace($root . '/', '', $logFile) . "\n";
    }

    exit(1);
}

// Success: exit code 0.
echo "🎉 All groups passed successfully!\n";
exit(0);
