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

namespace Composer\Test\Benchmark;

use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;

/**
 * Runs the dependency resolver on generated, deterministic package pools and
 * reports wall time, peak memory and rule counts so changes can be compared.
 *
 * Every scenario is solvable by installing the latest version of each package,
 * as requirements only use lower bounds and conflicts only use upper bounds
 * below the latest version.
 */
class SolverBenchmark
{
    /**
     * @var array<string, array{packages: int, versions: int, requires: int, conflicts: int, roots: int}>
     */
    public const SCENARIOS = [
        'small' => ['packages' => 50, 'versions' => 10, 'requires' => 3, 'conflicts' => 0, 'roots' => 5],
        'medium' => ['packages' => 150, 'versions' => 20, 'requires' => 4, 'conflicts' => 1, 'roots' => 15],
        'large' => ['packages' => 300, 'versions' => 30, 'requires' => 5, 'conflicts' => 1, 'roots' => 30],
        'conflict-heavy' => ['packages' => 150, 'versions' => 30, 'requires' => 3, 'conflicts' => 6, 'roots' => 15],
    ];

    /**
     * Metrics compared against a baseline, time is excluded as it is too noisy on shared CI runners
     */
    public const COMPARED_METRICS = ['rules', 'peak_memory'];

    /**
     * @param array{packages: int, versions: int, requires: int, conflicts: int, roots: int} $config
     * @return array{RepositorySet, Request}
     */
    public static function createScenario(array $config): array
    {
        $parser = new VersionParser();
        $repo = new ArrayRepository();
        $count = $config['packages'];
        $versions = $config['versions'];

        for ($i = 0; $i < $count; $i++) {
            $name = self::getPackageName($i);
            for ($v = 0; $v < $versions; $v++) {
                $prettyVersion = '1.'.$v.'.0';
                $package = new CompletePackage($name, $parser->normalize($prettyVersion), $prettyVersion);

                $requires = [];
                $conflicts = [];
                $remaining = $count - $i - 1;
                if ($remaining > 0) {
                    for ($k = 0; $k < $config['requires']; $k++) {
                        $target = self::getPackageName($i + 1 + (($k * 31 + $v * 7) % $remaining));
                        $prettyConstraint = '>=1.'.(($v + $k) % $versions);
                        $requires[$target] = new Link($name, $target, $parser->parseConstraints($prettyConstraint), Link::TYPE_REQUIRE, $prettyConstraint);
                    }
                    for ($k = 0; $k < $config['conflicts']; $k++) {
                        $target = self::getPackageName($i + 1 + (($k * 17 + $v * 13) % $remaining));
                        $prettyConstraint = '<1.'.max(1, ($v + $k) % $versions);
                        $conflicts[$target] = new Link($name, $target, $parser->parseConstraints($prettyConstraint), Link::TYPE_CONFLICT, $prettyConstraint);
                    }
                }

                $package->setRequires($requires);
                $package->setConflicts($conflicts);
                $repo->addPackage($package);
            }
        }

        $repoSet = new RepositorySet();
        $repoSet->addRepository($repo);

        $request = new Request();
        for ($i = 0; $i < min($config['roots'], $count); $i++) {
            $request->requireName(self::getPackageName($i), new MatchAllConstraint());
        }

        return [$repoSet, $request];
    }

    /**
     * @param array{packages: int, versions: int, requires: int, conflicts: int, roots: int} $config
     * @return array{pool_packages: int, rules: int, operations: int, time: float, peak_memory: int}
     */
    public static function run(array $config): array
    {
        [$repoSet, $request] = self::createScenario($config);

        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $startMemory = memory_get_usage();
        $start = microtime(true);

        $io = new NullIO();
        $policy = new DefaultPolicy();
        $pool = $repoSet->createPool($request, $io, null, new PoolOptimizer($policy));
        $solver = new Solver($policy, $pool, $io);
        $transaction = $solver->solve($request);

        $time = microtime(true) - $start;
        $peakMemory = max(0, memory_get_peak_usage() - $startMemory);

        return [
            'pool_packages' => \count($pool),
            'rules' => $solver->getRuleSetSize(),
            'operations' => \count($transaction->getOperations()),
            'time' => round($time, 4),
            'peak_memory' => $peakMemory,
        ];
    }

    /**
     * Returns a description of every compared metric which grew by more than $thresholdPercent
     *
     * Scenarios missing from either side are ignored.
     *
     * @param array<string, array<string, int|float>> $baseline
     * @param array<string, array<string, int|float>> $current
     * @return list<string>
     */
    public static function findRegressions(array $baseline, array $current, float $thresholdPercent): array
    {
        $regressions = [];
        foreach ($current as $scenario => $metrics) {
            if (!isset($baseline[$scenario])) {
                continue;
            }

            foreach (self::COMPARED_METRICS as $metric) {
                if (!isset($metrics[$metric], $baseline[$scenario][$metric])) {
                    continue;
                }

                $before = $baseline[$scenario][$metric];
                $after = $metrics[$metric];
                if ($before <= 0) {
                    continue;
                }

                $change = ($after - $before) / $before * 100;
                if ($change > $thresholdPercent) {
                    $regressions[] = sprintf('%s: %s grew from %s to %s (+%.1f%%, threshold %.1f%%)', $scenario, $metric, $before, $after, $change, $thresholdPercent);
                }
            }
        }

        return $regressions;
    }

    private static function getPackageName(int $index): string
    {
        return 'bench/package-'.$index;
    }
}
