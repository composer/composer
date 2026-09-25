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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\CooldownPoolFilter;
use Composer\DependencyResolver\Request;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Policy\ListPolicyConfig;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use DateTimeImmutable;
use Symfony\Component\Console\Output\OutputInterface;

class PoolBuilderCooldownOutputTest extends TestCase
{
    public function testWithheldSummaryShownAtDefaultVerbosityWithHint(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_NORMAL);
        $this->createPool($io);

        $output = $io->getOutput();
        self::assertStringContainsString('1 package version(s) withheld by the cooldown policy (run with -vv to list them).', $output);
        self::assertStringNotContainsString('vendor/pkg (2.0.0)', $output);
    }

    public function testWithheldVersionsListedAtVeryVerbose(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE);
        $this->createPool($io);

        $output = $io->getOutput();
        self::assertStringContainsString('1 package version(s) withheld by the cooldown policy:', $output);
        self::assertStringContainsString('  - vendor/pkg (2.0.0) published 2026-01-14T12:00:00+00:00', $output);
    }

    private function createPool(BufferIO $io): void
    {
        $now = new DateTimeImmutable('2026-01-15 12:00:00+00:00');

        $old = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $old->setReleaseDate(new DateTimeImmutable('2025-01-01 00:00:00+00:00'));
        $new = new Package('vendor/pkg', '2.0.0.0', '2.0.0');
        $new->setReleaseDate(new DateTimeImmutable('2026-01-14 12:00:00+00:00'));

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new ArrayRepository([$old, $new]));

        $request = new Request();
        $request->requireName('vendor/pkg', new MatchAllConstraint());

        $filter = new CooldownPoolFilter(new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 7 * 24 * 3600), $now);
        $repositorySet->createPool($request, $io, null, null, [], null, null, null, $filter);
    }
}
