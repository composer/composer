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

namespace Composer\Test\Util;

use Composer\Util\Perforce;
use Composer\Util\ProcessExecutor;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceTest extends \PHPUnit_Framework_TestCase
{
    protected $perforce;
    protected $processExecutor;
    protected $io;

    const TEST_DEPOT       = 'depot';
    const TEST_BRANCH      = 'branch';
    const TEST_P4USER      = 'user';
    const TEST_CLIENT_NAME = 'TEST';
    const TEST_PORT        = 'port';
    const TEST_PATH        = 'path';

    protected function setUp()
    {
        $this->processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $this->repoConfig = $this->getTestRepoConfig();
        $this->io = $this->getMockIOInterface();
        $this->createNewPerforceWithWindowsFlag(true);
    }

    protected function tearDown()
    {
        $this->perforce        = null;
        $this->io              = null;
        $this->repoConfig      = null;
        $this->processExecutor = null;
    }

    public function getTestRepoConfig()
    {
        return array(
            'depot'                       => self::TEST_DEPOT,
            'branch'                      => self::TEST_BRANCH,
            'p4user'                      => self::TEST_P4USER,
            'unique_perforce_client_name' => self::TEST_CLIENT_NAME,
        );
    }

    public function getMockIOInterface()
    {
        return $this->getMock('Composer\IO\IOInterface');
    }

    protected function createNewPerforceWithWindowsFlag($flag)
    {
        $this->perforce = new Perforce($this->repoConfig, self::TEST_PORT, self::TEST_PATH, $this->processExecutor, $flag, $this->io);
    }

    public function testGetClientWithoutStream()
    {
        $client = $this->perforce->getClient();
        $hostname = gethostname();
        $timestamp = time();

        $expected = 'composer_perforce_TEST_depot';
        $this->assertEquals($expected, $client);
    }

    public function testGetClientFromStream()
    {
        $this->setPerforceToStream();

        $client = $this->perforce->getClient();

        $expected = 'composer_perforce_TEST_depot_branch';
        $this->assertEquals($expected, $client);
    }

    public function testGetStreamWithoutStream()
    {
        $stream = $this->perforce->getStream();
        $this->assertEquals("//depot", $stream);
    }

    public function testGetStreamWithStream()
    {
        $this->setPerforceToStream();

        $stream = $this->perforce->getStream();
        $this->assertEquals('//depot/branch', $stream);
    }

    public function testGetStreamWithoutLabelWithStreamWithoutLabel()
    {
        $stream = $this->perforce->getStreamWithoutLabel('//depot/branch');
        $this->assertEquals('//depot/branch', $stream);
    }

    public function testGetStreamWithoutLabelWithStreamWithLabel()
    {
        $stream = $this->perforce->getStreamWithoutLabel('//depot/branching@label');
        $this->assertEquals('//depot/branching', $stream);
    }

    public function testGetClientSpec()
    {
        $clientSpec = $this->perforce->getP4ClientSpec();
        $expected = 'path/composer_perforce_TEST_depot.p4.spec';
        $this->assertEquals($expected, $clientSpec);
    }

    public function testGenerateP4Command()
    {
        $command = 'do something';
        $p4Command = $this->perforce->generateP4Command($command);
        $expected = 'p4 -u user -c composer_perforce_TEST_depot -p port do something';
        $this->assertEquals($expected, $p4Command);
    }

    public function testQueryP4UserWithUserAlreadySet()
    {
        $this->perforce->queryP4user();
        $this->assertEquals(self::TEST_P4USER, $this->perforce->getUser());
    }

    public function testQueryP4UserWithUserSetInP4VariablesWithWindowsOS()
    {
        $this->createNewPerforceWithWindowsFlag(true);
        $this->perforce->setUser(null);
        $expectedCommand = 'p4 set';
        $callback = function ($command, &$output) {
                $output = 'P4USER=TEST_P4VARIABLE_USER' . PHP_EOL;

                return true;
            };
        $this->processExecutor->expects($this->at(0))
                            ->method('execute')
                            ->with($this->equalTo($expectedCommand))
                            ->will($this->returnCallback($callback));
        $this->perforce->queryP4user();
        $this->assertEquals('TEST_P4VARIABLE_USER', $this->perforce->getUser());
    }

    public function testQueryP4UserWithUserSetInP4VariablesNotWindowsOS()
    {
        $this->createNewPerforceWithWindowsFlag(false);
        $this->perforce->setUser(null);
        $expectedCommand = 'echo $P4USER';
        $callback = function ($command, &$output) {
                $output = 'TEST_P4VARIABLE_USER' . PHP_EOL;

                return true;
            };
        $this->processExecutor->expects($this->at(0))
                              ->method('execute')
                              ->with($this->equalTo($expectedCommand))
                              ->will($this->returnCallback($callback));
        $this->perforce->queryP4user();
        $this->assertEquals('TEST_P4VARIABLE_USER', $this->perforce->getUser());
    }

    public function testQueryP4UserQueriesForUser()
    {
        $this->perforce->setUser(null);
        $expectedQuestion = 'Enter P4 User:';
        $this->io->expects($this->at(0))
                 ->method('ask')
                 ->with($this->equalTo($expectedQuestion))
                 ->will($this->returnValue('TEST_QUERY_USER'));
        $this->perforce->queryP4user();
        $this->assertEquals('TEST_QUERY_USER', $this->perforce->getUser());
    }

    public function testQueryP4UserStoresResponseToQueryForUserWithWindows()
    {
        $this->createNewPerforceWithWindowsFlag(true);
        $this->perforce->setUser(null);
        $expectedQuestion = 'Enter P4 User:';
        $expectedCommand  = 'p4 set P4USER=TEST_QUERY_USER';
        $this->io->expects($this->at(0))
                 ->method('ask')
                 ->with($this->equalTo($expectedQuestion))
                 ->will($this->returnValue('TEST_QUERY_USER'));
        $this->processExecutor->expects($this->at(1))
                              ->method('execute')
                              ->with($this->equalTo($expectedCommand))
                              ->will($this->returnValue(0));
        $this->perforce->queryP4user();
    }

    public function testQueryP4UserStoresResponseToQueryForUserWithoutWindows()
    {
        $this->createNewPerforceWithWindowsFlag(false);
        $this->perforce->setUser(null);
        $expectedQuestion = 'Enter P4 User:';
        $expectedCommand  = 'export P4USER=TEST_QUERY_USER';
        $this->io->expects($this->at(0))
                 ->method('ask')
                 ->with($this->equalTo($expectedQuestion))
                 ->will($this->returnValue('TEST_QUERY_USER'));
        $this->processExecutor->expects($this->at(1))
                              ->method('execute')
                              ->with($this->equalTo($expectedCommand))
                              ->will($this->returnValue(0));
        $this->perforce->queryP4user();
    }

    public function testQueryP4PasswordWithPasswordAlreadySet()
    {
        $repoConfig = array(
            'depot'      => 'depot',
            'branch'     => 'branch',
            'p4user'     => 'user',
            'p4password' => 'TEST_PASSWORD',
        );
        $this->perforce = new Perforce($repoConfig, 'port', 'path', $this->processExecutor, false, $this->getMockIOInterface(), 'TEST');
        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_PASSWORD', $password);
    }

    public function testQueryP4PasswordWithPasswordSetInP4VariablesWithWindowsOS()
    {
        $this->createNewPerforceWithWindowsFlag(true);
        $expectedCommand = 'p4 set';
        $callback = function ($command, &$output) {
                $output = 'P4PASSWD=TEST_P4VARIABLE_PASSWORD' . PHP_EOL;

                return true;
            };
        $this->processExecutor->expects($this->at(0))
                              ->method('execute')
                              ->with($this->equalTo($expectedCommand))
                              ->will($this->returnCallback($callback));
        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_P4VARIABLE_PASSWORD', $password);
    }

    public function testQueryP4PasswordWithPasswordSetInP4VariablesNotWindowsOS()
    {
        $this->createNewPerforceWithWindowsFlag(false);
        $expectedCommand = 'echo $P4PASSWD';
        $callback = function ($command, &$output) {
                $output = 'TEST_P4VARIABLE_PASSWORD' . PHP_EOL;

                return true;
            };
        $this->processExecutor->expects($this->at(0))
                              ->method('execute')
                              ->with($this->equalTo($expectedCommand))
                              ->will($this->returnCallback($callback));

        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_P4VARIABLE_PASSWORD', $password);
    }

    public function testQueryP4PasswordQueriesForPassword()
    {
        $expectedQuestion = 'Enter password for Perforce user user: ';
        $this->io->expects($this->at(0))
            ->method('askAndHideAnswer')
            ->with($this->equalTo($expectedQuestion))
            ->will($this->returnValue('TEST_QUERY_PASSWORD'));

        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_QUERY_PASSWORD', $password);
    }

    public function testWriteP4ClientSpecWithoutStream()
    {
        $stream = fopen('php://memory', 'w+');
        $this->perforce->writeClientSpecToFile($stream);

        rewind($stream);

        $expectedArray = $this->getExpectedClientSpec(false);
        try {
            foreach ($expectedArray as $expected) {
                $this->assertStringStartsWith($expected, fgets($stream));
            }
            $this->assertFalse(fgets($stream));
        } catch (Exception $e) {
            fclose($stream);
            throw $e;
        }
        fclose($stream);
    }

    public function testWriteP4ClientSpecWithStream()
    {
        $this->setPerforceToStream();
        $stream = fopen('php://memory', 'w+');

        $this->perforce->writeClientSpecToFile($stream);
        rewind($stream);

        $expectedArray = $this->getExpectedClientSpec(true);
        try {
            foreach ($expectedArray as $expected) {
                $this->assertStringStartsWith($expected, fgets($stream));
            }
            $this->assertFalse(fgets($stream));
        } catch (Exception $e) {
            fclose($stream);
            throw $e;
        }
        fclose($stream);
    }

    public function testIsLoggedIn()
    {
        $expectedCommand = 'p4 -u user -p port login -s';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(0));

        $this->perforce->isLoggedIn();
    }

    public function testConnectClient()
    {
        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot -p port client -i < path/composer_perforce_TEST_depot.p4.spec';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(0));

        $this->perforce->connectClient();
    }

    public function testGetBranchesWithStream()
    {
        $this->setPerforceToStream();

        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot_branch -p port streams //depot/...';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = 'Stream //depot/branch mainline none \'branch\'' . PHP_EOL;

                        return true;
                    }
                )
            );
        $expectedCommand2 = 'p4 -u user -p port changes //depot/branch/...';
        $expectedCallback = function ($command, &$output) {
                $output = 'Change 1234 on 2014/03/19 by Clark.Stuth@Clark.Stuth_test_client \'test changelist\'';

                return true;
            };
        $this->processExecutor->expects($this->at(1))
                              ->method('execute')
                              ->with($this->equalTo($expectedCommand2))
                              ->will($this->returnCallback($expectedCallback));

        $branches = $this->perforce->getBranches();
        $this->assertEquals('//depot/branch@1234', $branches['master']);
    }

    public function testGetBranchesWithoutStream()
    {
        $expectedCommand = 'p4 -u user -p port changes //depot/...';
        $expectedCallback = function ($command, &$output) {
                $output = 'Change 5678 on 2014/03/19 by Clark.Stuth@Clark.Stuth_test_client \'test changelist\'';

                return true;
            };
        $this->processExecutor->expects($this->once())
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will($this->returnCallback($expectedCallback));
        $branches = $this->perforce->getBranches();
        $this->assertEquals('//depot@5678', $branches['master']);
    }

    public function testGetTagsWithoutStream()
    {
        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot -p port labels';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = 'Label 0.0.1 2013/07/31 \'First Label!\'' . PHP_EOL . 'Label 0.0.2 2013/08/01 \'Second Label!\'' . PHP_EOL;

                        return true;
                    }
                )
            );

        $tags = $this->perforce->getTags();
        $this->assertEquals('//depot@0.0.1', $tags['0.0.1']);
        $this->assertEquals('//depot@0.0.2', $tags['0.0.2']);
    }

    public function testGetTagsWithStream()
    {
        $this->setPerforceToStream();

        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot_branch -p port labels';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = 'Label 0.0.1 2013/07/31 \'First Label!\'' . PHP_EOL . 'Label 0.0.2 2013/08/01 \'Second Label!\'' . PHP_EOL;

                        return true;
                    }
                )
            );

        $tags = $this->perforce->getTags();
        $this->assertEquals('//depot/branch@0.0.1', $tags['0.0.1']);
        $this->assertEquals('//depot/branch@0.0.2', $tags['0.0.2']);
    }

    public function testCheckStreamWithoutStream()
    {
        $result = $this->perforce->checkStream('depot');
        $this->assertFalse($result);
        $this->assertFalse($this->perforce->isStream());
    }

    public function testCheckStreamWithStream()
    {
        $this->processExecutor->expects($this->any())->method('execute')
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = 'Depot depot 2013/06/25 stream /p4/1/depots/depot/... \'Created by Me\'';

                        return true;
                    }
                )
            );
        $result = $this->perforce->checkStream('depot');
        $this->assertTrue($result);
        $this->assertTrue($this->perforce->isStream());
    }

    public function testGetComposerInformationWithoutLabelWithoutStream()
    {
        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot -p port  print //depot/composer.json';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = PerforceTest::getComposerJson();

                        return true;
                    }
                )
            );

        $result = $this->perforce->getComposerInformation('//depot');
        $expected = array(
            'name'              => 'test/perforce',
            'description'       => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload'          => array('psr-0' => array()),
        );
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithLabelWithoutStream()
    {
        $expectedCommand = 'p4 -u user -p port  files //depot/composer.json@0.0.1';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = '//depot/composer.json#1 - branch change 10001 (text)';

                        return true;
                    }
                )
            );

        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot -p port  print //depot/composer.json@10001';
        $this->processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = PerforceTest::getComposerJson();

                        return true;
                    }
                )
            );

        $result = $this->perforce->getComposerInformation('//depot@0.0.1');

        $expected = array(
            'name'              => 'test/perforce',
            'description'       => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload'          => array('psr-0' => array()),
        );
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithoutLabelWithStream()
    {
        $this->setPerforceToStream();

        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot_branch -p port  print //depot/branch/composer.json';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = PerforceTest::getComposerJson();

                        return true;
                    }
                )
            );

        $result = $this->perforce->getComposerInformation('//depot/branch');

        $expected = array(
            'name'              => 'test/perforce',
            'description'       => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload'          => array('psr-0' => array()),
        );
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithLabelWithStream()
    {
        $this->setPerforceToStream();
        $expectedCommand = 'p4 -u user -p port  files //depot/branch/composer.json@0.0.1';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = '//depot/composer.json#1 - branch change 10001 (text)';

                        return true;
                    }
                )
            );

        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot_branch -p port  print //depot/branch/composer.json@10001';
        $this->processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will(
                $this->returnCallback(
                    function ($command, &$output) {
                        $output = PerforceTest::getComposerJson();

                        return true;
                    }
                )
            );

        $result = $this->perforce->getComposerInformation('//depot/branch@0.0.1');

        $expected = array(
            'name'              => 'test/perforce',
            'description'       => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload'          => array('psr-0' => array()),
        );
        $this->assertEquals($expected, $result);
    }

    public function testSyncCodeBaseWithoutStream()
    {
        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot -p port sync -f @label';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(0));

        $this->perforce->syncCodeBase('label');
    }

    public function testSyncCodeBaseWithStream()
    {
        $this->setPerforceToStream();
        $expectedCommand = 'p4 -u user -c composer_perforce_TEST_depot_branch -p port sync -f @label';
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will($this->returnValue(0));

        $this->perforce->syncCodeBase('label');
    }

    public function testCheckServerExists()
    {
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedCommand = 'p4 -p perforce.does.exist:port info -s';
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(0));

        $result = $this->perforce->checkServerExists('perforce.does.exist:port', $processExecutor);
        $this->assertTrue($result);
    }

    /**
     * Test if "p4" command is missing.
     *
     * @covers \Composer\Util\Perforce::checkServerExists
     *
     * @return void
     */
    public function testCheckServerClientError()
    {
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedCommand = 'p4 -p perforce.does.exist:port info -s';
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(127));

        $result = $this->perforce->checkServerExists('perforce.does.exist:port', $processExecutor);
        $this->assertFalse($result);
    }

    public static function getComposerJson()
    {
        $composer_json = array(
            '{',
            '"name": "test/perforce",',
            '"description": "Basic project for testing",',
            '"minimum-stability": "dev",',
            '"autoload": {',
            '"psr-0" : {',
            '}',
            '}',
            '}',
        );

        return implode($composer_json);
    }

    private function getExpectedClientSpec($withStream)
    {
        $expectedArray = array(
            'Client: composer_perforce_TEST_depot',
            PHP_EOL,
            'Update:',
            PHP_EOL,
            'Access:',
            'Owner:  user',
            PHP_EOL,
            'Description:',
            '  Created by user from composer.',
            PHP_EOL,
            'Root: path',
            PHP_EOL,
            'Options:  noallwrite noclobber nocompress unlocked modtime rmdir',
            PHP_EOL,
            'SubmitOptions:  revertunchanged',
            PHP_EOL,
            'LineEnd:  local',
            PHP_EOL,
        );
        if ($withStream) {
            $expectedArray[] = 'Stream:';
            $expectedArray[] = '  //depot/branch';
        } else {
            $expectedArray[] = 'View:  //depot/...  //composer_perforce_TEST_depot/...';
        }

        return $expectedArray;
    }

    private function setPerforceToStream()
    {
        $this->perforce->setStream('//depot/branch');
    }

    public function testCleanupClientSpecShouldDeleteClient()
    {
        $fs = $this->getMock('Composer\Util\Filesystem');
        $this->perforce->setFilesystem($fs);

        $testClient = $this->perforce->getClient();
        $expectedCommand = 'p4 -u ' . self::TEST_P4USER . ' -p ' . self::TEST_PORT . ' client -d ' . $testClient;
        $this->processExecutor->expects($this->once())->method('execute')->with($this->equalTo($expectedCommand));

        $fs->expects($this->once())->method('remove')->with($this->perforce->getP4ClientSpec());

        $this->perforce->cleanupClientSpec();
    }
}
