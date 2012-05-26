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

namespace Composer\Test\Json;

use Composer\Json\JsonManipulator;

class JsonManipulatorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider linkProvider
     */
    public function testAddLink($json, $type, $package, $constraint, $expected)
    {
        $manipulator = new JsonManipulator($json);
        $this->assertTrue($manipulator->addLink($type, $package, $constraint));
        $this->assertEquals($expected, $manipulator->getContents());
    }

    public function linkProvider()
    {
        return array(
            array(
                '{
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require": {
        "vendor/baz": "qux"
    }
}
'
            ),
            array(
                '{
    "foo": "bar"
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "foo": "bar",
    "require": {
        "vendor/baz": "qux"
    }
}
'
            ),
            array(
                '{
    "require": {
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require": {
        "vendor/baz": "qux"
    }
}
'
            ),
            array(
                '{
    "require": {
        "foo": "bar"
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require": {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
'
            ),
            array(
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "baz"
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
'
            ),
            array(
                '{
    "require":
    {
        "foo": "bar",
        "vendor\/baz": "baz"
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
'
            ),
        );
    }
}
