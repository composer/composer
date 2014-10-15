<?php
namespace Composer\Test\Util;

use Composer\TestCase;
use Composer\Util\SpdxLicenseIdentifier;

class SpdxLicenseIdentifierTest extends TestCase
{
    public static function provideValidLicenses()
    {
        $valid = array_merge(
            array(
                "MIT",
                "NONE",
                "NOASSERTION",
                "LicenseRef-3",
                array("LGPL-2.0", "GPL-3.0+"),
                "(LGPL-2.0 or GPL-3.0+)",
                "(EUDatagrid and GPL-3.0+)",
            ),
            json_decode(file_get_contents(__DIR__ . '/../../../../res/spdx-identifier.json'))
        );

        foreach ($valid as &$r) {
            $r = array($r);
        }

        return $valid;
    }

    public static function provideInvalidLicenses()
    {
        return array(
            array(""),
            array(array()),
            array("The system pwns you"),
            array("()"),
            array("(MIT)"),
            array("MIT NONE"),
            array("MIT (MIT and MIT)"),
            array("(MIT and MIT) MIT"),
            array(array("LGPL-2.0", "The system pwns you")),
            array("and GPL-3.0+"),
            array("EUDatagrid and GPL-3.0+"),
            array("(GPL-3.0 and GPL-2.0 or GPL-3.0+)"),
            array("(EUDatagrid and GPL-3.0+ and  )"),
            array("(EUDatagrid xor GPL-3.0+)"),
            array("(MIT Or MIT)"),
            array("(NONE or MIT)"),
            array("(NOASSERTION or MIT)"),
        );
    }

    public static function provideInvalidArgument()
    {
        return array(
            array(null),
            array(new \stdClass),
            array(array(new \stdClass)),
            array(array("mixed", new \stdClass)),
            array(array(new \stdClass, new \stdClass)),
        );
    }

    /**
     * @dataProvider provideValidLicenses
     * @param $license
     */
    public function testValidate($license)
    {
        $validator = new SpdxLicenseIdentifier();
        $this->assertTrue($validator->validate($license));
    }

    /**
     * @dataProvider provideInvalidLicenses
     * @param string|array $invalidLicense
     */
    public function testInvalidLicenses($invalidLicense)
    {
        $validator = new SpdxLicenseIdentifier();
        $this->assertFalse($validator->validate($invalidLicense));
    }

    /**
     * @dataProvider provideInvalidArgument
     * @expectedException InvalidArgumentException
     */
    public function testInvalidArgument($invalidArgument)
    {
        $validator = new SpdxLicenseIdentifier();
        $validator->validate($invalidArgument);
    }
}
