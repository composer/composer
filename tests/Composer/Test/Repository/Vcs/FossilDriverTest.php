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

namespace Composer\Test\Repository\Vcs;

use Composer\Repository\Vcs\FossilDriver;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Util\ProcessExecutor;
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

    public function setUp()
    {
        $this->home = $this->getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge(array(
            'config' => array(
                'home' => $this->home,
            ),
        ));
    }

    public function tearDown()
    {
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }

    public static function supportProvider()
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
    public function testSupport($url, $assertion)
    {
        $config = new Config();
        $result = FossilDriver::supports($this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config, $url);
        $this->assertEquals($assertion, $result);
    }

    public function testCloneFailureOutputIsSanitized()
    {
        $url = 'https://user:secret@example.org/acme';
        $repoFile = $this->config->get('cache-repo-dir') . '/https---user-----example-org-acme.fossil';

        $process = new ProcessExecutorMock;
        $process->expects(array(
            array('cmd' => 'fossil version'),
            array('cmd' => 'fossil clone -- '.ProcessExecutor::escape($url).' '.ProcessExecutor::escape($repoFile), 'return' => 1, 'stderr' => 'Error: unable to connect to "'.$url.'"'),
        ), true);

        $driver = new FossilDriver(array('url' => $url), new NullIO(), $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        try {
            $driver->initialize();
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unable to connect to "https://user:***@example.org/acme"', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}
