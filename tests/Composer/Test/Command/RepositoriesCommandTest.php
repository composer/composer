<?php
namespace Composer\Test;
use Composer\Command\RepositoriesCommand;

class RepositoriesCommandTest extends \PHPUnit_Framework_TestCase
{
    public function testSelf()
    {
        $this->assertEquals(1,1);
    }
    
    public function testInstantiate()
    {
        $cmd = new RepositoriesCommand;
        $this->assertTrue(is_object($cmd));
    }
 
    public function testHasBaseFourActions()
    {
        $cmd = new RepositoriesCommand;
        $actions = $cmd->getAllowedActions();
        
        $this->assertTrue(in_array('add', $actions));
        $this->assertTrue(in_array('remove', $actions));
        $this->assertTrue(in_array('packagist', $actions));
        $this->assertTrue(in_array('list', $actions));
    }
}
