<?php

namespace Composer\Test\Command;

use Composer\Command\InitCommand;
use Composer\Test\TestCase;

class InitCommandTest extends TestCase
{
    function testParseValidAuthorString()
    {
        $command = new InitCommand;
        $author = $command->parseAuthorString('John Smith <john@example.com>');
        $this->assertEquals('John Smith', $author['name']);
        $this->assertEquals('john@example.com', $author['email']);
    }

    function testParseEmptyAuthorString()
    {
        $command = new InitCommand;
        $this->setExpectedException('InvalidArgumentException');
        $command->parseAuthorString('');
    }

    function testParseAuthorStringWithInvalidEmail()
    {
        $command = new InitCommand;
        $this->setExpectedException('InvalidArgumentException');
        $command->parseAuthorString('John Smith <john>');
    }
}
