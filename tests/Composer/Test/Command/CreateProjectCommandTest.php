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
use InvalidArgumentException;

class CreateProjectCommandTest extends TestCase
{
    public function testReportsVersionsOnlyThePlatformRejects(): void
    {
        $this->initTempComposer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find package required/pkg with stability stable in a version installable using your PHP version, PHP extensions and Composer version.');

        $this->runCreateProject([
            ['name' => 'required/pkg', 'version' => '1.0.0', 'require' => ['ext-foobar' => '^1']],
        ]);
    }

    public function testReportsVersionsOnlyTheCooldownWithholds(): void
    {
        $dir = $this->initTempComposer();
        (new JsonFile($dir.'/composer-home/config.json'))->write(['config' => ['policy' => ['cooldown' => ['period' => '7 days']]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find package required/pkg with stability stable that is past the cooldown period configured in "policy.cooldown".');

        $this->runCreateProject([
            ['name' => 'required/pkg', 'version' => '1.0.0', 'time' => gmdate('Y-m-d H:i:s', time() - 3600)],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function runCreateProject(array $packages): void
    {
        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'create-project',
            'package' => 'required/pkg',
            'directory' => 'project',
            '--repository' => [JsonFile::encode(['type' => 'package', 'package' => $packages])],
            '--no-install' => true,
        ]);
    }
}
