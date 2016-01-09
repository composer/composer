<?php

use Composer\TestCase;
use Composer\Command\Show\Constraints;
use Composer\Repository\RepositoryInterface;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;

class ConstraintsTest extends TestCase
{

    /**
     * @var Constraints
     */
    private $constraints;

    protected function setUp()
    {
        $this->constraints = new Constraints($this->getMockForRepos(), $this->getMockForIo());
    }

    protected function tearDown()
    {
        $this->constraints = null;
    }

    public function testWriteToOutput()
    {
        $this->constraints->writeToOutput();
    }

    /**
     * @return IOInterface
     */
    private function getMockForIo()
    {
        $stub = $this->getMockBuilder('\Composer\IO\IOInterface')
            ->getMock();

        return $stub;
    }

    /**
     * @return RepositoryInterface
     */
    private function getMockForRepos()
    {
        $stub = $this->getMockBuilder('\Composer\Repository\RepositoryInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $stub->expects($this->once())
            ->method('getPackages')
            ->willReturn([
                $this->getMockForCompletePackage()
            ]);

        return $stub;
    }

    /**
     * @return CompletePackageInterface
     */
    private function getMockForCompletePackage()
    {
        $stub = $this->getMockBuilder('\Composer\Package\CompletePackageInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $stub->expects($this->once())
            ->method('getRequires')
            ->willReturn([
                $this->getMockForLink(),
                $this->getMockForLink(),
            ]);

        return $stub;
    }

    /**
     * @return Link
     */
    private function getMockForLink()
    {
        $stub = $this->getMockBuilder('\Composer\Package\Link')
            ->disableOriginalConstructor()
            ->getMock();

        $stub->expects($this->atLeast(3))
            ->method('getTarget')
            ->willReturn('phpunit/phpunit');

        $stub->expects($this->atLeast(2))
            ->method('getPrettyConstraint')
            ->willReturn('>=5.3.9');

        $stub->expects($this->any())
            ->method('getSource')
            ->willReturn('php');

        return $stub;
    }

}