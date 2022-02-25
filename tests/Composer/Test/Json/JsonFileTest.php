<?php declare(strict_types=1);

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

use Seld\JsonLint\ParsingException;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Test\TestCase;

class JsonFileTest extends TestCase
{
    public function testParseErrorDetectExtraComma(): void
    {
        $json = '{
        "foo": "bar",
}';
        $this->expectParseException('Parse error on line 2', $json);
    }

    public function testParseErrorDetectExtraCommaInArray(): void
    {
        $json = '{
        "foo": [
            "bar",
        ]
}';
        $this->expectParseException('Parse error on line 3', $json);
    }

    public function testParseErrorDetectUnescapedBackslash(): void
    {
        $json = '{
        "fo\o": "bar"
}';
        $this->expectParseException('Parse error on line 1', $json);
    }

    public function testParseErrorSkipsEscapedBackslash(): void
    {
        $json = '{
        "fo\\\\o": "bar"
        "a": "b"
}';
        $this->expectParseException('Parse error on line 2', $json);
    }

    public function testParseErrorDetectSingleQuotes(): void
    {
        if (defined('JSON_PARSER_NOTSTRICT') && version_compare(phpversion('json'), '1.3.9', '<')) {
            $this->markTestSkipped('jsonc issue, see https://github.com/remicollet/pecl-json-c/issues/23');
        }
        $json = '{
        \'foo\': "bar"
}';
        $this->expectParseException('Parse error on line 1', $json);
    }

    public function testParseErrorDetectMissingQuotes(): void
    {
        $json = '{
        foo: "bar"
}';
        $this->expectParseException('Parse error on line 1', $json);
    }

    public function testParseErrorDetectArrayAsHash(): void
    {
        $json = '{
        "foo": ["bar": "baz"]
}';
        $this->expectParseException('Parse error on line 2', $json);
    }

    public function testParseErrorDetectMissingComma(): void
    {
        $json = '{
        "foo": "bar"
        "bar": "foo"
}';
        $this->expectParseException('Parse error on line 2', $json);
    }

    public function testSchemaValidation(): void
    {
        $json = new JsonFile(__DIR__.'/Fixtures/composer.json');
        $this->assertTrue($json->validateSchema());
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));
    }

    public function testSchemaValidationError(): void
    {
        $file = $this->createTempFile();
        file_put_contents($file, '{ "name": null }');
        $json = new JsonFile($file);
        $expectedMessage = sprintf('"%s" does not match the expected JSON schema', $file);
        $expectedError = 'name : NULL value found, but a string is required';
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $this->assertContains($expectedError, $e->getErrors());
        }
        try {
            $json->validateSchema(JsonFile::LAX_SCHEMA);
            $this->fail('Expected exception to be thrown (lax)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $this->assertContains($expectedError, $e->getErrors());
        }
        unlink($file);
    }

    public function testSchemaValidationLaxAdditionalProperties(): void
    {
        $file = $this->createTempFile();
        file_put_contents($file, '{ "name": "vendor/package", "description": "generic description", "foo": "bar" }');
        $json = new JsonFile($file);
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals(sprintf('"%s" does not match the expected JSON schema', $file), $e->getMessage());
            $this->assertEquals(array('The property foo is not defined and the definition does not allow additional properties'), $e->getErrors());
        }
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));
        unlink($file);
    }

    public function testSchemaValidationLaxRequired(): void
    {
        $file = $this->createTempFile();
        $json = new JsonFile($file);

        $expectedMessage = sprintf('"%s" does not match the expected JSON schema', $file);

        file_put_contents($file, '{ }');
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $errors = $e->getErrors();
            $this->assertContains('name : The property name is required', $errors);
            $this->assertContains('description : The property description is required', $errors);
        }
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));

        file_put_contents($file, '{ "name": "vendor/package" }');
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $this->assertEquals(array('description : The property description is required'), $e->getErrors());
        }
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));

        file_put_contents($file, '{ "description": "generic description" }');
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $this->assertEquals(array('name : The property name is required'), $e->getErrors());
        }
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));

        file_put_contents($file, '{ "type": "library" }');
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $errors = $e->getErrors();
            $this->assertContains('name : The property name is required', $errors);
            $this->assertContains('description : The property description is required', $errors);
        }
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));

        file_put_contents($file, '{ "type": "project" }');
        try {
            $json->validateSchema();
            $this->fail('Expected exception to be thrown (strict)');
        } catch (JsonValidationException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage());
            $errors = $e->getErrors();
            $this->assertContains('name : The property name is required', $errors);
            $this->assertContains('description : The property description is required', $errors);
        }
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));

        file_put_contents($file, '{ "name": "vendor/package", "description": "generic description" }');
        $this->assertTrue($json->validateSchema());
        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA));

        unlink($file);
    }

    public function testCustomSchemaValidationLax(): void
    {
        $file = $this->createTempFile();
        file_put_contents($file, '{ "custom": "property", "another custom": "property" }');

        $schema = $this->createTempFile();
        file_put_contents($schema, '{ "properties": { "custom": { "type": "string" }}}');

        $json = new JsonFile($file);

        $this->assertTrue($json->validateSchema(JsonFile::LAX_SCHEMA, $schema));

        unlink($file);
        unlink($schema);
    }

    public function testCustomSchemaValidationStrict(): void
    {
        $file = $this->createTempFile();
        file_put_contents($file, '{ "custom": "property" }');

        $schema = $this->createTempFile();
        file_put_contents($schema, '{ "properties": { "custom": { "type": "string" }}}');

        $json = new JsonFile($file);

        $this->assertTrue($json->validateSchema(JsonFile::STRICT_SCHEMA, $schema));

        unlink($file);
        unlink($schema);
    }

    public function testParseErrorDetectMissingCommaMultiline(): void
    {
        $json = '{
        "foo": "barbar"

        "bar": "foo"
}';
        $this->expectParseException('Parse error on line 2', $json);
    }

    public function testParseErrorDetectMissingColon(): void
    {
        $json = '{
        "foo": "bar",
        "bar" "foo"
}';
        $this->expectParseException('Parse error on line 3', $json);
    }

    public function testSimpleJsonString(): void
    {
        $data = array('name' => 'composer/composer');
        $json = '{
    "name": "composer/composer"
}';
        $this->assertJsonFormat($json, $data);
    }

    public function testTrailingBackslash(): void
    {
        $data = array('Metadata\\' => 'src/');
        $json = '{
    "Metadata\\\\": "src/"
}';
        $this->assertJsonFormat($json, $data);
    }

    public function testFormatEmptyArray(): void
    {
        $data = array('test' => array(), 'test2' => new \stdClass);
        $json = '{
    "test": [],
    "test2": {}
}';
        $this->assertJsonFormat($json, $data);
    }

    public function testEscape(): void
    {
        $data = array("Metadata\\\"" => 'src/');
        $json = '{
    "Metadata\\\\\\"": "src/"
}';

        $this->assertJsonFormat($json, $data);
    }

    public function testUnicode(): void
    {
        $data = array("Žluťoučký \" kůň" => "úpěl ďábelské ódy za €");
        $json = '{
    "Žluťoučký \" kůň": "úpěl ďábelské ódy za €"
}';

        $this->assertJsonFormat($json, $data);
    }

    public function testOnlyUnicode(): void
    {
        $data = "\\/ƌ";

        $this->assertJsonFormat('"\\\\\\/ƌ"', $data, JSON_UNESCAPED_UNICODE);
    }

    public function testEscapedSlashes(): void
    {
        $data = "\\/foo";

        $this->assertJsonFormat('"\\\\\\/foo"', $data, 0);
    }

    public function testEscapedBackslashes(): void
    {
        $data = "a\\b";

        $this->assertJsonFormat('"a\\\\b"', $data, 0);
    }

    public function testEscapedUnicode(): void
    {
        $data = "ƌ";

        $this->assertJsonFormat('"\\u018c"', $data, 0);
    }

    public function testDoubleEscapedUnicode(): void
    {
        $jsonFile = new JsonFile('composer.json');
        $data = array("Zdjęcia","hjkjhl\\u0119kkjk");
        $encodedData = $jsonFile->encode($data);
        $doubleEncodedData = $jsonFile->encode(array('t' => $encodedData));

        $decodedData = json_decode($doubleEncodedData, true);
        $doubleData = json_decode($decodedData['t'], true);
        $this->assertEquals($data, $doubleData);
    }

    /**
     * @param string $text
     * @param string $json
     * @return void
     */
    private function expectParseException(string $text, string $json): void
    {
        try {
            $result = JsonFile::parseJson($json);
            $this->fail(sprintf("Parsing should have failed but didn't.\nExpected:\n\"%s\"\nFor:\n\"%s\"\nGot:\n\"%s\"", $text, $json, var_export($result, true)));
        } catch (ParsingException $e) {
            $this->assertStringContainsString($text, $e->getMessage());
        }
    }

    /**
     * @param string $json
     * @param mixed $data
     * @param int|null $options
     * @return void
     */
    private function assertJsonFormat(string $json, $data, ?int $options = null): void
    {
        $file = new JsonFile('composer.json');

        $json = str_replace("\r", '', $json);
        if (null === $options) {
            $this->assertEquals($json, $file->encode($data));
        } else {
            $this->assertEquals($json, $file->encode($data, $options));
        }
    }
}
