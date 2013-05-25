<?php

namespace Composer\Test\Autoload;

use Composer\Command\InitCommand;
use Composer\Test\TestCase;

class InitCommandTest extends TestCase
{
    function testParseValidAuthorString()
    {
        $command = new InitCommand;
        $command->parseAuthorString('John Smith <john@example.com>');
    }

    function testParseInvalidAuthorString()
    {
        $command = new InitCommand;
        $this->setExpectedException('InvalidArgumentException');
        $command->parseAuthorString('');
    }
}
