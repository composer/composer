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

namespace Composer\Test\Package\Version;


class VersionParserTest extends \PHPUnit_Framework_TestCase
{
    public function testParseConstraintsRejectsArray()
    {
        $parser = new \Composer\Package\Version\VersionParser();
        $invalid_constraint = array();
        $did_throw_exception = false;
        $exception_message = null;
        try {
            $parser->parseConstraints($invalid_constraint);
        } catch (\UnexpectedValueException $e) {
            $did_throw_exception = true;
            $exception_message = $e->getMessage();
        }
        $this->assertEquals($did_throw_exception, true);
        $this->assertEquals($exception_message, 'Version constraint must be string.');
    }
}
