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

use Composer\Json\JsonFile;

class JsonFileTest extends \PHPUnit_Framework_TestCase
{
    public function testParseErrorDetectExtraComma()
    {
        $json = '{
        "foo": "bar",
}';
        $this->expectParseException('extra comma on line 2, char 21', $json);
    }

    public function testParseErrorDetectExtraCommaInArray()
    {
        $json = '{
        "foo": [
            "bar",
        ]
}';
        $this->expectParseException('extra comma on line 3, char 18', $json);
    }

    public function testParseErrorDetectUnescapedBackslash()
    {
        $json = '{
        "fo\o": "bar"
}';
        $this->expectParseException('unescaped backslash (\\) on line 2, char 12', $json);
    }

    public function testParseErrorSkipsEscapedBackslash()
    {
        $json = '{
        "fo\\\\o": "bar"
        "a": "b"
}';
        $this->expectParseException('missing comma on line 2, char 23', $json);
    }

    public function testParseErrorDetectSingleQuotes()
    {
        $json = '{
        \'foo\': "bar"
}';
        $this->expectParseException('use double quotes (") instead of single quotes (\') on line 2, char 9', $json);
    }

    public function testParseErrorDetectMissingQuotes()
    {
        $json = '{
        foo: "bar"
}';
        $this->expectParseException('must use double quotes (") around keys on line 2, char 9', $json);
    }

    public function testParseErrorDetectArrayAsHash()
    {
        $json = '{
        "foo": ["bar": "baz"]
}';
        $this->expectParseException('you must use the hash syntax (e.g. {"foo": "bar"}) instead of array syntax (e.g. ["foo", "bar"]) on line 2, char 16', $json);
    }

    public function testParseErrorDetectMissingComma()
    {
        $json = '{
        "foo": "bar"
        "bar": "foo"
}';
        $this->expectParseException('missing comma on line 2, char 21', $json);
    }

    public function testSimpleJsonString()
    {
        $data = array('name' => 'composer/composer');
        $json = '{
    "name": "composer\/composer"
}';
        $this->assertJsonFormat($json, $data);
    }

    public function testTrailingBackslash()
    {
        $data = array('Metadata\\' => 'src/');
        $json = '{
    "Metadata\\\\": "src\/"
}';
        $this->assertJsonFormat($json, $data);
    }

    private function expectParseException($text, $json)
    {
        try {
            JsonFile::parseJson($json);
            $this->fail();
        } catch (\UnexpectedValueException $e) {
            $this->assertContains($text, $e->getMessage());
        }
    }

    private function assertJsonFormat($json, $data)
    {
        $file = new JsonFile('composer.json');

        $this->assertEquals($json, $file->encode($data));
    }

}
