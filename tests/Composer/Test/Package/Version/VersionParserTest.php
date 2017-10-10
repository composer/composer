<?php

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionParser;

class VersionParserTest extends \PHPUnit_Framework_TestCase
{
    /** @var VersionParser */
    private $versionParser;

    protected function setUp()
    {
        $this->versionParser = new VersionParser();
    }

    /**
     * @dataProvider packageNameProvider
     *
     * @param array $input
     * @param array $expected
     */
    public function testParseNameVersionPairs($input, $expected)
    {
        $versionParser = $this->versionParser;

        $actual = $versionParser->parseNameVersionPairs($input);

        self::assertSame($expected, $actual);
    }

    public function packageNameProvider()
    {
        return array(
            'Package with version, two fields' => array(
                array('foo/bar', '1.0.0'),
                array(array('name' => 'foo/bar', 'version' => '1.0.0'))
            ),
            'Package with version, space separated' => array(
                array('foo/bar 1.0.0'),
                array(array('name' => 'foo/bar', 'version' => '1.0.0'))
            ),
            'Package with version, colon separated' => array(
                array('foo/bar:1.0.0'),
                array(array('name' => 'foo/bar', 'version' => '1.0.0'))
            ),
            'Package with version, equals separated' => array(
                array('foo/bar=1.0.0'),
                array(array('name' => 'foo/bar', 'version' => '1.0.0'))
            ),
            'Packages without version' => array(
                array('foo/bar', 'foo/baz'),
                array(array('name' => 'foo/bar'), array('name' => 'foo/baz'))
            ),
            'Platform packages' => array(
                array('ext-acpu', 'ext-ds'),
                array(array('name' => 'ext-acpu'), array('name' => 'ext-ds'))
            ),
        );
    }
}
