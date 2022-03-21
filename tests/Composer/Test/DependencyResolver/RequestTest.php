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

use Composer\DependencyResolver\Request;
use Composer\Repository\ArrayRepository;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class RequestTest extends TestCase
{
    public function testRequestInstall(): void
    {
        $repo = new ArrayRepository;
        $foo = $this->getPackage('foo', '1');
        $bar = $this->getPackage('bar', '1');
        $foobar = $this->getPackage('foobar', '1');

        $repo->addPackage($foo);
        $repo->addPackage($bar);
        $repo->addPackage($foobar);

        $request = new Request();
        $request->requireName('foo');

        $this->assertEquals(
            [
                'foo' => new MatchAllConstraint(),
            ],
            $request->getRequires()
        );
    }

    public function testRequestInstallSamePackageFromDifferentRepositories(): void
    {
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $foo1 = $this->getPackage('foo', '1');
        $foo2 = $this->getPackage('foo', '1');

        $repo1->addPackage($foo1);
        $repo2->addPackage($foo2);

        $request = new Request();
        $request->requireName('foo', $constraint = $this->getVersionConstraint('=', '1'));

        $this->assertEquals(
            [
                'foo' => $constraint,
            ],
            $request->getRequires()
        );
    }
}
