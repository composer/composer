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

namespace Composer\Test;

use Composer\Config;

class DefaultConfigTest extends TestCase
{
    /**
     * @group TLS
     */
    public function testDefaultValuesAreAsExpected(): void
    {
        $config = new Config;
        self::assertFalse($config->get('disable-tls'));
    }
}
