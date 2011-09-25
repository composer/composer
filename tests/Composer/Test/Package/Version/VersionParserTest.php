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

use Composer\Package\Version\VersionParser;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;

class VersionParserTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider successfulNormalizedVersions
     */
    public function testNormalizeSucceeds($input, $expected)
    {
        $parser = new VersionParser;
        $this->assertEquals($expected, $parser->normalize($input));
    }

    public function successfulNormalizedVersions()
    {
        return array(
            'none'              => array('1.0.0',               '1.0.0'),
            'parses state'      => array('1.0.0RC1dev',         '1.0.0-rc1-dev'),
            'CI parsing'        => array('1.0.0-rC15-dev',      '1.0.0-rc15-dev'),
            'forces x.y.z'      => array('1.0-dev',             '1.0.0-dev'),
            'parses long'       => array('10.4.13-beta',        '10.4.13-beta'),
            'strips leading v'  => array('v1.0.0',              '1.0.0'),
            'strips leading v'  => array('v20100102',           '20100102'),
            'parses dates w/ .' => array('2010.01.02',          '2010-01-02'),
            'parses dates w/ -' => array('2010-01-02',          '2010-01-02'),
            'parses numbers'    => array('2010-01-02.5',        '2010-01-02-5'),
            'parses datetime'   => array('20100102-203040',     '20100102-203040'),
            'parses dt+number'  => array('20100102203040-10',   '20100102203040-10'),
        );
    }

    /**
     * @dataProvider failingNormalizedVersions
     * @expectedException UnexpectedValueException
     */
    public function testNormalizeFails($input)
    {
        $parser = new VersionParser;
        $parser->normalize($input);
    }

    public function failingNormalizedVersions()
    {
        return array(
            'empty '            => array(''),
            'invalid chars'     => array('a'),
            'invalid type'      => array('1.0.0-meh'),
            'too many bits'     => array('1.0.0.0'),
        );
    }

    /**
     * @dataProvider simpleConstraints
     */
    public function testParseConstraintsSimple($input, $expected)
    {
        $parser = new VersionParser;
        $this->assertEquals((string) $expected, (string) $parser->parseConstraints($input));
    }

    public function simpleConstraints()
    {
        return array(
            'greater than'      => array('>1.0.0',      new VersionConstraint('>', '1.0.0')),
            'lesser than'       => array('<1.2.3',      new VersionConstraint('<', '1.2.3')),
            'less/eq than'      => array('<=1.2.3',     new VersionConstraint('<=', '1.2.3')),
            'great/eq than'     => array('>=1.2.3',     new VersionConstraint('>=', '1.2.3')),
            'equals'            => array('=1.2.3',      new VersionConstraint('=', '1.2.3')),
            'double equals'     => array('==1.2.3',     new VersionConstraint('=', '1.2.3')),
            'no op means eq'    => array('1.2.3',       new VersionConstraint('=', '1.2.3')),
            'completes version' => array('=1.0',        new VersionConstraint('=', '1.0.0')),
            'accepts spaces'    => array('>= 1.2.3',    new VersionConstraint('>=', '1.2.3')),
        );
    }

    /**
     * @dataProvider wildcardConstraints
     */
    public function testParseConstraintsWildcard($input, $min, $max)
    {
        $parser = new VersionParser;
        $expected = new MultiConstraint(array($min, $max));

        $this->assertEquals((string) $expected, (string) $parser->parseConstraints($input));
    }

    public function wildcardConstraints()
    {
        return array(
            array('2.*',     new VersionConstraint('>=', '2.0.0'), new VersionConstraint('<', '3.0.0')),
            array('20.*',    new VersionConstraint('>=', '20.0.0'), new VersionConstraint('<', '21.0.0')),
            array('2.0.*',   new VersionConstraint('>=', '2.0.0'), new VersionConstraint('<', '2.1.0')),
            array('2.2.*',   new VersionConstraint('>=', '2.2.0'), new VersionConstraint('<', '2.3.0')),
            array('2.10.*',  new VersionConstraint('>=', '2.10.0'), new VersionConstraint('<', '2.11.0')),
        );
    }

    /**
     * @dataProvider failingConstraints
     * @expectedException UnexpectedValueException
     */
    public function testParseConstraintsFails($input)
    {
        $parser = new VersionParser;
        $parser->parseConstraints($input);
    }

    public function failingConstraints()
    {
        return array(
            'empty '            => array(''),
            'invalid version'   => array('1.0.0-meh'),
        );
    }
}
