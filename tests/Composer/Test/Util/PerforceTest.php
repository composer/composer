<?php
/**
 * Created by JetBrains PhpStorm.
 * User: matt.whittom
 * Date: 7/31/13
 * Time: 2:13 PM
 * To change this template use File | Settings | File Templates.
 */

namespace Composer\Test\Util;

use Composer\Test\Util\TestingPerforce;
use Composer\Util\ProcessExecutor;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStream;


class PerforceTest extends \PHPUnit_Framework_TestCase {

    protected $perforce;
    protected $processExecutor;

    public function setUp() {
        $this->processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $repoConfig = array("depot"=>"depot", "branch"=>"branch", "p4user"=>"user");
        $this->perforce = new TestingPerforce($repoConfig, "port", "path", $this->processExecutor);
    }

    public function testGetClientWithoutStream() {
        $client = $this->perforce->testGetClient();
        $expected = "composer_perforce_TEST_depot";
        $this->assertEquals($expected, $client);
    }

    public function testGetClientFromStream() {
        $this->perforce->setDepotType("stream");
        $client = $this->perforce->testGetClient();

        $expected = "composer_perforce_TEST_depot_branch";
        $this->assertEquals($expected, $client);
    }

    public function testGetStreamWithoutStream() {
        $stream = $this->perforce->testGetStream();
        $this->assertEquals("//depot", $stream);
    }

    public function testGetStreamWithStream() {
        $this->perforce->setDepotType("stream");
        $stream = $this->perforce->testGetStream();
        $this->assertEquals("//depot/branch", $stream);
    }

    public function testGetStreamWithoutLabel() {
        $stream = $this->perforce->testGetStreamWithoutLabel();
        $this->assertEquals("//depot", $stream);
        $this->perforce->setDepotType("stream");
        $stream = $this->perforce->testGetStreamWithoutLabel();
        $this->assertEquals("//depot/branch", $stream);
        $this->perforce->setStream("//depot/branching@label");
        $stream = $this->perforce->testGetStreamWithoutLabel();
        $this->assertEquals("//depot/branching", $stream);
    }

    public function testGetClientSpec() {
        $clientSpec = $this->perforce->testGetClientSpec();
        $expected = "path/composer_perforce_TEST_depot.p4.spec";
        $this->assertEquals($expected, $clientSpec);
    }

    public function testGenerateP4Command() {
        $command = "do something";
        $p4Command = $this->perforce->testGenerateP4Command($command);
        $expected = "p4 -u user -c composer_perforce_TEST_depot -p port do something";
        $this->assertEquals($expected, $p4Command);
    }

    public function testQueryP4UserWithUserAlreadySet(){
        $io = $this->getMock('Composer\IO\IOInterface');

        $this->perforce->setP4User("TEST_USER");
        $this->perforce->queryP4user($io);
        $this->assertEquals("TEST_USER", $this->perforce->getUser());
    }

    public function testQueryP4UserWithUserSetInP4VariablesWithWindowsOS(){
        $this->perforce->windows_flag = true;

        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedCommand = "p4 set";
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will($this->returnCallback(function($command, &$output) {$output =  "P4USER=TEST_P4VARIABLE_USER\n"; return true;}));

        $this->perforce->setP4User(null);
        $this->perforce->queryP4user($io);
        $this->assertEquals("TEST_P4VARIABLE_USER", $this->perforce->getUser());
    }

    public function testQueryP4UserWithUserSetInP4VariablesNotWindowsOS(){
        $this->perforce->windows_flag = false;

        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedCommand = 'echo $P4USER';
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "TEST_P4VARIABLE_USER\n"; return true;}));

        $this->perforce->setP4User(null);
        $this->perforce->queryP4user($io);
        $this->assertEquals("TEST_P4VARIABLE_USER", $this->perforce->getUser());
    }

    public function testQueryP4UserQueriesForUser(){
        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedQuestion = "Enter P4 User:";
        $io->expects($this->at(0))
            ->method('ask')
            ->with($this->equalTo($expectedQuestion))
            ->will($this->returnValue("TEST_QUERY_USER"));

        $this->perforce->setP4User(null);
        $this->perforce->queryP4user($io);
        $this->assertEquals("TEST_QUERY_USER", $this->perforce->getUser());
    }

    public function testQueryP4UserStoresResponseToQueryForUserWithWindows(){
        $this->perforce->windows_flag = true;

        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedQuestion = "Enter P4 User:";
        $io->expects($this->at(0))
            ->method('ask')
            ->with($this->equalTo($expectedQuestion))
            ->will($this->returnValue("TEST_QUERY_USER"));
        $expectedCommand = "p4 set P4USER=TEST_QUERY_USER";
        $this->processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will($this->returnValue(0));

        $this->perforce->setP4User(null);
        $this->perforce->queryP4user($io);
    }

    public function testQueryP4UserStoresResponseToQueryForUserWithoutWindows(){
        $this->perforce->windows_flag = false;

        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedQuestion = "Enter P4 User:";
        $io->expects($this->at(0))
        ->method('ask')
        ->with($this->equalTo($expectedQuestion))
        ->will($this->returnValue("TEST_QUERY_USER"));
        $expectedCommand = "export P4USER=TEST_QUERY_USER";
        $this->processExecutor->expects($this->at(1))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnValue(0));

        $this->perforce->setP4User(null);
        $this->perforce->queryP4user($io);
    }

    public function testQueryP4PasswordWithPasswordAlreadySet(){
        $io = $this->getMock('Composer\IO\IOInterface');

        $this->perforce->setP4Password("TEST_PASSWORD");
        $password = $this->perforce->testQueryP4Password($io);
        $this->assertEquals("TEST_PASSWORD", $password);
    }

    public function testQueryP4PasswordWithPasswordSetInP4VariablesWithWindowsOS(){
        $this->perforce->windows_flag = true;

        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedCommand = "p4 set";
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand))
            ->will($this->returnCallback(function($command, &$output) {$output =  "P4PASSWD=TEST_P4VARIABLE_PASSWORD\n"; return true;}));

        $this->perforce->setP4Password(null);
        $password = $this->perforce->testQueryP4Password($io);
        $this->assertEquals("TEST_P4VARIABLE_PASSWORD", $password);
    }

    public function testQueryP4PasswordWithPasswordSetInP4VariablesNotWindowsOS(){
        $this->perforce->windows_flag = false;

        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedCommand = 'echo $P4PASSWD';
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "TEST_P4VARIABLE_PASSWORD\n"; return true;}));

        $this->perforce->setP4Password(null);
        $password = $this->perforce->testQueryP4Password($io);
        $this->assertEquals("TEST_P4VARIABLE_PASSWORD", $password);
    }

    public function testQueryP4PasswordQueriesForPassword(){
        $io = $this->getMock('Composer\IO\IOInterface');
        $expectedQuestion = "Enter password for Perforce user user: ";
        $io->expects($this->at(0))
            ->method('askAndHideAnswer')
            ->with($this->equalTo($expectedQuestion))
            ->will($this->returnValue("TEST_QUERY_PASSWORD"));

        $this->perforce->setP4Password(null);
        $password = $this->perforce->testQueryP4Password($io);
        $this->assertEquals("TEST_QUERY_PASSWORD", $password);
    }

    public function testWriteP4ClientSpecWithoutStream() {
        vfsStreamWrapper::register();
        VfsStreamWrapper::setRoot(new vfsStreamDirectory("path"));
        $clientSpec = $this->perforce->testGetClientSpec();
        $this->perforce->writeP4ClientSpec();
        $spec = fopen(vfsStream::url($clientSpec), 'r');
        $expectedArray = $this->getExpectedClientSpec(FALSE);
        try {
            foreach ($expectedArray as $expected) {
                $this->assertStringStartsWith($expected, fgets($spec));
            }
            $this->assertFalse(fgets($spec));
        } catch (Exception $e) {
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }

    public function testWriteP4ClientSpecWithStream() {
        vfsStreamWrapper::register();
        VfsStreamWrapper::setRoot(new vfsStreamDirectory("path"));
        $this->perforce->setStream("//depot/branching@label");
        $clientSpec = $this->perforce->testGetClientSpec();
        $this->perforce->writeP4ClientSpec();
        $spec = fopen(vfsStream::url($clientSpec), 'r');
        $expectedArray = $this->getExpectedClientSpec(TRUE);
        try {
            foreach ($expectedArray as $expected) {
                $this->assertStringStartsWith($expected, fgets($spec));
            }
            $this->assertFalse(fgets($spec));
        } catch (Exception $e) {
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }

    public function testIsLoggedIn() {
        $expectedCommand = $this->winCompat("p4 -u user -p port login -s");
        $this->processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(0));

        $this->perforce->testIsLoggedIn();
    }

    public function testConnectClient() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot -p port client -i < path/composer_perforce_TEST_depot.p4.spec");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand), $this->equalTo(null))
        ->will($this->returnValue(0));

        $this->perforce->connectClient();
    }

    public function testGetBranchesWithStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot_branchlabel -p port streams //depot/...");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "Stream //depot/branch mainline none 'branch'\n"; return true;}));

        $this->perforce->setStream("//depot/branch@label");
        $branches = $this->perforce->getBranches();
        $this->assertEquals("//depot/branch", $branches['master']);
    }

    public function testGetBranchesWithoutStream() {
        $expectedCommand = $this->winCompat("p4 -u user -p port depots");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "Depot depot 2013/01/28 local /path/to/depots/depot/... 'depot project'\n"; return true;}));

        $result = $this->perforce->checkStream("depot");
        $this->assertFalse($result);

        $branches = $this->perforce->getBranches();
        $this->assertEquals("//depot", $branches['master']);
    }

    public function testGetTagsWithoutStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot -p port labels");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "Label 0.0.1 2013/07/31 'First Label!'\nLabel 0.0.2 2013/08/01 'Second Label!'\n"; return true;}));

        $tags = $this->perforce->getTags();
        $this->assertEquals("//depot@0.0.1", $tags['0.0.1']);
        $this->assertEquals("//depot@0.0.2", $tags['0.0.2']);
    }

    public function testGetTagsWithStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot_branch -p port labels");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "Label 0.0.1 2013/07/31 'First Label!'\nLabel 0.0.2 2013/08/01 'Second Label!'\n"; return true;}));

        $this->perforce->setStream("//depot/branch");
        $tags = $this->perforce->getTags();
        $this->assertEquals("//depot/branch@0.0.1", $tags['0.0.1']);
        $this->assertEquals("//depot/branch@0.0.2", $tags['0.0.2']);
    }

    public function testCheckStreamWithoutStream() {
        $this->perforce->commandReturnValue = "Depot depot 2013/01/28 local /path/to/depots/depot/... 'depot project'";
        $result = $this->perforce->checkStream("depot");
        $this->assertFalse($result);
        $this->assertFalse($this->perforce->testIsStream());
    }

    public function testCheckStreamWithStream() {
        $line1 = "Depot depot 2013/01/28 branch /path/to/depots/depot/... 'depot project'\n";
        $line2 = "Depot depot 2013/01/28 development /path/to/depots/depot/... 'depot project'\n";
        $this->perforce->commandReturnValue = $line1 . $line2;
        $result = $this->perforce->checkStream("depot");
        $this->assertFalse($result);
        $this->assertFalse($this->perforce->testIsStream());
    }

    public function testGetComposerInformationWithoutLabelWithoutStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot -p port  print //depot/composer.json");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  PerforceTest::getComposerJson(); return true;}));

        $result = $this->perforce->getComposerInformation("//depot");
        $expected = array(
            "name"              => "test/perforce",
            "description"       => "Basic project for testing",
            "minimum-stability" => "dev",
            "autoload"          => array("psr-0" => array())
        );
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithLabelWithoutStream() {
        $expectedCommand = $this->winCompat("p4 -u user -p port  files //depot/composer.json@0.0.1");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "//depot/composer.json#1 - branch change 10001 (text)"; return true;}));

        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot -p port  print //depot/composer.json@10001");
        $this->processExecutor->expects($this->at(1))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  PerforceTest::getComposerJson(); return true;}));

        $result = $this->perforce->getComposerInformation("//depot@0.0.1");

        $expected = array(
            "name"              => "test/perforce",
            "description"       => "Basic project for testing",
            "minimum-stability" => "dev",
            "autoload"          => array("psr-0" => array())
        );
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithoutLabelWithStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot_branch -p port  print //depot/branch/composer.json");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  PerforceTest::getComposerJson(); return true;}));

        $this->perforce->setStream("//depot/branch");
        $result = $this->perforce->getComposerInformation("//depot/branch");

        $expected = array(
            "name"              => "test/perforce",
            "description"       => "Basic project for testing",
            "minimum-stability" => "dev",
            "autoload"          => array("psr-0" => array())
        );
        $this->assertEquals($expected, $result);
    }

    public function testGetComposerInformationWithLabelWithStream() {
        $expectedCommand = $this->winCompat("p4 -u user -p port  files //depot/branch/composer.json@0.0.1");
        $this->processExecutor->expects($this->at(0))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  "//depot/composer.json#1 - branch change 10001 (text)"; return true;}));

        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot_branch -p port  print //depot/branch/composer.json@10001");
        $this->processExecutor->expects($this->at(1))
        ->method('execute')
        ->with($this->equalTo($expectedCommand))
        ->will($this->returnCallback(function($command, &$output) {$output =  PerforceTest::getComposerJson(); return true;}));

        $this->perforce->setStream("//depot/branch");
        $result = $this->perforce->getComposerInformation("//depot/branch@0.0.1");

        $expected = array(
            "name"              => "test/perforce",
            "description"       => "Basic project for testing",
            "minimum-stability" => "dev",
            "autoload"          => array("psr-0" => array())
        );
        $this->assertEquals($expected, $result);
    }

    public function testSyncCodeBaseWithoutStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot -p port sync -f @label");
        $this->processExecutor->expects($this->at(1))
        ->method('execute')
        ->with($this->equalTo($expectedCommand), $this->equalTo(null))
        ->will($this->returnValue(0));

        $this->perforce->syncCodeBase("label");
    }

    public function testSyncCodeBaseWithStream() {
        $expectedCommand = $this->winCompat("p4 -u user -c composer_perforce_TEST_depot_branch -p port sync -f @label");
        $this->processExecutor->expects($this->at(1))
        ->method('execute')
        ->with($this->equalTo($expectedCommand), $this->equalTo(null))
        ->will($this->returnValue(0));

        $this->perforce->setStream("//depot/branch");
        $this->perforce->syncCodeBase("label");
    }

    public function testCheckServerExists() {
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedCommand = $this->winCompat("p4 -p perforce.does.exist:port info -s");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue(0));

        $result = $this->perforce->checkServerExists("perforce.does.exist:port", $processExecutor);
        $this->assertTrue($result);
    }

    public function testCheckServerExistsWithFailure() {
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedCommand = $this->winCompat("p4 -p perforce.does.not.exist:port info -s");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedCommand), $this->equalTo(null))
            ->will($this->returnValue("Perforce client error:"));

        $result = $this->perforce->checkServerExists("perforce.does.not.exist:port", $processExecutor);
        $this->assertTrue($result);
    }

    public static function getComposerJson() {
        $composer_json = array(
            '{',
            '"name": "test/perforce",',
            '"description": "Basic project for testing",',
            '"minimum-stability": "dev",',
            '"autoload": {',
            '"psr-0" : {',
            '}',
            '}',
            '}'
        );

        return implode($composer_json);
    }

    private function getExpectedClientSpec($withStream) {
        $expectedArray = array(
            "Client: composer_perforce_TEST_depot",
            "\n",
            "Update:",
            "\n",
            "Access:",
            "Owner:  user",
            "\n",
            "Description:",
            "  Created by user from composer.",
            "\n",
            "Root: path",
            "\n",
            "Options:  noallwrite noclobber nocompress unlocked modtime rmdir",
            "\n",
            "SubmitOptions:  revertunchanged",
            "\n",
            "LineEnd:  local",
            "\n"
        );
        if ($withStream) {
            $expectedArray[] = "Stream:";
            $expectedArray[] = "  //depot/branching";
        }
        else {
            $expectedArray[] = "View:  //depot/...  //composer_perforce_TEST_depot/depot/...";
        }

        return $expectedArray;
    }

    private function winCompat($cmd) {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $cmd = str_replace('cd ', 'cd /D ', $cmd);
            $cmd = str_replace('composerPath', getcwd() . '/composerPath', $cmd);

            return strtr($cmd, "'", '"');
        }

        return $cmd;
    }

}

