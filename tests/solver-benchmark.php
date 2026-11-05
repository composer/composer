<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Usage: php tests/solver-benchmark.php [--baseline=results.json] [--threshold=15] [scenario ...]
 *
 * Prints the results as JSON on stdout. When a baseline is given, exits with
 * code 1 if rule counts or peak memory grew by more than the threshold percentage.
 */

use Composer\Pcre\Preg;
use Composer\Test\Benchmark\SolverBenchmark;

// This is a standalone CLI script; do nothing (and never exit) when it is merely
// included from another script, e.g. a test runner scanning the tests directory.
if (PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

require __DIR__.'/../vendor/autoload.php';

ini_set('memory_limit', '-1');

// $argv is only defined in the global scope of the entry script, read the
// arguments from $_SERVER so they are available regardless of scope.
$args = $_SERVER['argv'] ?? [];

$baselineFile = null;
$threshold = 15.0;
$scenarios = [];
foreach (array_slice($args, 1) as $arg) {
    if (Preg::isMatchStrictGroups('{^--baseline=(.*)$}', $arg, $match)) {
        $baselineFile = $match[1];
        if ($baselineFile === '') {
            fwrite(STDERR, 'The --baseline option requires a file path'.PHP_EOL);
            exit(2);
        }
    } elseif (strpos($arg, '--threshold=') === 0) {
        $threshold = (float) substr($arg, strlen('--threshold='));
    } elseif (isset(SolverBenchmark::SCENARIOS[$arg])) {
        $scenarios[] = $arg;
    } else {
        fwrite(STDERR, 'Unknown argument "'.$arg.'", available scenarios: '.implode(', ', array_keys(SolverBenchmark::SCENARIOS)).PHP_EOL);
        exit(2);
    }
}

if (count($scenarios) === 0) {
    $scenarios = array_keys(SolverBenchmark::SCENARIOS);
}

$results = [];
foreach ($scenarios as $scenario) {
    $results[$scenario] = SolverBenchmark::run(SolverBenchmark::SCENARIOS[$scenario]);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if ($baselineFile === null) {
    exit(0);
}

$baselineJson = file_get_contents($baselineFile);
$baseline = is_string($baselineJson) ? json_decode($baselineJson, true) : null;
if (!is_array($baseline)) {
    fwrite(STDERR, 'Could not read baseline results from '.$baselineFile.PHP_EOL);
    exit(2);
}

$regressions = SolverBenchmark::findRegressions($baseline, $results, $threshold);
foreach ($regressions as $regression) {
    fwrite(STDERR, $regression.PHP_EOL);
}

exit(count($regressions) > 0 ? 1 : 0);
