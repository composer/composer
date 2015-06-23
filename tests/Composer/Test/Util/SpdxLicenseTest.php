<?php
namespace Composer\Test\Util;

use Composer\TestCase;
use Composer\Util\SpdxLicense;

class SpdxLicenseTest extends TestCase
{
    /**
     * @var object
     */
    private $license;

    public function setUp()
    {
        $this->license = new SpdxLicense;
    }

    public static function provideValidLicenses()
    {
        $json = file_get_contents(__DIR__ . '/../../../../res/spdx-licenses.json');

        $licenses = json_decode($json, true);

        $identifiers = array_keys($licenses);

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
            $identifiers
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
        $this->assertTrue($this->license->validate($license));
    }

    /**
     * @dataProvider provideInvalidLicenses
     * @param string|array $invalidLicense
     */
    public function testInvalidLicenses($invalidLicense)
    {
        $this->assertFalse($this->license->validate($invalidLicense));
    }

    /**
     * @dataProvider provideInvalidArgument
     * @expectedException InvalidArgumentException
     */
    public function testInvalidArgument($invalidArgument)
    {
        $this->license->validate($invalidArgument);
    }

    public function testGetLicenseByIdentifier()
    {
        $license = $this->license->getLicenseByIdentifier('AGPL-1.0');
        $this->assertEquals($license[0], 'Affero General Public License v1.0'); // fullname
        $this->assertFalse($license[1]); // osi approved
    }

    public function testGetIdentifierByName()
    {
        $identifier = $this->license->getIdentifierByName('Affero General Public License v1.0');
        $this->assertEquals($identifier, 'AGPL-1.0');

        $identifier = $this->license->getIdentifierByName('BSD 2-clause "Simplified" License');
        $this->assertEquals($identifier, 'BSD-2-Clause');
    }

    public function testIsOsiApprovedByIdentifier()
    {
        $osiApproved = $this->license->isOsiApprovedByIdentifier('MIT');
        $this->assertTrue($osiApproved);

        $osiApproved = $this->license->isOsiApprovedByIdentifier('AGPL-1.0');
        $this->assertFalse($osiApproved);
    }
}
