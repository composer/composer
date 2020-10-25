<?php

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

use Composer\DependencyResolver\Transaction;
use Composer\Package\Link;
use Composer\Test\TestCase;

class TransactionTest extends TestCase
{
    public function setUp()
    {
    }

    public function testTransactionGenerationAndSorting()
    {
        $presentPackages = array(
            $packageA = $this->getPackage('a/a', 'dev-master'),
            $packageAalias = $this->getAliasPackage($packageA, '1.0.x-dev'),
            $packageB = $this->getPackage('b/b', '1.0.0'),
            $packageE = $this->getPackage('e/e', 'dev-foo'),
            $packageEalias = $this->getAliasPackage($packageE, '1.0.x-dev'),
            $packageC = $this->getPackage('c/c', '1.0.0'),
        );
        $resultPackages = array(
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
        );

        $plugin->setType('composer-installer');
        foreach (array($plugin2, $dlModifyingPlugin, $dlModifyingPlugin2) as $pluginPackage) {
            $pluginPackage->setType('composer-plugin');
        }

        $plugin2->setRequires(array(
            'x/plugin2-dep' => new Link('x/plugin2', 'x/plugin2-dep', $this->getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE),
        ));
        $dlModifyingPlugin2->setRequires(array(
            'x/downloads-modifying2-dep' => new Link('x/downloads-modifying2', 'x/downloads-modifying2-dep', $this->getVersionConstraint('=', '1.0.0'), Link::TYPE_REQUIRE),
        ));
        $dlModifyingPlugin->setExtra(array('plugin-modifies-downloads' => true));
        $dlModifyingPlugin2->setExtra(array('plugin-modifies-downloads' => true));

        $packageD->setRequires(array(
            'f/f' => new Link('d/d', 'f/f', $this->getVersionConstraint('>', '0.2'), Link::TYPE_REQUIRE),
            'g/provider' => new Link('d/d', 'g/provider', $this->getVersionConstraint('>', '0.2'), Link::TYPE_REQUIRE),
        ));
        $packageG->setProvides(array('g/provider' => new Link('g/g', 'g/provider', $this->getVersionConstraint('==', '1.0.0'), Link::TYPE_PROVIDE)));

        $expectedOperations = array(
            array('job' => 'uninstall', 'package' => $packageC),
            array('job' => 'uninstall', 'package' => $packageE),
            array('job' => 'markAliasUninstalled', 'package' => $packageEalias),
            array('job' => 'install', 'package' => $dlModifyingPlugin),
            array('job' => 'install', 'package' => $dlModifyingPlugin2Dep),
            array('job' => 'install', 'package' => $dlModifyingPlugin2),
            array('job' => 'install', 'package' => $plugin),
            array('job' => 'install', 'package' => $plugin2Dep),
            array('job' => 'install', 'package' => $plugin2),
            array('job' => 'install', 'package' => $packageA0first),
            array('job' => 'update', 'from' => $packageB, 'to' => $packageBnew),
            array('job' => 'install', 'package' => $packageG),
            array('job' => 'install', 'package' => $packageF),
            array('job' => 'markAliasInstalled', 'package' => $packageFalias2),
            array('job' => 'markAliasInstalled', 'package' => $packageFalias1),
            array('job' => 'install', 'package' => $packageD),
        );

        $transaction = new Transaction($presentPackages, $resultPackages);
        $this->checkTransactionOperations($transaction, $expectedOperations);
    }

    protected function checkTransactionOperations(Transaction $transaction, array $expected)
    {
        $result = array();
        foreach ($transaction->getOperations() as $operation) {
            if ('update' === $operation->getOperationType()) {
                $result[] = array(
                    'job' => 'update',
                    'from' => $operation->getInitialPackage(),
                    'to' => $operation->getTargetPackage(),
                );
            } else {
                $result[] = array(
                    'job' => $operation->getOperationType(),
                    'package' => $operation->getPackage(),
                );
            }
        }

        $this->assertEquals($expected, $result);
    }
}
