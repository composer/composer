<?php
namespace Composer\Test\Util;

use Composer\IO\NullIO;
use Composer\Util\ConfigValidator;

class ConfigValidatorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @param string $file
     * @param array  $expected
     *
     * @dataProvider provideTestValidateCases
     */
    public function testValidate($file, $expected = array())
    {
        $validator = new ConfigValidator(new NullIO());
        list($errors, $publishErrors, $warnings) = $validator->validate($file);
        $this->assertArrayRegExp(isset($expected['errors']) ? $expected['errors'] : array(), $errors, 'errors');
        $this->assertArrayRegExp(isset($expected['publishErrors']) ? $expected['publishErrors'] : array(), $publishErrors, 'publish errors');
        $this->assertArrayRegExp(isset($expected['warnings']) ? $expected['warnings'] : array(), $warnings, 'warnings');
    }

    public function provideTestValidateCases()
    {
        $testFilesDirectory = __DIR__.'/ConfigValidatorTestFiles/';
        $cases = array();

        $cases[] = array(
            $testFilesDirectory.'not_existing.json',
            array(
                'errors' => array(sprintf('#"%s" file could not be downloaded: failed to open stream: No such file or directory#', $testFilesDirectory.'not_existing.json'))
            )
        );

        $cases[] = array(
            $testFilesDirectory.'test1.json',
            array(
                'errors' => array(sprintf('#"%s" does not contain valid JSON#', $testFilesDirectory.'test1.json'))
            )
        );

        $cases[] = array(
            $testFilesDirectory.'test2.json',
            array(
                'publishErrors' => array(sprintf('#%s#', preg_quote('Name "spacepossum/gecko-IO" does not match the best practice (e.g. lower-cased/with-dashes). We suggest using "spacepossum/gecko-io" instead. As such you will not be able to submit it to Packagist.')))
            )
        );

        $cases[] = array(
            $testFilesDirectory.'../../../../../composer.json'
        );

        return $cases;
    }

    private function assertArrayRegExp(array $patterns, array $values, $prefix)
    {
        $count = count($patterns);
        $this->assertSame($count, count($values), sprintf("Expected # of %s does not match.\nExpected:\n%s\nGot:\n%s", $prefix, var_export($patterns, true), var_export($values, true)));
        // note: this only works for depth 0, which is enough for these test cases for now
        for ($i =0; $i < $count; ++$i) {
            $this->assertRegExp($patterns[$i], $values[$i], sprintf('Expected value at %d of %s does not match.', $i, $prefix));
        }
    }
}
