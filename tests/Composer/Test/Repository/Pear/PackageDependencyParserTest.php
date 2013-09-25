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

namespace Composer\Repository\Pear;

use Composer\TestCase;

class PackageDependencyParserTest extends TestCase
{
    /**
     * @dataProvider dataProvider10
     * @param $expected
     * @param $data10
     * @param $data20
     */
    public function testShouldParseDependencies($expected, $data10, $data20)
    {
        $expectedDependencies = array();
        foreach ($expected as $expectedItem) {
            $expectedDependencies[] = new DependencyConstraint(
                $expectedItem['type'],
                $expectedItem['constraint'],
                $expectedItem['channel'],
                $expectedItem['name']
            );
        }

        $parser = new PackageDependencyParser();

        if (false !== $data10) {
            $result = $parser->buildDependencyInfo($data10);
            $this->assertEquals($expectedDependencies, $result->getRequires() + $result->getOptionals(), "Failed for package.xml 1.0 format");
        }

        if (false !== $data20) {
            $result = $parser->buildDependencyInfo($data20);
            $this->assertEquals($expectedDependencies, $result->getRequires() + $result->getOptionals(), "Failed for package.xml 2.0 format");
        }
    }

    public function dataProvider10()
    {
        $data = json_decode(file_get_contents(__DIR__.'/Fixtures/DependencyParserTestData.json'), true);
        if (0 !== json_last_error()) {
            throw new \PHPUnit_Framework_Exception('Invalid json file.');
        }

        return $data;
    }
}
