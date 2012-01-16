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

namespace Composer\Test\Repository;

use Composer\Repository\RepositoryManager;
use Composer\Repository\ArrayRepository;
use Composer\Composer;
use Composer\Test\TestCase;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\Link;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class RepositoryManagerTest extends TestCase
{
    public function testFindOutdated()
    {
        $composer = new Composer();

        //build some link constraints
        $linkFoo = new Link('git://foo.git','foo',new VersionConstraint('>=','1.0'));
        $linkBar = new Link('git://bar.git','bar',new MultiConstraint(array(
            new VersionConstraint('>=','1.0'),
            new VersionConstraint('<','2.0')
        )));
        $linkBazz = new Link('git://bazz.git','bazz',new VersionConstraint('>','1.0'));

        $pkg = $this->getPackage('myPackage', '1');
        $pkg->setRequires(array($linkFoo, $linkBar, $linkBazz));
        $composer->setPackage($pkg);

        //add some remote packages
        $repo = new ArrayRepository();
        $repo->addPackage($this->getPackage('foo', '1.0'));
        $repo->addPackage($this->getPackage('foo', '1.1'));
        $repo->addPackage($this->getPackage('foo', '1.2'));
        $repo->addPackage($this->getPackage('bar', '1.0'));
        $repo->addPackage($this->getPackage('bar', '1.7'));
        $repo->addPackage($this->getPackage('bar', '2.0'));
        $repo->addPackage($this->getPackage('bazz', '1.0'));
        $repo->addPackage($this->getPackage('bazz', '1.1'));

        //add the local packages
        $local = new ArrayRepository();
        $local->addPackage($this->getPackage('foo', '1.0'));
        $local->addPackage($this->getPackage('bar', '1.0'));
        $local->addPackage($this->getPackage('bazz', '1.0'));

        $manager = new RepositoryManager();
        $manager->addRepository($repo);
        $manager->setLocalRepository($local);

        $result = $manager->findOutdated($composer);
        $packageFoo = $result['foo'];
        $packageBar = $result['bar'];
        $packageBazz = $result['bazz'];

        $this->assertEquals($packageFoo['available']->getPrettyVersion(), '1.2');
        $this->assertEquals($packageBar['available']->getPrettyVersion(), '1.7');
        $this->assertEquals($packageBazz['available']->getPrettyVersion(), '1.1');
    }
}