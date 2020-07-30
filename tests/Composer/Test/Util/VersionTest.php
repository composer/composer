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

namespace Composer\Test\Util;

use Composer\Util\Version;
use Composer\Util\Zip;
use Composer\Test\TestCase;

/**
 * @author Lars Strojny <lars@strojny.net>
 */
class VersionTest extends TestCase
{
    public static function getOpenSslVersions()
    {
        return array(
            array('3.0.0-alpha5', '3.0.0-alpha5'),
            array('1.1.1g-dev', '1.1.1.6-dev'),
            array('1.1.1g', '1.1.1.6'),
            array('1.1.1-pre5', '1.1.1-pre5'),
            array('0.9.8zg', '0.9.8.25.6'),
        );
    }

    /** @dataProvider getOpenSslVersions */
    public function testParseOpensslVersions($input, $output)
    {
        self::assertSame($output, Version::normalizeOpenssl($input));
    }
}
