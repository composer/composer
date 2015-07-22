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

namespace Composer\Test\Json;

use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;

class JsonConfigSourceTest extends \PHPUnit_Framework_TestCase
{
    /** @var Filesystem */
    private $fs;
    /** @var string */
    private $workingDir;

    protected function fixturePath($name)
    {
        return __DIR__.'/Fixtures/'.$name;
    }

    protected function setUp()
    {
        $this->fs = new Filesystem;
        $this->workingDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest';
        $this->fs->ensureDirectoryExists($this->workingDir);
    }

    protected function tearDown()
    {
        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }
    }

    public function testAddRepository()
    {
        $config = $this->workingDir.'/composer.json';
        copy($this->fixturePath('composer-repositories.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->addRepository('example_tld', array('type' => 'git', 'url' => 'example.tld'));

        $this->assertFileEquals($this->fixturePath('config/config-with-exampletld-repository.json'), $config);
    }

    public function testRemoveRepository()
    {
        $config = $this->workingDir.'/composer.json';
        copy($this->fixturePath('config/config-with-exampletld-repository.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->removeRepository('example_tld');

        $this->assertFileEquals($this->fixturePath('composer-repositories.json'), $config);
    }

    public function testAddPackagistRepositoryWithFalseValue()
    {
        $config = $this->workingDir.'/composer.json';
        copy($this->fixturePath('composer-repositories.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->addRepository('packagist', false);

        $this->assertFileEquals($this->fixturePath('config/config-with-packagist-false.json'), $config);
    }

    public function testRemovePackagist()
    {
        $config = $this->workingDir.'/composer.json';
        copy($this->fixturePath('config/config-with-packagist-false.json'), $config);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($config));
        $jsonConfigSource->removeRepository('packagist');

        $this->assertFileEquals($this->fixturePath('composer-repositories.json'), $config);
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
    public function testAddLink($sourceFile, $type, $name, $value, $compareAgainst)
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
    public function testRemoveLink($sourceFile, $type, $name, $compareAgainst)
    {
        $composerJson = $this->workingDir.'/composer.json';
        copy($sourceFile, $composerJson);
        $jsonConfigSource = new JsonConfigSource(new JsonFile($composerJson));

        $jsonConfigSource->removeLink($type, $name);

        $this->assertFileEquals($compareAgainst, $composerJson);
    }

    protected function addLinkDataArguments($type, $name, $value, $fixtureBasename, $before)
    {
        return array(
            $before,
            $type,
            $name,
            $value,
            $this->fixturePath('addLink/'.$fixtureBasename.'.json'),
        );
    }

    /**
     * Provide data for testAddLink
     *
     * @return array
     */
    public function provideAddLinkData()
    {
        $empty = $this->fixturePath('composer-empty.json');
        $oneOfEverything = $this->fixturePath('composer-one-of-everything.json');
        $twoOfEverything = $this->fixturePath('composer-two-of-everything.json');

        return array(
            $this->addLinkDataArguments('require', 'my-vend/my-lib', '1.*', 'require-from-empty', $empty),
            $this->addLinkDataArguments('require', 'my-vend/my-lib', '1.*', 'require-from-oneOfEverything', $oneOfEverything),
            $this->addLinkDataArguments('require', 'my-vend/my-lib', '1.*', 'require-from-twoOfEverything', $twoOfEverything),

            $this->addLinkDataArguments('require-dev', 'my-vend/my-lib-tests', '1.*', 'require-dev-from-empty', $empty),
            $this->addLinkDataArguments('require-dev', 'my-vend/my-lib-tests', '1.*', 'require-dev-from-oneOfEverything', $oneOfEverything),
            $this->addLinkDataArguments('require-dev', 'my-vend/my-lib-tests', '1.*', 'require-dev-from-twoOfEverything', $twoOfEverything),

            $this->addLinkDataArguments('provide', 'my-vend/my-lib-interface', '1.*', 'provide-from-empty', $empty),
            $this->addLinkDataArguments('provide', 'my-vend/my-lib-interface', '1.*', 'provide-from-oneOfEverything', $oneOfEverything),
            $this->addLinkDataArguments('provide', 'my-vend/my-lib-interface', '1.*', 'provide-from-twoOfEverything', $twoOfEverything),

            $this->addLinkDataArguments('suggest', 'my-vend/my-optional-extension', '1.*', 'suggest-from-empty', $empty),
            $this->addLinkDataArguments('suggest', 'my-vend/my-optional-extension', '1.*', 'suggest-from-oneOfEverything', $oneOfEverything),
            $this->addLinkDataArguments('suggest', 'my-vend/my-optional-extension', '1.*', 'suggest-from-twoOfEverything', $twoOfEverything),

            $this->addLinkDataArguments('replace', 'my-vend/other-app', '1.*', 'replace-from-empty', $empty),
            $this->addLinkDataArguments('replace', 'my-vend/other-app', '1.*', 'replace-from-oneOfEverything', $oneOfEverything),
            $this->addLinkDataArguments('replace', 'my-vend/other-app', '1.*', 'replace-from-twoOfEverything', $twoOfEverything),

            $this->addLinkDataArguments('conflict', 'my-vend/my-old-app', '1.*', 'conflict-from-empty', $empty),
            $this->addLinkDataArguments('conflict', 'my-vend/my-old-app', '1.*', 'conflict-from-oneOfEverything', $oneOfEverything),
            $this->addLinkDataArguments('conflict', 'my-vend/my-old-app', '1.*', 'conflict-from-twoOfEverything', $twoOfEverything),
        );
    }

    protected function removeLinkDataArguments($type, $name, $fixtureBasename, $after = null)
    {
        return array(
            $this->fixturePath('removeLink/'.$fixtureBasename.'.json'),
            $type,
            $name,
            $after ?: $this->fixturePath('removeLink/'.$fixtureBasename.'-after.json'),
        );
    }

    /**
     * Provide data for testRemoveLink
     *
     * @return array
     */
    public function provideRemoveLinkData()
    {
        $oneOfEverything = $this->fixturePath('composer-one-of-everything.json');
        $twoOfEverything = $this->fixturePath('composer-two-of-everything.json');

        return array(
            $this->removeLinkDataArguments('require', 'my-vend/my-lib', 'require-to-empty'),
            $this->removeLinkDataArguments('require', 'my-vend/my-lib', 'require-to-oneOfEverything', $oneOfEverything),
            $this->removeLinkDataArguments('require', 'my-vend/my-lib', 'require-to-twoOfEverything', $twoOfEverything),

            $this->removeLinkDataArguments('require-dev', 'my-vend/my-lib-tests', 'require-dev-to-empty'),
            $this->removeLinkDataArguments('require-dev', 'my-vend/my-lib-tests', 'require-dev-to-oneOfEverything', $oneOfEverything),
            $this->removeLinkDataArguments('require-dev', 'my-vend/my-lib-tests', 'require-dev-to-twoOfEverything', $twoOfEverything),

            $this->removeLinkDataArguments('provide', 'my-vend/my-lib-interface', 'provide-to-empty'),
            $this->removeLinkDataArguments('provide', 'my-vend/my-lib-interface', 'provide-to-oneOfEverything', $oneOfEverything),
            $this->removeLinkDataArguments('provide', 'my-vend/my-lib-interface', 'provide-to-twoOfEverything', $twoOfEverything),

            $this->removeLinkDataArguments('suggest', 'my-vend/my-optional-extension', 'suggest-to-empty'),
            $this->removeLinkDataArguments('suggest', 'my-vend/my-optional-extension', 'suggest-to-oneOfEverything', $oneOfEverything),
            $this->removeLinkDataArguments('suggest', 'my-vend/my-optional-extension', 'suggest-to-twoOfEverything', $twoOfEverything),

            $this->removeLinkDataArguments('replace', 'my-vend/other-app', 'replace-to-empty'),
            $this->removeLinkDataArguments('replace', 'my-vend/other-app', 'replace-to-oneOfEverything', $oneOfEverything),
            $this->removeLinkDataArguments('replace', 'my-vend/other-app', 'replace-to-twoOfEverything', $twoOfEverything),

            $this->removeLinkDataArguments('conflict', 'my-vend/my-old-app', 'conflict-to-empty'),
            $this->removeLinkDataArguments('conflict', 'my-vend/my-old-app', 'conflict-to-oneOfEverything', $oneOfEverything),
            $this->removeLinkDataArguments('conflict', 'my-vend/my-old-app', 'conflict-to-twoOfEverything', $twoOfEverything),
        );
    }
}
