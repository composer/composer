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

use Composer\Test\TestCase;

class SbomCommandTest extends TestCase
{
    /**
     * Not done in setUp() as some tests need their own project, and calling
     * initTempComposer() twice in one test breaks the cwd restore on tearDown
     */
    private function initDefaultProject(): void
    {
        $this->initTempComposer([
            'name' => 'test/pkg',
            'version' => '1.2.3',
            'license' => 'MIT',
            'require' => [
                'php' => '>=7.2',
                'first/pkg' => '^2.0',
                'second/pkg' => '3.*',
                'third/pkg' => '^1.3',
            ],
            'require-dev' => [
                'dev/pkg' => '~2.0',
            ],
        ]);

        $first = self::getPackage('first/pkg', '2.3.4');
        $first->setLicense(['MIT']);
        $first->setSourceType('git');
        $first->setSourceUrl('https://example.org/first.git');
        $first->setSourceReference('abcdef123');
        $first->setDistType('zip');
        $first->setDistUrl('https://user:secret@example.org/first.zip');
        $first->setDistReference('abcdef123');
        $first->setDistSha1Checksum('0123456789abcdef0123456789abcdef01234567');
        self::configureLinks($first, ['require' => ['third/pkg' => '^1.0', 'ext-json' => '*']]);

        $second = self::getPackage('second/pkg', '3.4.0');
        $second->setLicense(['MIT', 'Apache-2.0']);
        $second->setSourceType('git');
        $second->setSourceUrl('https://example.org/second.git');
        $second->setSourceReference('fedcba987');

        $third = self::getPackage('third/pkg', '1.5.4');

        $dev = self::getPackage('dev/pkg', '2.3.4.5');
        $dev->setLicense(['Some-Custom-License']);

        $this->createInstalledJson([$first, $second, $third], [$dev]);
        $this->createComposerLock([$first, $second, $third], [$dev]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function runSbom(array $args = []): array
    {
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(array_merge(['command' => 'sbom'], $args), ['capture_stderr_separately' => true]));

        $data = json_decode($appTester->getDisplay(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $sbom
     * @return array<string, array<string, mixed>>
     */
    private static function componentsByPurl(array $sbom): array
    {
        $components = [];
        foreach ($sbom['components'] as $component) {
            $components[$component['purl']] = $component;
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $sbom
     * @return array<string, list<string>>
     */
    private static function dependenciesByRef(array $sbom): array
    {
        $dependencies = [];
        foreach ($sbom['dependencies'] as $dependency) {
            $dependencies[$dependency['ref']] = $dependency['dependsOn'];
        }

        return $dependencies;
    }

    /**
     * @param array<string, mixed> $sbom
     * @return array<string, array<string, mixed>>
     */
    private static function spdxPackagesByName(array $sbom): array
    {
        $packages = [];
        foreach ($sbom['packages'] as $package) {
            $packages[$package['name']] = $package;
        }

        return $packages;
    }

    public function testCycloneDxIsTheDefaultFormat(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom();

        self::assertSame('CycloneDX', $sbom['bomFormat']);
        self::assertSame('1.6', $sbom['specVersion']);
        self::assertSame(1, $sbom['version']);
        self::assertMatchesRegularExpression('{^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$}', $sbom['serialNumber']);
        self::assertMatchesRegularExpression('{^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$}', $sbom['metadata']['timestamp']);
        self::assertSame('composer', $sbom['metadata']['tools']['components'][0]['name']);
        self::assertSame('application', $sbom['metadata']['tools']['components'][0]['type']);
    }

    public function testCycloneDxSerialNumberIsUniquePerRun(): void
    {
        $this->initDefaultProject();
        $first = $this->runSbom(['--format' => 'cyclonedx']);
        $second = $this->runSbom(['--format' => 'cyclonedx']);

        self::assertNotSame($first['serialNumber'], $second['serialNumber']);
    }

    public function testCycloneDxRootComponent(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom();

        self::assertSame([
            'type' => 'application',
            'bom-ref' => 'pkg:composer/test/pkg@1.2.3',
            'group' => 'test',
            'name' => 'pkg',
            'version' => '1.2.3',
            'licenses' => [['license' => ['id' => 'MIT']]],
            'purl' => 'pkg:composer/test/pkg@1.2.3',
        ], $sbom['metadata']['component']);
    }

    public function testCycloneDxComponentsAreSortedAndScoped(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom();

        self::assertSame([
            'pkg:composer/dev/pkg@2.3.4.5',
            'pkg:composer/first/pkg@2.3.4',
            'pkg:composer/second/pkg@3.4.0',
            'pkg:composer/third/pkg@1.5.4',
        ], array_column($sbom['components'], 'purl'));

        $components = self::componentsByPurl($sbom);
        self::assertSame('optional', $components['pkg:composer/dev/pkg@2.3.4.5']['scope']);
        self::assertSame('required', $components['pkg:composer/first/pkg@2.3.4']['scope']);
        self::assertSame('required', $components['pkg:composer/second/pkg@3.4.0']['scope']);
        self::assertSame('required', $components['pkg:composer/third/pkg@1.5.4']['scope']);

        foreach ($components as $purl => $component) {
            self::assertSame('library', $component['type']);
            self::assertSame($purl, $component['bom-ref']);
        }
    }

    public function testCycloneDxComponentDetails(): void
    {
        $this->initDefaultProject();
        $components = self::componentsByPurl($this->runSbom());

        $first = $components['pkg:composer/first/pkg@2.3.4'];
        self::assertSame('first', $first['group']);
        self::assertSame('pkg', $first['name']);
        self::assertSame('2.3.4', $first['version']);
        self::assertSame([['license' => ['id' => 'MIT']]], $first['licenses']);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://example.org/first.git', 'comment' => 'reference: abcdef123'],
            [
                'type' => 'distribution',
                'url' => 'https://user:***@example.org/first.zip',
                'comment' => 'reference: abcdef123',
                'hashes' => [['alg' => 'SHA-1', 'content' => '0123456789abcdef0123456789abcdef01234567']],
            ],
        ], $first['externalReferences']);

        $second = $components['pkg:composer/second/pkg@3.4.0'];
        self::assertSame([['expression' => '(MIT OR Apache-2.0)']], $second['licenses']);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://example.org/second.git', 'comment' => 'reference: fedcba987'],
        ], $second['externalReferences']);

        $third = $components['pkg:composer/third/pkg@1.5.4'];
        self::assertArrayNotHasKey('licenses', $third);
        self::assertArrayNotHasKey('externalReferences', $third);

        $dev = $components['pkg:composer/dev/pkg@2.3.4.5'];
        self::assertSame([['license' => ['name' => 'Some-Custom-License']]], $dev['licenses']);
    }

    public function testCycloneDxDoesNotLeakCredentials(): void
    {
        $this->initDefaultProject();
        $appTester = $this->getApplicationTester();
        self::assertSame(0, $appTester->run(['command' => 'sbom'], ['capture_stderr_separately' => true]));

        self::assertStringNotContainsString('secret', $appTester->getDisplay());
    }

    public function testCycloneDxDependencies(): void
    {
        $this->initDefaultProject();
        $dependencies = self::dependenciesByRef($this->runSbom());

        self::assertSame([
            'pkg:composer/first/pkg@2.3.4',
            'pkg:composer/second/pkg@3.4.0',
            'pkg:composer/third/pkg@1.5.4',
            'pkg:composer/dev/pkg@2.3.4.5',
        ], $dependencies['pkg:composer/test/pkg@1.2.3']);
        self::assertSame(['pkg:composer/third/pkg@1.5.4'], $dependencies['pkg:composer/first/pkg@2.3.4']);
        self::assertSame([], $dependencies['pkg:composer/second/pkg@3.4.0']);
        self::assertSame([], $dependencies['pkg:composer/third/pkg@1.5.4']);
        self::assertSame([], $dependencies['pkg:composer/dev/pkg@2.3.4.5']);
        self::assertCount(5, $dependencies);
    }

    public function testCycloneDxNoDev(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom(['--no-dev' => true]);

        self::assertSame([
            'pkg:composer/first/pkg@2.3.4',
            'pkg:composer/second/pkg@3.4.0',
            'pkg:composer/third/pkg@1.5.4',
        ], array_column($sbom['components'], 'purl'));
        self::assertSame([
            'pkg:composer/first/pkg@2.3.4',
            'pkg:composer/second/pkg@3.4.0',
            'pkg:composer/third/pkg@1.5.4',
        ], self::dependenciesByRef($sbom)['pkg:composer/test/pkg@1.2.3']);
    }

    public function testCycloneDxLocked(): void
    {
        $this->initDefaultProject();

        // remove the installed packages so only the lock file can provide them
        unlink('vendor/composer/installed.json');

        $sbom = $this->runSbom(['--locked' => true]);

        self::assertSame([
            'pkg:composer/dev/pkg@2.3.4.5',
            'pkg:composer/first/pkg@2.3.4',
            'pkg:composer/second/pkg@3.4.0',
            'pkg:composer/third/pkg@1.5.4',
        ], array_column($sbom['components'], 'purl'));

        $components = self::componentsByPurl($sbom);
        self::assertSame('optional', $components['pkg:composer/dev/pkg@2.3.4.5']['scope']);
        self::assertSame('required', $components['pkg:composer/first/pkg@2.3.4']['scope']);
    }

    public function testCycloneDxLockedNoDev(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom(['--locked' => true, '--no-dev' => true]);

        self::assertSame([
            'pkg:composer/first/pkg@2.3.4',
            'pkg:composer/second/pkg@3.4.0',
            'pkg:composer/third/pkg@1.5.4',
        ], array_column($sbom['components'], 'purl'));
        self::assertNotContains('pkg:composer/dev/pkg@2.3.4.5', self::dependenciesByRef($sbom)['pkg:composer/test/pkg@1.2.3']);
    }

    public function testLockedWithoutLockFile(): void
    {
        $this->initDefaultProject();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Valid composer.json and composer.lock files are required to run this command with --locked');

        unlink('./composer.lock');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'sbom', '--locked' => true]);
    }

    public function testFormatUnknown(): void
    {
        $this->initDefaultProject();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported format "unknown". Must be one of: cyclonedx, spdx.');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'sbom', '--format' => 'unknown']);
    }

    public function testSpdxDocument(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom(['--format' => 'spdx']);

        self::assertSame('SPDX-2.3', $sbom['spdxVersion']);
        self::assertSame('CC0-1.0', $sbom['dataLicense']);
        self::assertSame('SPDXRef-DOCUMENT', $sbom['SPDXID']);
        self::assertSame('test/pkg', $sbom['name']);
        self::assertMatchesRegularExpression('{^https://spdx\.org/spdxdocs/test%2Fpkg-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$}', $sbom['documentNamespace']);
        self::assertMatchesRegularExpression('{^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$}', $sbom['creationInfo']['created']);
        self::assertCount(1, $sbom['creationInfo']['creators']);
        self::assertStringStartsWith('Tool: composer-', $sbom['creationInfo']['creators'][0]);
    }

    public function testSpdxPackages(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom(['--format' => 'spdx']);

        self::assertSame(['test/pkg', 'dev/pkg', 'first/pkg', 'second/pkg', 'third/pkg'], array_column($sbom['packages'], 'name'));

        $packages = self::spdxPackagesByName($sbom);

        self::assertSame([
            'name' => 'first/pkg',
            'SPDXID' => 'SPDXRef-Package-first-pkg',
            'versionInfo' => '2.3.4',
            'downloadLocation' => 'https://user:***@example.org/first.zip',
            'filesAnalyzed' => false,
            'checksums' => [['algorithm' => 'SHA1', 'checksumValue' => '0123456789abcdef0123456789abcdef01234567']],
            'licenseConcluded' => 'NOASSERTION',
            'licenseDeclared' => 'MIT',
            'copyrightText' => 'NOASSERTION',
            'externalRefs' => [[
                'referenceCategory' => 'PACKAGE-MANAGER',
                'referenceType' => 'purl',
                'referenceLocator' => 'pkg:composer/first/pkg@2.3.4',
            ]],
        ], $packages['first/pkg']);

        self::assertSame('SPDXRef-Package-test-pkg', $packages['test/pkg']['SPDXID']);
        self::assertSame('MIT', $packages['test/pkg']['licenseDeclared']);

        self::assertSame('git+https://example.org/second.git@fedcba987', $packages['second/pkg']['downloadLocation']);
        self::assertSame('(MIT OR Apache-2.0)', $packages['second/pkg']['licenseDeclared']);
        self::assertArrayNotHasKey('checksums', $packages['second/pkg']);

        self::assertSame('NOASSERTION', $packages['third/pkg']['downloadLocation']);
        self::assertSame('NOASSERTION', $packages['third/pkg']['licenseDeclared']);

        self::assertSame('NOASSERTION', $packages['dev/pkg']['licenseDeclared']);
    }

    public function testSpdxRelationships(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom(['--format' => 'spdx']);

        self::assertSame([
            ['spdxElementId' => 'SPDXRef-DOCUMENT', 'relationshipType' => 'DESCRIBES', 'relatedSpdxElement' => 'SPDXRef-Package-test-pkg'],
            ['spdxElementId' => 'SPDXRef-Package-test-pkg', 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => 'SPDXRef-Package-first-pkg'],
            ['spdxElementId' => 'SPDXRef-Package-test-pkg', 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => 'SPDXRef-Package-second-pkg'],
            ['spdxElementId' => 'SPDXRef-Package-test-pkg', 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => 'SPDXRef-Package-third-pkg'],
            ['spdxElementId' => 'SPDXRef-Package-dev-pkg', 'relationshipType' => 'DEV_DEPENDENCY_OF', 'relatedSpdxElement' => 'SPDXRef-Package-test-pkg'],
            ['spdxElementId' => 'SPDXRef-Package-first-pkg', 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => 'SPDXRef-Package-third-pkg'],
        ], $sbom['relationships']);
    }

    public function testSpdxNoDev(): void
    {
        $this->initDefaultProject();
        $sbom = $this->runSbom(['--format' => 'spdx', '--no-dev' => true]);

        self::assertSame(['test/pkg', 'first/pkg', 'second/pkg', 'third/pkg'], array_column($sbom['packages'], 'name'));
        self::assertNotContains('DEV_DEPENDENCY_OF', array_column($sbom['relationships'], 'relationshipType'));
    }

    public function testSpdxIdsAreUniqueWhenNamesCollide(): void
    {
        $this->initTempComposer([
            'name' => 'test/root',
            'version' => '1.0.0',
            'require' => [
                'foo/bar-baz' => '*',
                'foo/bar_baz' => '*',
            ],
        ]);

        $this->createInstalledJson([
            self::getPackage('foo/bar-baz', '1.0.0'),
            self::getPackage('foo/bar_baz', '1.0.0'),
        ]);

        $packages = self::spdxPackagesByName($this->runSbom(['--format' => 'spdx']));

        self::assertSame('SPDXRef-Package-foo-bar-baz', $packages['foo/bar-baz']['SPDXID']);
        self::assertSame('SPDXRef-Package-foo-bar-baz-2', $packages['foo/bar_baz']['SPDXID']);
    }

    public function testAliasPackagesAreSkipped(): void
    {
        $this->initTempComposer([
            'name' => 'test/root',
            'version' => '1.0.0',
            'require' => [
                'alias/pkg' => '2.x-dev',
            ],
        ]);

        $package = self::getPackage('alias/pkg', 'dev-main');
        $package->setExtra(['branch-alias' => ['dev-main' => '2.x-dev']]);
        $this->createInstalledJson([$package]);

        $sbom = $this->runSbom();

        self::assertSame(['pkg:composer/alias/pkg@dev-main'], array_column($sbom['components'], 'purl'));
        self::assertSame(['pkg:composer/alias/pkg@dev-main'], self::dependenciesByRef($sbom)['pkg:composer/test/root@1.0.0']);
    }

    public function testProvidedNamesResolveToTheProvidingPackage(): void
    {
        $this->initTempComposer([
            'name' => 'test/root',
            'version' => '1.0.0',
            'require' => [
                'psr/log-implementation' => '*',
            ],
        ]);

        $provider = self::getPackage('impl/logger', '1.0.0');
        self::configureLinks($provider, ['provide' => ['psr/log-implementation' => '1.0.0']]);
        $this->createInstalledJson([$provider]);

        $sbom = $this->runSbom();

        self::assertSame(['pkg:composer/impl/logger@1.0.0'], self::dependenciesByRef($sbom)['pkg:composer/test/root@1.0.0']);
    }

    public function testPurlEncodesSpecialCharactersInVersion(): void
    {
        $this->initTempComposer([
            'name' => 'test/root',
            'version' => '1.0.0',
            'require' => [
                'some/pkg' => '*',
            ],
        ]);

        $this->createInstalledJson([self::getPackage('some/pkg', '1.0.0+build')]);

        $sbom = $this->runSbom();

        self::assertSame(['pkg:composer/some/pkg@1.0.0%2Bbuild'], array_column($sbom['components'], 'purl'));
        self::assertSame('1.0.0+build', $sbom['components'][0]['version']);
    }
}
