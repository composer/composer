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

namespace Composer\Test\Command;

use Composer\Command\PropertySetCommand;

class PropertySetCommandTest extends \PHPUnit_Framework_TestCase
{
    private $composerEnv = './composer_test.json';
    private $origEnv;
    private $testFile;
    private $rootDir;
    private $resourcesDir;

    protected function setUp()
    {
        // getenv is internally used to customize composer.json file location
        // using it here to not modify the original file
        $this->origEnv = getenv('COMPOSER');
        putenv('COMPOSER=' . $this->composerEnv);

        $this->resourcesDir = __DIR__ . '/../../Resources/property';
        $this->rootDir = __DIR__ . '/../../../..';

        $this->testFile = $this->rootDir . DIRECTORY_SEPARATOR . '/composer_test.json';

        file_put_contents(
            $this->testFile,
            $this->readResourcesFile('composer_test.json')
        );
    }

    protected function tearDown()
    {
        // reset custom env var to not produce any side effects
        putenv('COMPOSER=' . $this->origEnv);

        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    /**
     * Exception is catched within command and error is added to output
     *
     * @group exception
     */
    public function testFileException()
    {
        $filesystemHelper = $this->getFilesystemHelper();

        $filesystemHelper->expects($this->once())
            ->method('ensureFileExists')
            ->with($this->composerEnv)
            ->will(
                $this->returnCallback(
                    function () {
                        throw new \RuntimeException('foo exception');
                    }
                )
            );

        $input = $this->getInput();

        $output = $this->getOutput();

        $output->expects($this->once())
            ->method('writeln')
            ->with('<error>foo exception</error>');


        $command = new PropertySetCommand();
        $command->setHelperSet($this->getHelperSet($filesystemHelper));
        $command->run($input, $output);
    }

    public function testAbortsWithInvalidProperty()
    {
        $filesystemHelper = $this->getFilesystemHelper();

        $filesystemHelper->expects($this->once())
            ->method('ensureFileExists')
            ->with($this->composerEnv);

        $input = $this->getInput();

        // input calls done in parent Command class
        // 0: $input->bind()
        // 1: $input->isInteractive()
        // 2: $input->validate()
        // -> "at" index call starts by 3 in child Command classes
        $input->expects($this->at(3))
            ->method('getArgument')
            ->with('name')
            ->will($this->returnValue('invalid'));

        $output = $this->getOutput();

        $output->expects($this->once())
            ->method('writeln')
            ->with(
                $this->callback(
                    function ($e) {
                        return (bool)preg_match("/<error>property \"invalid\" is not supported/", $e);
                    }
                )
            );

        $command = new PropertySetCommand();
        $command->setHelperSet($this->getHelperSet($filesystemHelper));
        $command->setComposer($this->getComposer());
        $command->run($input, $output);
    }

    /**
     * @dataProvider provideFiles
     */
    public function testSetWithWhitelistedProperty($name, $value, $checkFile)
    {
        $filesystemHelper = $this->getFilesystemHelper();

        $filesystemHelper->expects($this->once())
            ->method('ensureFileExists')
            ->with($this->composerEnv);

        $input = $this->getInput();

        // input calls done in parent Command class
        // 0: $input->bind()
        // 1: $input->isInteractive()
        // 2: $input->validate()
        // -> "at" index call starts by 3 in child Command classes
        $input->expects($this->at(3))
            ->method('getArgument')
            ->with('name')
            ->will($this->returnValue($name));

        $input->expects($this->at(4))
            ->method('getArgument')
            ->with('value')
            ->will($this->returnValue($value));

        $output = $this->getOutput();

        $command = new PropertySetCommand();
        $command->setHelperSet($this->getHelperSet($filesystemHelper));
        $command->setComposer($this->getComposer());
        $command->run($input, $output);

        $this->assertEquals($this->readResourcesFile($checkFile), $this->readTestFile());
    }

    public static function provideFiles()
    {
        return array(
            array('name', 'foo/bar', 'composer_test_changed_name.json'),
            array('homepage', 'http://foo.bar.baz', 'composer_test_changed_homepage.json'),
            array('license', 'FOO', 'composer_test_changed_license.json'),
            array('minimum-stability', 'beta', 'composer_test_changed_stability.json'),
        );
    }

    protected function getFilesystemHelper()
    {
        return $this->getMockBuilder('\Composer\Command\Helper\FilesystemHelper')
            ->disableOriginalConstructor()
            ->setMethods(array('ensureFileExists'))
            ->getMock();
    }

    protected function getHelperSet($filesystemHelper)
    {
        $helperSet = $this->getMockBuilder('\Symfony\Component\Console\Helper\HelperSet')
            ->disableOriginalConstructor()
            ->setMethods(array('get'))
            ->getMock();

        $helperSet->expects($this->atLeastOnce())
            ->method('get')
            ->with('filesystem')
            ->will($this->returnValue($filesystemHelper));

        return $helperSet;
    }

    protected function getInput()
    {
        return $this->getMockBuilder('\Symfony\Component\Console\Input\InputInterface')
            ->getMockForAbstractClass();
    }

    protected function getOutput()
    {
        return $this->getMockBuilder('\Symfony\Component\Console\Output\OutputInterface')
            ->getMockForAbstractClass();
    }

    protected function getComposer()
    {
        $eventDispatcher = $this->getMockBuilder('\Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->setMethods(array('dispatch'))
            ->getMock();

        $composer = $this->getMockBuilder('\Composer\Composer')
            ->disableOriginalConstructor()
            ->setMethods(array('getEventDispatcher'))
            ->getMock();

        $composer->expects($this->atLeastOnce())
            ->method('getEventDispatcher')
            ->will($this->returnValue($eventDispatcher));

        return $composer;
    }

    protected function readResourcesFile($file)
    {
        return file_get_contents($this->resourcesDir . DIRECTORY_SEPARATOR . $file);
    }

    protected function readTestFile()
    {
        return file_get_contents($this->testFile);
    }
}
