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

namespace Composer\Test\Config;

use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;

class JsonConfigSourceTest extends TestCase
{
    /** @var Filesystem */
    private $fs;
    /** @var string */
    private $workingDir;

    protected static function fixturePath(string $name): string
    {
        return __DIR__.'/Fixtures/'.$name;
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem;
        $this->workingDir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }
    }

    public function testAddRepository(): void
    {
        $config = $this->workingDir.'/composer.json';
        copy(self::fixturePath('composer-repositories.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->addRepository('example_tld', ['type' => 'git', 'url' => 'example.tld']);

        $this->assertFileEquals(self::fixturePath('config/config-with-exampletld-repository.json'), $config);
    }

    public function testAddRepositoryWithOptions(): void
    {
        $config = $this->workingDir.'/composer.json';
        copy(self::fixturePath('composer-repositories.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->addRepository('example_tld', [
            'type' => 'composer',
            'url' => 'https://example.tld',
            'options' => [
                'ssl' => [
                    'local_cert' => '/home/composer/.ssl/composer.pem',
                ],
            ],
        ]);

        $this->assertFileEquals(self::fixturePath('config/config-with-exampletld-repository-and-options.json'), $config);
    }

    public function testRemoveRepository(): void
    {
        $config = $this->workingDir.'/composer.json';
        copy(self::fixturePath('config/config-with-exampletld-repository.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->removeRepository('example_tld');

        $this->assertFileEquals(self::fixturePath('composer-repositories.json'), $config);
    }

    public function testAddPackagistRepositoryWithFalseValue(): void
    {
        $config = $this->workingDir.'/composer.json';
        copy(self::fixturePath('composer-repositories.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->addRepository('packagist', false);

        $this->assertFileEquals(self::fixturePath('config/config-with-packagist-false.json'), $config);
    }

    public function testRemovePackagist(): void
    {
        $config = $this->workingDir.'/composer.json';
        copy(self::fixturePath('config/config-with-packagist-false.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->removeRepository('packagist');

        $this->assertFileEquals(self::fixturePath('composer-repositories.json'), $config);
    }

    /**
     * Test addLink()
     *
     * @param string $sourceFile     Source file
     * @param string $type           Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name           Name
     * @param string $value          Value
     * @param string $compareAgainst File to compare against after making changes
     *
     * @dataProvider provideAddLinkData
     */
    public function testAddLink(string $sourceFile, string $type, string $name, string $value, string $compareAgainst): void
    {
        $composerJson = $this->workingDir.'/composer.json';
        copy($sourceFile, $composerJson);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($composerJson));

        $jsonConfigSource->addLink($type, $name, $value);

        $this->assertFileEquals($compareAgainst, $composerJson);
    }

    /**
     * Test removeLink()
     *
     * @param string $sourceFile     Source file
     * @param string $type           Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name           Name
     * @param string $compareAgainst File to compare against after making changes
     *
     * @dataProvider provideRemoveLinkData
     */
    public function testRemoveLink(string $sourceFile, string $type, string $name, string $compareAgainst): void
    {
        $composerJson = $this->workingDir.'/composer.json';
        copy($sourceFile, $composerJson);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($composerJson));

        $jsonConfigSource->removeLink($type, $name);

        $this->assertFileEquals($compareAgainst, $composerJson);
    }

    /**
     * @return string[]
     *
     * @phpstan-return array{string, string, string, string, string}
     */
    protected static function addLinkDataArguments(string $type, string $name, string $value, string $fixtureBasename, string $before): array
    {
        return [
            $before,
            $type,
            $name,
            $value,
            self::fixturePath('addLink/'.$fixtureBasename.'.json'),
        ];
    }

    /**
     * Provide data for testAddLink
     */
    public static function provideAddLinkData(): array
    {
        $empty = self::fixturePath('composer-empty.json');
        $oneOfEverything = self::fixturePath('composer-one-of-everything.json');
        $twoOfEverything = self::fixturePath('composer-two-of-everything.json');

        return [
            self::addLinkDataArguments('require', 'my-vend/my-lib', '1.*', 'require-from-empty', $empty),
            self::addLinkDataArguments('require', 'my-vend/my-lib', '1.*', 'require-from-oneOfEverything', $oneOfEverything),
            self::addLinkDataArguments('require', 'my-vend/my-lib', '1.*', 'require-from-twoOfEverything', $twoOfEverything),

            self::addLinkDataArguments('require-dev', 'my-vend/my-lib-tests', '1.*', 'require-dev-from-empty', $empty),
            self::addLinkDataArguments('require-dev', 'my-vend/my-lib-tests', '1.*', 'require-dev-from-oneOfEverything', $oneOfEverything),
            self::addLinkDataArguments('require-dev', 'my-vend/my-lib-tests', '1.*', 'require-dev-from-twoOfEverything', $twoOfEverything),

            self::addLinkDataArguments('provide', 'my-vend/my-lib-interface', '1.*', 'provide-from-empty', $empty),
            self::addLinkDataArguments('provide', 'my-vend/my-lib-interface', '1.*', 'provide-from-oneOfEverything', $oneOfEverything),
            self::addLinkDataArguments('provide', 'my-vend/my-lib-interface', '1.*', 'provide-from-twoOfEverything', $twoOfEverything),

            self::addLinkDataArguments('suggest', 'my-vend/my-optional-extension', '1.*', 'suggest-from-empty', $empty),
            self::addLinkDataArguments('suggest', 'my-vend/my-optional-extension', '1.*', 'suggest-from-oneOfEverything', $oneOfEverything),
            self::addLinkDataArguments('suggest', 'my-vend/my-optional-extension', '1.*', 'suggest-from-twoOfEverything', $twoOfEverything),

            self::addLinkDataArguments('replace', 'my-vend/other-app', '1.*', 'replace-from-empty', $empty),
            self::addLinkDataArguments('replace', 'my-vend/other-app', '1.*', 'replace-from-oneOfEverything', $oneOfEverything),
            self::addLinkDataArguments('replace', 'my-vend/other-app', '1.*', 'replace-from-twoOfEverything', $twoOfEverything),

            self::addLinkDataArguments('conflict', 'my-vend/my-old-app', '1.*', 'conflict-from-empty', $empty),
            self::addLinkDataArguments('conflict', 'my-vend/my-old-app', '1.*', 'conflict-from-oneOfEverything', $oneOfEverything),
            self::addLinkDataArguments('conflict', 'my-vend/my-old-app', '1.*', 'conflict-from-twoOfEverything', $twoOfEverything),
        ];
    }

    /**
     * @return string[]
     *
     * @phpstan-return array{string, string, string, string}
     */
    protected static function removeLinkDataArguments(string $type, string $name, string $fixtureBasename, ?string $after = null): array
    {
        return [
            self::fixturePath('removeLink/'.$fixtureBasename.'.json'),
            $type,
            $name,
            $after ?: self::fixturePath('removeLink/'.$fixtureBasename.'-after.json'),
        ];
    }

    /**
     * Provide data for testRemoveLink
     */
    public static function provideRemoveLinkData(): array
    {
        $oneOfEverything = self::fixturePath('composer-one-of-everything.json');
        $twoOfEverything = self::fixturePath('composer-two-of-everything.json');

        return [
            self::removeLinkDataArguments('require', 'my-vend/my-lib', 'require-to-empty'),
            self::removeLinkDataArguments('require', 'my-vend/my-lib', 'require-to-oneOfEverything', $oneOfEverything),
            self::removeLinkDataArguments('require', 'my-vend/my-lib', 'require-to-twoOfEverything', $twoOfEverything),

            self::removeLinkDataArguments('require-dev', 'my-vend/my-lib-tests', 'require-dev-to-empty'),
            self::removeLinkDataArguments('require-dev', 'my-vend/my-lib-tests', 'require-dev-to-oneOfEverything', $oneOfEverything),
            self::removeLinkDataArguments('require-dev', 'my-vend/my-lib-tests', 'require-dev-to-twoOfEverything', $twoOfEverything),

            self::removeLinkDataArguments('provide', 'my-vend/my-lib-interface', 'provide-to-empty'),
            self::removeLinkDataArguments('provide', 'my-vend/my-lib-interface', 'provide-to-oneOfEverything', $oneOfEverything),
            self::removeLinkDataArguments('provide', 'my-vend/my-lib-interface', 'provide-to-twoOfEverything', $twoOfEverything),

            self::removeLinkDataArguments('suggest', 'my-vend/my-optional-extension', 'suggest-to-empty'),
            self::removeLinkDataArguments('suggest', 'my-vend/my-optional-extension', 'suggest-to-oneOfEverything', $oneOfEverything),
            self::removeLinkDataArguments('suggest', 'my-vend/my-optional-extension', 'suggest-to-twoOfEverything', $twoOfEverything),

            self::removeLinkDataArguments('replace', 'my-vend/other-app', 'replace-to-empty'),
            self::removeLinkDataArguments('replace', 'my-vend/other-app', 'replace-to-oneOfEverything', $oneOfEverything),
            self::removeLinkDataArguments('replace', 'my-vend/other-app', 'replace-to-twoOfEverything', $twoOfEverything),

            self::removeLinkDataArguments('conflict', 'my-vend/my-old-app', 'conflict-to-empty'),
            self::removeLinkDataArguments('conflict', 'my-vend/my-old-app', 'conflict-to-oneOfEverything', $oneOfEverything),
            self::removeLinkDataArguments('conflict', 'my-vend/my-old-app', 'conflict-to-twoOfEverything', $twoOfEverything),
        ];
    }
}
