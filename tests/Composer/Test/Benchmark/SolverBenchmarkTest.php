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
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Solver;
use Composer\IO\NullIO;
use Composer\Test\TestCase;

class SolverBenchmarkTest extends TestCase
{
    private const TINY = ['packages' => 6, 'versions' => 3, 'requires' => 2, 'conflicts' => 1, 'roots' => 2];

    public function testScenariosDefineAllRequiredKeys(): void
    {
        self::assertNotEmpty(SolverBenchmark::SCENARIOS);
        foreach (SolverBenchmark::SCENARIOS as $name => $config) {
            foreach (['packages', 'versions', 'requires', 'conflicts', 'roots'] as $key) {
                self::assertArrayHasKey($key, $config, $name.' is missing '.$key);
                self::assertIsInt($config[$key]);
                self::assertGreaterThanOrEqual(0, $config[$key]);
            }
            self::assertGreaterThan(0, $config['packages']);
            self::assertGreaterThan(0, $config['versions']);
        }
    }

    public function testComparedMetricsExcludeTime(): void
    {
        self::assertSame(['rules', 'peak_memory'], SolverBenchmark::COMPARED_METRICS);
        self::assertNotContains('time', SolverBenchmark::COMPARED_METRICS);
    }

    public function testCreateScenarioAddsEveryVersionOfEveryPackage(): void
    {
        [$repoSet] = SolverBenchmark::createScenario(self::TINY);

        for ($i = 0; $i < self::TINY['packages']; $i++) {
            $packages = $repoSet->findPackages('bench/package-'.$i);
            self::assertCount(self::TINY['versions'], $packages);

            $versions = array_map(static function ($package): string {
                return $package->getPrettyVersion();
            }, $packages);
            sort($versions);
            self::assertSame(['1.0.0', '1.1.0', '1.2.0'], $versions);
        }

        self::assertSame([], $repoSet->findPackages('bench/package-'.self::TINY['packages']));
    }

    public function testCreateScenarioRequestsRootPackages(): void
    {
        [, $request] = SolverBenchmark::createScenario(self::TINY);

        self::assertSame(['bench/package-0', 'bench/package-1'], array_keys($request->getRequires()));
    }

    public function testCreateScenarioCapsRootsAtPackageCount(): void
    {
        [, $request] = SolverBenchmark::createScenario(['packages' => 3, 'versions' => 1, 'requires' => 1, 'conflicts' => 0, 'roots' => 10]);

        self::assertSame(['bench/package-0', 'bench/package-1', 'bench/package-2'], array_keys($request->getRequires()));
    }

    public function testCreateScenarioOnlyLinksToLaterPackagesWithBoundedConstraints(): void
    {
        [$repoSet] = SolverBenchmark::createScenario(self::TINY);

        for ($i = 0; $i < self::TINY['packages']; $i++) {
            foreach ($repoSet->findPackages('bench/package-'.$i) as $package) {
                foreach ($package->getRequires() as $target => $link) {
                    self::assertGreaterThan($i, (int) substr($target, strlen('bench/package-')));
                    self::assertStringStartsWith('>=1.', $link->getPrettyConstraint());
                }
                foreach ($package->getConflicts() as $target => $link) {
                    self::assertGreaterThan($i, (int) substr($target, strlen('bench/package-')));
                    self::assertStringStartsWith('<1.', $link->getPrettyConstraint());
                }
            }
        }
    }

    public function testCreateScenarioLastPackageHasNoLinks(): void
    {
        [$repoSet] = SolverBenchmark::createScenario(self::TINY);

        foreach ($repoSet->findPackages('bench/package-'.(self::TINY['packages'] - 1)) as $package) {
            self::assertSame([], $package->getRequires());
            self::assertSame([], $package->getConflicts());
        }
    }

    public function testCreateScenarioWithoutConflictsHasNoConflictLinks(): void
    {
        [$repoSet] = SolverBenchmark::createScenario(['conflicts' => 0] + self::TINY);

        for ($i = 0; $i < self::TINY['packages']; $i++) {
            foreach ($repoSet->findPackages('bench/package-'.$i) as $package) {
                self::assertSame([], $package->getConflicts());
            }
        }
    }

    public function testCreateScenarioIsDeterministic(): void
    {
        [$repoSetA] = SolverBenchmark::createScenario(self::TINY);
        [$repoSetB] = SolverBenchmark::createScenario(self::TINY);

        for ($i = 0; $i < self::TINY['packages']; $i++) {
            $describe = static function (array $packages): array {
                $result = [];
                foreach ($packages as $package) {
                    $links = [];
                    foreach (array_merge($package->getRequires(), $package->getConflicts()) as $link) {
                        $links[] = $link->getDescription().' '.$link->getTarget().' '.$link->getPrettyConstraint();
                    }
                    $result[$package->getPrettyVersion()] = $links;
                }
                ksort($result);

                return $result;
            };

            self::assertSame(
                $describe($repoSetA->findPackages('bench/package-'.$i)),
                $describe($repoSetB->findPackages('bench/package-'.$i))
            );
        }
    }

    public function testScenarioIsSolvedByInstallingLatestVersions(): void
    {
        $config = ['packages' => 20, 'versions' => 5, 'requires' => 3, 'conflicts' => 3, 'roots' => 4];
        [$repoSet, $request] = SolverBenchmark::createScenario($config);

        $io = new NullIO();
        $policy = new DefaultPolicy();
        $pool = $repoSet->createPool($request, $io, null, new PoolOptimizer($policy));
        $solver = new Solver($policy, $pool, $io);
        $transaction = $solver->solve($request);

        $operations = $transaction->getOperations();
        self::assertNotEmpty($operations);
        foreach ($operations as $operation) {
            self::assertInstanceOf(InstallOperation::class, $operation);
            self::assertSame('1.4.0', $operation->getPackage()->getPrettyVersion());
        }
    }

    public function testRunReturnsMetrics(): void
    {
        $result = SolverBenchmark::run(self::TINY);

        self::assertSame(['pool_packages', 'rules', 'operations', 'time', 'peak_memory'], array_keys($result));
        self::assertGreaterThan(0, $result['pool_packages']);
        self::assertGreaterThan(0, $result['rules']);
        self::assertGreaterThanOrEqual(self::TINY['roots'], $result['operations']);
        self::assertLessThanOrEqual(self::TINY['packages'], $result['operations']);
        self::assertIsFloat($result['time']);
        self::assertGreaterThanOrEqual(0, $result['time']);
        self::assertIsInt($result['peak_memory']);
        self::assertGreaterThanOrEqual(0, $result['peak_memory']);
    }

    public function testRunIsDeterministicForStructuralMetrics(): void
    {
        $first = SolverBenchmark::run(self::TINY);
        $second = SolverBenchmark::run(self::TINY);

        self::assertSame($first['pool_packages'], $second['pool_packages']);
        self::assertSame($first['rules'], $second['rules']);
        self::assertSame($first['operations'], $second['operations']);
    }

    public function testRunSmallScenario(): void
    {
        $result = SolverBenchmark::run(SolverBenchmark::SCENARIOS['small']);

        self::assertGreaterThanOrEqual(SolverBenchmark::SCENARIOS['small']['roots'], $result['operations']);
        self::assertLessThanOrEqual(SolverBenchmark::SCENARIOS['small']['packages'], $result['operations']);
        self::assertGreaterThan(0, $result['rules']);
    }

    public function testFindRegressionsReportsGrowthAboveThreshold(): void
    {
        $regressions = SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000]],
            ['small' => ['rules' => 120, 'peak_memory' => 1000]],
            15.0
        );

        self::assertSame(['small: rules grew from 100 to 120 (+20.0%, threshold 15.0%)'], $regressions);
    }

    public function testFindRegressionsReportsEveryComparedMetric(): void
    {
        $regressions = SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000], 'large' => ['rules' => 10, 'peak_memory' => 1000]],
            ['small' => ['rules' => 200, 'peak_memory' => 1500], 'large' => ['rules' => 20, 'peak_memory' => 1000]],
            10.0
        );

        self::assertSame([
            'small: rules grew from 100 to 200 (+100.0%, threshold 10.0%)',
            'small: peak_memory grew from 1000 to 1500 (+50.0%, threshold 10.0%)',
            'large: rules grew from 10 to 20 (+100.0%, threshold 10.0%)',
        ], $regressions);
    }

    public function testFindRegressionsIgnoresGrowthAtOrBelowThreshold(): void
    {
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000]],
            ['small' => ['rules' => 115, 'peak_memory' => 1100]],
            15.0
        ));
    }

    public function testFindRegressionsIgnoresImprovements(): void
    {
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000]],
            ['small' => ['rules' => 50, 'peak_memory' => 10]],
            0.0
        ));
    }

    public function testFindRegressionsIgnoresTimeAndOtherMetrics(): void
    {
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000, 'time' => 0.1, 'operations' => 5, 'pool_packages' => 10]],
            ['small' => ['rules' => 100, 'peak_memory' => 1000, 'time' => 10.0, 'operations' => 50, 'pool_packages' => 100]],
            15.0
        ));
    }

    public function testFindRegressionsIgnoresScenariosMissingFromEitherSide(): void
    {
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000]],
            ['large' => ['rules' => 1000, 'peak_memory' => 100000]],
            15.0
        ));
        self::assertSame([], SolverBenchmark::findRegressions(
            [],
            ['small' => ['rules' => 1000, 'peak_memory' => 100000]],
            15.0
        ));
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100, 'peak_memory' => 1000]],
            [],
            15.0
        ));
    }

    public function testFindRegressionsIgnoresMetricsMissingFromEitherSide(): void
    {
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 100]],
            ['small' => ['peak_memory' => 100000]],
            15.0
        ));

        self::assertSame(
            ['small: rules grew from 100 to 200 (+100.0%, threshold 15.0%)'],
            SolverBenchmark::findRegressions(
                ['small' => ['rules' => 100]],
                ['small' => ['rules' => 200, 'peak_memory' => 100000]],
                15.0
            )
        );
    }

    public function testFindRegressionsIgnoresNonPositiveBaseline(): void
    {
        self::assertSame([], SolverBenchmark::findRegressions(
            ['small' => ['rules' => 0, 'peak_memory' => -5]],
            ['small' => ['rules' => 100, 'peak_memory' => 1000]],
            15.0
        ));
    }

    public function testFindRegressionsSupportsZeroThreshold(): void
    {
        self::assertSame(
            ['small: peak_memory grew from 1000 to 1001 (+0.1%, threshold 0.0%)'],
            SolverBenchmark::findRegressions(
                ['small' => ['rules' => 100, 'peak_memory' => 1000]],
                ['small' => ['rules' => 100, 'peak_memory' => 1001]],
                0.0
            )
        );
    }
}
