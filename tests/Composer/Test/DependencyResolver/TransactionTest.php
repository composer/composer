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

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Transaction;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Test\TestCase;

class TransactionTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function testTransactionGenerationAndSorting(): void
    {
        $presentPackages = [
            $packageA = $this->getPackage('a/a', 'dev-master'),
            $packageAalias = $this->getAliasPackage($packageA, '1.0.x-dev'),
            $packageB = $this->getPackage('b/b', '1.0.0'),
            $packageE = $this->getPackage('e/e', 'dev-foo'),
            $packageEalias = $this->getAliasPackage($packageE, '1.0.x-dev'),
            $packageC = $this->getPackage('c/c', '1.0.0'),
        ];
        $resultPackages = [
            $packageA,
            $packageAalias,
            $packageBnew = $this->getPackage('b/b', '2.1.3'),
            $packageD = $this->getPackage('d/d', '1.2.3'),
            $packageF = $this->getPackage('f/f', '1.0.0'),
            $packageFalias1 = $this->getAliasPackage($packageF, 'dev-foo'),
            $packageG = $this->getPackage('g/g', '1.0.0'),
            $packageA0first = $this->getPackage('a0/first', '1.2.3'),
            $packageFalias2 = $this->getAliasPackage($packageF, 'dev-bar'),
            $plugin = $this->getPackage('x/plugin', '1.0.0'),
            $plugin2Dep = $this->getPackage('x/plugin2-dep', '1.0.0'),
            $plugin2 = $this->getPackage('x/plugin2', '1.0.0'),
            $dlModifyingPlugin = $this->getPackage('x/downloads-modifying', '1.0.0'),
            $dlModifyingPlugin2Dep = $this->getPackage('x/downloads-modifying2-dep', '1.0.0'),
            $dlModifyingPlugin2 = $this->getPackage('x/downloads-modifying2', '1.0.0'),
        ];

        $plugin->setType('composer-installer');
        foreach ([$plugin2, $dlModifyingPlugin, $dlModifyingPlugin2] as $pluginPackage) {
            $pluginPackage->setType('composer-plugin');
        }

        $plugin2->setRequires([
            'x/plugin2-dep' => new Link('x/plugin2', 'x/plugin2-dep', $this->getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE),
        ]);
        $dlModifyingPlugin2->setRequires([
            'x/downloads-modifying2-dep' => new Link('x/downloads-modifying2', 'x/downloads-modifying2-dep', $this->getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE),
        ]);
        $dlModifyingPlugin->setExtra(['plugin-modifies-downloads' => true]);
        $dlModifyingPlugin2->setExtra(['plugin-modifies-downloads' => true]);

        $packageD->setRequires([
            'f/f' => new Link('d/d', 'f/f', $this->getVersionConstraint('>', '0.2'), Link::TYPE_REQUIRE),
            'g/provider' => new Link('d/d', 'g/provider', $this->getVersionConstraint('>', '0.2'), Link::TYPE_REQUIRE),
        ]);
        $packageG->setProvides(['g/provider' => new Link('g/g', 'g/provider', $this->getVersionConstraint('==', '1.0.0'), Link::TYPE_PROVIDE)]);

        $expectedOperations = [
            ['job' => 'uninstall', 'package' => $packageC],
            ['job' => 'uninstall', 'package' => $packageE],
            ['job' => 'markAliasUninstalled', 'package' => $packageEalias],
            ['job' => 'install', 'package' => $dlModifyingPlugin],
            ['job' => 'install', 'package' => $dlModifyingPlugin2Dep],
            ['job' => 'install', 'package' => $dlModifyingPlugin2],
            ['job' => 'install', 'package' => $plugin],
            ['job' => 'install', 'package' => $plugin2Dep],
            ['job' => 'install', 'package' => $plugin2],
            ['job' => 'install', 'package' => $packageA0first],
            ['job' => 'update', 'from' => $packageB, 'to' => $packageBnew],
            ['job' => 'install', 'package' => $packageG],
            ['job' => 'install', 'package' => $packageF],
            ['job' => 'markAliasInstalled', 'package' => $packageFalias2],
            ['job' => 'markAliasInstalled', 'package' => $packageFalias1],
            ['job' => 'install', 'package' => $packageD],
        ];

        $transaction = new Transaction($presentPackages, $resultPackages);
        $this->checkTransactionOperations($transaction, $expectedOperations);
    }

    /**
     * @param array<array{job: string, package?: PackageInterface, from?: PackageInterface, to?: PackageInterface}> $expected
     */
    protected function checkTransactionOperations(Transaction $transaction, array $expected): void
    {
        $result = [];
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation) {
                $result[] = [
                    'job' => 'update',
                    'from' => $operation->getInitialPackage(),
                    'to' => $operation->getTargetPackage(),
                ];
            } elseif ($operation instanceof InstallOperation || $operation instanceof UninstallOperation || $operation instanceof MarkAliasInstalledOperation || $operation instanceof MarkAliasUninstalledOperation) {
                $result[] = [
                    'job' => $operation->getOperationType(),
                    'package' => $operation->getPackage(),
                ];
            } else {
                throw new \UnexpectedValueException('Unknown operation type: '.get_class($operation));
            }
        }

        $this->assertEquals($expected, $result);
    }
}
