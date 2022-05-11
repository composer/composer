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

namespace Composer\Test\Repository\Vcs;

use Composer\Repository\Vcs\FossilDriver;
use Composer\Config;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;

class FossilDriverTest extends TestCase
{
    /**
     * @var string
     */
    protected $home;
    /**
     * @var Config
     */
    protected $config;

    public function setUp(): void
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
            ),
        ));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }

    public static function supportProvider(): array
    {
        return array(
            array('http://fossil.kd2.org/kd2fw/', true),
            array('https://chiselapp.com/user/rkeene/repository/flint/index', true),
            array('ssh://fossil.kd2.org/kd2fw.fossil', true),
        );
    }

    /**
     * @dataProvider supportProvider
     *
     * @param string $url
     * @param bool   $assertion
     */
    public function testSupport(string $url, bool $assertion): void
    {
        $config = new Config();
        $result = FossilDriver::supports($this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config, $url);
        $this->assertEquals($assertion, $result);
    }
}
