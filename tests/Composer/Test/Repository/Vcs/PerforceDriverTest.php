<?php
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 *  Contributor: matt-whittom
 *  Date: 7/17/13
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Repository\Vcs;

#use Composer\Downloader\TransportException;
use Composer\Repository\Vcs\PerforceDriver;
use Composer\Util\Filesystem;
use Composer\Config;
use Composer\IO\ConsoleIO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Helper\HelperSet;


class PerforceDriverTest extends \PHPUnit_Framework_TestCase
{
    private $config;
    private $io;

    public function setUp()
    {
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => sys_get_temp_dir() . '/composer-test',
            ),
        ));
        $inputParameters = array();
        $input = new ArrayInput($inputParameters);
        $output = new ConsoleOutput();
        $helperSet = new HelperSet();
        $this->io = new ConsoleIO($input, $output, $helperSet);
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory(sys_get_temp_dir() . '/composer-test');
    }

    public function testPrivateRepository()
    {
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
    }

    public function testGetBranches()
    {
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
        $branches = $vcs->getBranches();
        //print ("\nBranches are: " . var_export($branches, true));
        $this->assertTrue(strcmp($branches['mainline'], "//lighthouse/mainline") == 0);
    }

    public function testGetTags()
    {
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
        $tags = $vcs->getTags();
        $this->assertTrue(empty($tags));
    }

    public function testGetSource()
    {
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
        $identifier = $vcs->getRootIdentifier();
        $source = $vcs->getSource($identifier);
        $this->assertEquals($source['type'], "perforce");
        $this->assertEquals($source['reference'], $identifier);
    }

    public function testGetDist()
    {
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
        $identifier = $vcs->getRootIdentifier();
        $dist = $vcs->getDist($identifier);
        $this->assertNull($dist);
    }

    public function testGetRootIdentifier(){
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
        $rootId = $vcs->getRootIdentifier();
        $this->assertEquals("mainline", $rootId);
    }

    public function testHasComposerFile(){
        $repo_config = array(
            'url' => "perforce.vuhl.root.mrc.local:3710",
            'depot' => "lighthouse"
        );

        $vcs = new PerforceDriver($repo_config, $this->io, $this->config);
        $result = $vcs->initialize();
        $this->assertTrue($result);
        $identifier = $vcs->getRootIdentifier();
        $value = $vcs->hasComposerFile($identifier);
        $this->assertTrue($value);
    }
}

