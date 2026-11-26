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

namespace Composer\Test\Command;

use Composer\Json\JsonFile;
use Composer\Test\TestCase;

class LinkCommandTest extends TestCase
{
    public function testLink(): void
    {
        $dir = $this->initTempComposer(['name' => 'acme/foo']);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'link', '--as-version' => '1.x-dev']);
        $appTester->assertCommandIsSuccessful();

        $file = $dir.'/composer-home/linked-packages.json';
        self::assertSame('Linked acme/foo as 1.x-dev from '.$dir.' in '.$file, trim($appTester->getDisplay(true)));
        self::assertSame(['packages' => ['acme/foo' => ['url' => $dir, 'version' => '1.x-dev']]], (new JsonFile($file))->read());
    }

    public function testLinkWithLinkedRepoPath(): void
    {
        $dir = $this->initTempComposer(['name' => 'acme/foo']);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'link', '--linked-repo-path' => $dir.'/links']);
        $appTester->assertCommandIsSuccessful();

        self::assertSame(['packages' => ['acme/foo' => ['url' => $dir]]], (new JsonFile($dir.'/links/linked-packages.json'))->read());
        self::assertFileDoesNotExist($dir.'/composer-home/linked-packages.json');
    }
}
