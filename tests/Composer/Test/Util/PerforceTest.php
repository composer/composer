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

namespace Composer\Test\Util;

use Composer\Json\JsonFile;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Util\Perforce;
use Composer\Test\TestCase;
use Composer\Util\ProcessExecutor;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceTest extends TestCase
{
    /** @var Perforce */
    protected $perforce;
    /** @var ProcessExecutorMock */
    protected $processExecutor;
    /** @var array<string, string> */
    protected $repoConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject&\Composer\IO\IOInterface */
    protected $io;

    private const TEST_DEPOT = 'depot';
    private const TEST_BRANCH = 'branch';
    private const TEST_P4USER = 'user';
    private const TEST_CLIENT_NAME = 'TEST';
    private const TEST_PORT = 'port';
    private const TEST_PATH = 'path';

    protected function setUp(): void
    {
        $this->processExecutor = $this->getProcessExecutorMock();
        $this->repoConfig = $this->getTestRepoConfig();
        $this->io = $this->getMockIOInterface();
        $this->createNewPerforceWithWindowsFlag(true);
    }

    /**
     * @return array<string, string>
     */
    public function getTestRepoConfig(): array
    {
        return [
            'depot' => self::TEST_DEPOT,
            'branch' => self::TEST_BRANCH,
            'p4user' => self::TEST_P4USER,
            'unique_perforce_client_name' => self::TEST_CLIENT_NAME,
        ];
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\IO\IOInterface
     */
    public function getMockIOInterface()
    {
        return $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    protected function createNewPerforceWithWindowsFlag(bool $flag): void
    {
        $this->perforce = new Perforce($this->repoConfig, self::TEST_PORT, self::TEST_PATH, $this->processExecutor, $flag, $this->io);
    }

    public function testGetClientWithoutStream(): void
    {
        $client = $this->perforce->getClient();

        $expected = 'composer_perforce_TEST_depot';
        $this->assertEquals($expected, $client);
    }

    public function testGetClientFromStream(): void
    {
        $this->setPerforceToStream();

        $client = $this->perforce->getClient();

        $expected = 'composer_perforce_TEST_depot_branch';
        $this->assertEquals($expected, $client);
    }

    public function testGetStreamWithoutStream(): void
    {
        $stream = $this->perforce->getStream();
        $this->assertEquals("//depot", $stream);
    }

    public function testGetStreamWithStream(): void
    {
        $this->setPerforceToStream();

        $stream = $this->perforce->getStream();
        $this->assertEquals('//depot/branch', $stream);
    }

    public function testGetStreamWithoutLabelWithStreamWithoutLabel(): void
    {
        $stream = $this->perforce->getStreamWithoutLabel('//depot/branch');
        $this->assertEquals('//depot/branch', $stream);
    }

    public function testGetStreamWithoutLabelWithStreamWithLabel(): void
    {
        $stream = $this->perforce->getStreamWithoutLabel('//depot/branching@label');
        $this->assertEquals('//depot/branching', $stream);
    }

    public function testGetClientSpec(): void
    {
        $clientSpec = $this->perforce->getP4ClientSpec();
        $expected = 'path/composer_perforce_TEST_depot.p4.spec';
        $this->assertEquals($expected, $clientSpec);
    }

    public function testGenerateP4Command(): void
    {
        $command = 'do something';
        $p4Command = $this->perforce->generateP4Command($command);
        $expected = 'p4 -u user -c composer_perforce_TEST_depot -p port do something';
        $this->assertEquals($expected, $p4Command);
    }

    public function testQueryP4UserWithUserAlreadySet(): void
    {
        $this->perforce->queryP4user();
        $this->assertEquals(self::TEST_P4USER, $this->perforce->getUser());
    }

    public function testQueryP4UserWithUserSetInP4VariablesWithWindowsOS(): void
    {
        $this->createNewPerforceWithWindowsFlag(true);
        $this->perforce->setUser(null);
        $this->processExecutor->expects(
            [['cmd' => 'p4 set', 'stdout' => 'P4USER=TEST_P4VARIABLE_USER' . PHP_EOL, 'return' => 0]],
            true
        );

        $this->perforce->queryP4user();
        $this->assertEquals('TEST_P4VARIABLE_USER', $this->perforce->getUser());
    }

    public function testQueryP4UserWithUserSetInP4VariablesNotWindowsOS(): void
    {
        $this->createNewPerforceWithWindowsFlag(false);
        $this->perforce->setUser(null);

        $this->processExecutor->expects(
            [['cmd' => 'echo $P4USER', 'stdout' => 'TEST_P4VARIABLE_USER' . PHP_EOL, 'return' => 0]],
            true
        );

        $this->perforce->queryP4user();
        $this->assertEquals('TEST_P4VARIABLE_USER', $this->perforce->getUser());
    }

    public function testQueryP4UserQueriesForUser(): void
    {
        $this->perforce->setUser(null);
        $expectedQuestion = 'Enter P4 User:';
        $this->io->method('ask')
                 ->with($this->equalTo($expectedQuestion))
                 ->willReturn('TEST_QUERY_USER');
        $this->perforce->queryP4user();
        $this->assertEquals('TEST_QUERY_USER', $this->perforce->getUser());
    }

    public function testQueryP4UserStoresResponseToQueryForUserWithWindows(): void
    {
        $this->createNewPerforceWithWindowsFlag(true);
        $this->perforce->setUser(null);
        $expectedQuestion = 'Enter P4 User:';
        $expectedCommand = 'p4 set P4USER=TEST_QUERY_USER';
        $this->io->expects($this->once())
                 ->method('ask')
                 ->with($this->equalTo($expectedQuestion))
                 ->willReturn('TEST_QUERY_USER');

        $this->processExecutor->expects(
            [
                'p4 set',
                $expectedCommand,
            ],
            true
        );

        $this->perforce->queryP4user();
    }

    public function testQueryP4UserStoresResponseToQueryForUserWithoutWindows(): void
    {
        $this->createNewPerforceWithWindowsFlag(false);
        $this->perforce->setUser(null);
        $expectedQuestion = 'Enter P4 User:';
        $expectedCommand = 'export P4USER=TEST_QUERY_USER';
        $this->io->expects($this->once())
                 ->method('ask')
                 ->with($this->equalTo($expectedQuestion))
                 ->willReturn('TEST_QUERY_USER');
        $this->processExecutor->expects(
            [
                'echo $P4USER',
                $expectedCommand,
            ],
            true
        );
        $this->perforce->queryP4user();
    }

    public function testQueryP4PasswordWithPasswordAlreadySet(): void
    {
        $repoConfig = [
            'depot' => 'depot',
            'branch' => 'branch',
            'p4user' => 'user',
            'p4password' => 'TEST_PASSWORD',
        ];
        $this->perforce = new Perforce($repoConfig, 'port', 'path', $this->processExecutor, false, $this->getMockIOInterface());
        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_PASSWORD', $password);
    }

    public function testQueryP4PasswordWithPasswordSetInP4VariablesWithWindowsOS(): void
    {
        $this->createNewPerforceWithWindowsFlag(true);

        $this->processExecutor->expects(
            [['cmd' => 'p4 set', 'stdout' => 'P4PASSWD=TEST_P4VARIABLE_PASSWORD' . PHP_EOL, 'return' => 0]],
            true
        );

        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_P4VARIABLE_PASSWORD', $password);
    }

    public function testQueryP4PasswordWithPasswordSetInP4VariablesNotWindowsOS(): void
    {
        $this->createNewPerforceWithWindowsFlag(false);

        $this->processExecutor->expects(
            [['cmd' => 'echo $P4PASSWD', 'stdout' => 'TEST_P4VARIABLE_PASSWORD' . PHP_EOL, 'return' => 0]],
            true
        );

        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_P4VARIABLE_PASSWORD', $password);
    }

    public function testQueryP4PasswordQueriesForPassword(): void
    {
        $expectedQuestion = 'Enter password for Perforce user user: ';
        $this->io->expects($this->once())
            ->method('askAndHideAnswer')
            ->with($this->equalTo($expectedQuestion))
            ->willReturn('TEST_QUERY_PASSWORD');

        $password = $this->perforce->queryP4Password();
        $this->assertEquals('TEST_QUERY_PASSWORD', $password);
    }

    public function testWriteP4ClientSpecWithoutStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            self::fail('Could not open memory stream');
        }
        $this->perforce->writeClientSpecToFile($stream);

        rewind($stream);

        $expectedArray = $this->getExpectedClientSpec(false);
        try {
            foreach ($expectedArray as $expected) {
                $this->assertStringStartsWith($expected, fgets($stream));
            }
            $this->assertFalse(fgets($stream));
        } catch (\Exception $e) {
            fclose($stream);
            throw $e;
        }
        fclose($stream);
    }

    public function testWriteP4ClientSpecWithStream(): void
    {
        $this->setPerforceToStream();
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            self::fail('Could not open memory stream');
        }

        $this->perforce->writeClientSpecToFile($stream);
        rewind($stream);

        $expectedArray = $this->getExpectedClientSpec(true);
        try {
            foreach ($expectedArray as $expected) {
                $this->assertStringStartsWith($expected, fgets($stream));
            }
            $this->assertFalse(fgets($stream));
        } catch (\Exception $e) {
            fclose($stream);
            throw $e;
        }
        fclose($stream);
    }

    public function testIsLoggedIn(): void
    {
        $this->processExecutor->expects(
            [['cmd' => 'p4 -u user -p port login -s']],
            true
        );
        $this->perforce->isLoggedIn();
    }

    public function testConnectClient(): void
    {
        $this->processExecutor->expects(
            ['p4 -u user -c composer_perforce_TEST_depot -p port client -i < '.ProcessExecutor::escape('path/composer_perforce_TEST_depot.p4.spec')],
            true
        );

        $this->perforce->connectClient();
    }

    public function testGetBranchesWithStream(): void
    {
        $this->setPerforceToStream();

        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot_branch -p port streams '.ProcessExecutor::escape('//depot/...'),
                    'stdout' => 'Stream //depot/branch mainline none \'branch\'' . PHP_EOL,
                ],
                [
                    'cmd' => 'p4 -u user -p port changes '.ProcessExecutor::escape('//depot/branch/...'),
                    'stdout' => 'Change 1234 on 2014/03/19 by Clark.Stuth@Clark.Stuth_test_client \'test changelist\'',
                ],
            ],
            true
        );

        $branches = $this->perforce->getBranches();
        $this->assertEquals('//depot/branch@1234', $branches['master']);
    }

    public function testGetBranchesWithoutStream(): void
    {
        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -p port changes '.ProcessExecutor::escape('//depot/...'),
                    'stdout' => 'Change 5678 on 2014/03/19 by Clark.Stuth@Clark.Stuth_test_client \'test changelist\'',
                ],
            ],
            true
        );

        $branches = $this->perforce->getBranches();
        $this->assertEquals('//depot@5678', $branches['master']);
    }

    public function testGetTagsWithoutStream(): void
    {
        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot -p port labels',
                    'stdout' => 'Label 0.0.1 2013/07/31 \'First Label!\'' . PHP_EOL . 'Label 0.0.2 2013/08/01 \'Second Label!\'' . PHP_EOL,
                ],
            ],
            true
        );

        $tags = $this->perforce->getTags();
        $this->assertEquals('//depot@0.0.1', $tags['0.0.1']);
        $this->assertEquals('//depot@0.0.2', $tags['0.0.2']);
    }

    public function testGetTagsWithStream(): void
    {
        $this->setPerforceToStream();

        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot_branch -p port labels',
                    'stdout' => 'Label 0.0.1 2013/07/31 \'First Label!\'' . PHP_EOL . 'Label 0.0.2 2013/08/01 \'Second Label!\'' . PHP_EOL,
                ],
            ],
            true
        );

        $tags = $this->perforce->getTags();
        $this->assertEquals('//depot/branch@0.0.1', $tags['0.0.1']);
        $this->assertEquals('//depot/branch@0.0.2', $tags['0.0.2']);
    }

    public function testCheckStreamWithoutStream(): void
    {
        $result = $this->perforce->checkStream();
        $this->assertFalse($result);
        $this->assertFalse($this->perforce->isStream());
    }

    public function testCheckStreamWithStream(): void
    {
        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -p port depots',
                    'stdout' => 'Depot depot 2013/06/25 stream /p4/1/depots/depot/... \'Created by Me\'',
                ],
            ],
            true
        );

        $result = $this->perforce->checkStream();
        $this->assertTrue($result);
        $this->assertTrue($this->perforce->isStream());
    }

    public function testGetComposerInformationWithoutLabelWithoutStream(): void
    {
        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot -p port  print '.ProcessExecutor::escape('//depot/composer.json'),
                    'stdout' => PerforceTest::getComposerJson(),
                ],
            ],
            true
        );

        $result = $this->perforce->getComposerInformation('//depot');
        $expected = [
            'name' => 'test/perforce',
            'description' => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload' => ['psr-0' => []],
        ];
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithLabelWithoutStream(): void
    {
        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -p port  files '.ProcessExecutor::escape('//depot/composer.json@0.0.1'),
                    'stdout' => '//depot/composer.json#1 - branch change 10001 (text)',
                ],
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot -p port  print '.ProcessExecutor::escape('//depot/composer.json@10001'),
                    'stdout' => PerforceTest::getComposerJson(),
                ],
            ],
            true
        );

        $result = $this->perforce->getComposerInformation('//depot@0.0.1');

        $expected = [
            'name' => 'test/perforce',
            'description' => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload' => ['psr-0' => []],
        ];
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithoutLabelWithStream(): void
    {
        $this->setPerforceToStream();

        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot_branch -p port  print '.ProcessExecutor::escape('//depot/branch/composer.json'),
                    'stdout' => PerforceTest::getComposerJson(),
                ],
            ],
            true
        );

        $result = $this->perforce->getComposerInformation('//depot/branch');

        $expected = [
            'name' => 'test/perforce',
            'description' => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload' => ['psr-0' => []],
        ];
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithLabelWithStream(): void
    {
        $this->processExecutor->expects(
            [
                [
                    'cmd' => 'p4 -u user -p port  files '.ProcessExecutor::escape('//depot/branch/composer.json@0.0.1'),
                    'stdout' => '//depot/composer.json#1 - branch change 10001 (text)',
                ],
                [
                    'cmd' => 'p4 -u user -c composer_perforce_TEST_depot_branch -p port  print '.ProcessExecutor::escape('//depot/branch/composer.json@10001'),
                    'stdout' => PerforceTest::getComposerJson(),
                ],
            ],
            true
        );

        $this->setPerforceToStream();

        $result = $this->perforce->getComposerInformation('//depot/branch@0.0.1');

        $expected = [
            'name' => 'test/perforce',
            'description' => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload' => ['psr-0' => []],
        ];
        $this->assertEquals($expected, $result);
    }

    public function testSyncCodeBaseWithoutStream(): void
    {
        $this->processExecutor->expects(
            ['p4 -u user -c composer_perforce_TEST_depot -p port sync -f @label'],
            true
        );

        $this->perforce->syncCodeBase('label');
    }

    public function testSyncCodeBaseWithStream(): void
    {
        $this->setPerforceToStream();

        $this->processExecutor->expects(
            ['p4 -u user -c composer_perforce_TEST_depot_branch -p port sync -f @label'],
            true
        );

        $this->perforce->syncCodeBase('label');
    }

    public function testCheckServerExists(): void
    {
        $this->processExecutor->expects(
            ['p4 -p '.ProcessExecutor::escape('perforce.does.exist:port').' info -s'],
            true
        );

        $result = $this->perforce->checkServerExists('perforce.does.exist:port', $this->processExecutor);
        $this->assertTrue($result);
    }

    /**
     * Test if "p4" command is missing.
     *
     * @covers \Composer\Util\Perforce::checkServerExists
     */
    public function testCheckServerClientError(): void
    {
        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();

        $expectedCommand = 'p4 -p '.ProcessExecutor::escape('perforce.does.exist:port').' info -s';
        $processExecutor->expects($this->once())
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->willReturn(127);

        $result = $this->perforce->checkServerExists('perforce.does.exist:port', $processExecutor);
        $this->assertFalse($result);
    }

    public static function getComposerJson(): string
    {
        return JsonFile::encode([
            'name' => 'test/perforce',
            'description' => 'Basic project for testing',
            'minimum-stability' => 'dev',
            'autoload' => [
                'psr-0' => [],
            ],
        ], JSON_FORCE_OBJECT);
    }

    /**
     * @return string[]
     */
    private function getExpectedClientSpec(bool $withStream): array
    {
        $expectedArray = [
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
        ];
        if ($withStream) {
            $expectedArray[] = 'Stream:';
            $expectedArray[] = '  //depot/branch';
        } else {
            $expectedArray[] = 'View:  //depot/...  //composer_perforce_TEST_depot/...';
        }

        return $expectedArray;
    }

    private function setPerforceToStream(): void
    {
        $this->perforce->setStream('//depot/branch');
    }

    public function testCleanupClientSpecShouldDeleteClient(): void
    {
        $fs = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $this->perforce->setFilesystem($fs);

        $testClient = $this->perforce->getClient();
        $this->processExecutor->expects(
            ['p4 -u ' . self::TEST_P4USER . ' -p ' . self::TEST_PORT . ' client -d ' . ProcessExecutor::escape($testClient)],
            true
        );

        $fs->expects($this->once())->method('remove')->with($this->perforce->getP4ClientSpec());

        $this->perforce->cleanupClientSpec();
    }
}
