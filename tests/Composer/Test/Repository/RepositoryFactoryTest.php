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

namespace Composer\Test\Repository;

use Composer\Repository\RepositoryFactory;
use Composer\Test\TestCase;

class RepositoryFactoryTest extends TestCase
{
    public function testManagerWithAllRepositoryTypes(): void
    {
        $manager = RepositoryFactory::manager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getMockBuilder('Composer\Config')->getMock(),
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $ref = new \ReflectionProperty($manager, 'repositoryClasses');
        $ref->setAccessible(true);
        $repositoryClasses = $ref->getValue($manager);

        $this->assertEquals(array(
            'composer',
            'vcs',
            'package',
            'pear',
            'git',
            'bitbucket',
            'git-bitbucket',
            'github',
            'gitlab',
            'svn',
            'fossil',
            'perforce',
            'hg',
            'artifact',
            'path',
            'app',
        ), array_keys($repositoryClasses));
    }

    /**
     * @dataProvider generateRepositoryNameProvider
     *
     * @param int|string            $index
     * @param array<string, string> $config
     * @param array<string, mixed>  $existingRepos
     *
     * @phpstan-param array{url?: string} $config
     */
    public function testGenerateRepositoryName($index, array $config, array $existingRepos, string $expected): void
    {
        $this->assertSame($expected, RepositoryFactory::generateRepositoryName($index, $config, $existingRepos));
    }

    public function generateRepositoryNameProvider(): array
    {
        return array(
            array(0, array(), array(), '0'),
            array(0, array(), array(array()), '02'),
            array(0, array('url' => 'https://example.org'), array(), 'example.org'),
            array(0, array('url' => 'https://example.org'), array('example.org' => array()), 'example.org2'),
            array('example.org', array('url' => 'https://example.org/repository'), array(), 'example.org'),
            array('example.org', array('url' => 'https://example.org/repository'), array('example.org' => array()), 'example.org2'),
        );
    }
}
