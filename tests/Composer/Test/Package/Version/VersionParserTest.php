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
        $this->assertSame($expected, $parser->normalize($input));
    }

    public function successfulNormalizedVersions()
    {
        return array(
            'none'              => array('1.0.0',               '1.0.0.0'),
            'none/2'            => array('1.2.3.4',             '1.2.3.4'),
            'parses state'      => array('1.0.0RC1dev',         '1.0.0.0-RC1-dev'),
            'CI parsing'        => array('1.0.0-rC15-dev',      '1.0.0.0-RC15-dev'),
            'delimiters'        => array('1.0.0.RC.15-dev',     '1.0.0.0-RC15-dev'),
            'RC uppercase'      => array('1.0.0-rc1',           '1.0.0.0-RC1'),
            'patch replace'     => array('1.0.0.pl3-dev',       '1.0.0.0-patch3-dev'),
            'forces w.x.y.z'    => array('1.0-dev',             '1.0.0.0-dev'),
            'forces w.x.y.z/2'  => array('0',                   '0.0.0.0'),
            'parses long'       => array('10.4.13-beta',        '10.4.13.0-beta'),
            'strips leading v'  => array('v1.0.0',              '1.0.0.0'),
            'strips v/datetime' => array('v20100102',           '20100102'),
            'parses dates y-m'  => array('2010.01',             '2010-01'),
            'parses dates w/ .' => array('2010.01.02',          '2010-01-02'),
            'parses dates w/ -' => array('2010-01-02',          '2010-01-02'),
            'parses numbers'    => array('2010-01-02.5',        '2010-01-02-5'),
            'parses datetime'   => array('20100102-203040',     '20100102-203040'),
            'parses dt+number'  => array('20100102203040-10',   '20100102203040-10'),
            'parses dt+patch'   => array('20100102-203040-p1',  '20100102-203040-patch1'),
            'parses master'     => array('dev-master',          '9999999-dev'),
            'parses trunk'      => array('dev-trunk',           '9999999-dev'),
            'parses arbitrary'  => array('dev-feature-foo',     'dev-feature-foo'),
            'parses arbitrary2' => array('DEV-FOOBAR',          'dev-foobar'),
            'ignores aliases'   => array('dev-master as 1.0.0', '1.0.0.0'),
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
            'too many bits'     => array('1.0.0.0.0'),
            'non-dev arbitrary' => array('feature-foo'),
        );
    }

    /**
     * @dataProvider successfulNormalizedBranches
     */
    public function testNormalizeBranch($input, $expected)
    {
        $parser = new VersionParser;
        $this->assertSame((string) $expected, (string) $parser->normalizeBranch($input));
    }

    public function successfulNormalizedBranches()
    {
        return array(
            'parses x'              => array('v1.x',        '1.9999999.9999999.9999999-dev'),
            'parses *'              => array('v1.*',        '1.9999999.9999999.9999999-dev'),
            'parses digits'         => array('v1.0',        '1.0.9999999.9999999-dev'),
            'parses digits/2'       => array('2.0',         '2.0.9999999.9999999-dev'),
            'parses long x'         => array('v1.0.x',      '1.0.9999999.9999999-dev'),
            'parses long *'         => array('v1.0.3.*',    '1.0.3.9999999-dev'),
            'parses long digits'    => array('v2.4.0',      '2.4.0.9999999-dev'),
            'parses long digits/2'  => array('2.4.4',       '2.4.4.9999999-dev'),
            'parses master'         => array('master',      '9999999-dev'),
            'parses trunk'          => array('trunk',       '9999999-dev'),
            'parses arbitrary'      => array('feature-a',   'dev-feature-a'),
            'parses arbitrary/2'    => array('foobar',      'dev-foobar'),
        );
    }

    /**
     * @dataProvider simpleConstraints
     */
    public function testParseConstraintsSimple($input, $expected)
    {
        $parser = new VersionParser;
        $this->assertSame((string) $expected, (string) $parser->parseConstraints($input));
    }

    public function simpleConstraints()
    {
        return array(
            'greater than'      => array('>1.0.0',      new VersionConstraint('>', '1.0.0.0')),
            'lesser than'       => array('<1.2.3.4',    new VersionConstraint('<', '1.2.3.4')),
            'less/eq than'      => array('<=1.2.3',     new VersionConstraint('<=', '1.2.3.0')),
            'great/eq than'     => array('>=1.2.3',     new VersionConstraint('>=', '1.2.3.0')),
            'equals'            => array('=1.2.3',      new VersionConstraint('=', '1.2.3.0')),
            'double equals'     => array('==1.2.3',     new VersionConstraint('=', '1.2.3.0')),
            'no op means eq'    => array('1.2.3',       new VersionConstraint('=', '1.2.3.0')),
            'completes version' => array('=1.0',        new VersionConstraint('=', '1.0.0.0')),
            'accepts spaces'    => array('>= 1.2.3',    new VersionConstraint('>=', '1.2.3.0')),
            'accepts master'    => array('>=dev-master',    new VersionConstraint('>=', '9999999-dev')),
            'accepts master/2'  => array('dev-master',      new VersionConstraint('=', '9999999-dev')),
            'accepts arbitrary' => array('dev-feature-a',   new VersionConstraint('=', 'dev-feature-a')),
            'ignores aliases'   => array('dev-master as 1.0.0', new VersionConstraint('=', '1.0.0.0')),
        );
    }

    /**
     * @dataProvider wildcardConstraints
     */
    public function testParseConstraintsWildcard($input, $min, $max)
    {
        $parser = new VersionParser;
        if ($min) {
            $expected = new MultiConstraint(array($min, $max));
        } else {
            $expected = $max;
        }

        $this->assertSame((string) $expected, (string) $parser->parseConstraints($input));
    }

    public function wildcardConstraints()
    {
        return array(
            array('2.*',     new VersionConstraint('>', '1.9999999.9999999.9999999'), new VersionConstraint('<', '2.9999999.9999999.9999999')),
            array('20.*',    new VersionConstraint('>', '19.9999999.9999999.9999999'), new VersionConstraint('<', '20.9999999.9999999.9999999')),
            array('2.0.*',   new VersionConstraint('>', '1.9999999.9999999.9999999'), new VersionConstraint('<', '2.0.9999999.9999999')),
            array('2.2.x',   new VersionConstraint('>', '2.1.9999999.9999999'), new VersionConstraint('<', '2.2.9999999.9999999')),
            array('2.10.x',  new VersionConstraint('>', '2.9.9999999.9999999'), new VersionConstraint('<', '2.10.9999999.9999999')),
            array('2.1.3.*', new VersionConstraint('>', '2.1.2.9999999'), new VersionConstraint('<', '2.1.3.9999999')),
            array('0.*',     null, new VersionConstraint('<', '0.9999999.9999999.9999999')),
        );
    }

    public function testParseConstraintsMulti()
    {
        $parser = new VersionParser;
        $first = new VersionConstraint('>', '2.0.0.0');
        $second = new VersionConstraint('<=', '3.0.0.0');
        $multi = new MultiConstraint(array($first, $second));
        $this->assertSame((string) $multi, (string) $parser->parseConstraints('>2.0,<=3.0'));
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

    /**
     * @dataProvider dataIsDev
     */
    public function testIsDev($expected, $version)
    {
        $this->assertSame($expected, VersionParser::isDev($version));
    }

    public function dataIsDev()
    {
        return array(
            array(false, '1.0'),
            array(false, 'v2.0.*'),
            array(false, '3.0dev'),
            array(true, 'dev-master'),
            array(true, '3.1.2-dev'),
        );
    }
}
