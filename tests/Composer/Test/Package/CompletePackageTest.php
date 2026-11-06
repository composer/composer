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

namespace Composer\Test\Package;

use Composer\Package\Package;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;

class CompletePackageTest extends TestCase
{
    /**
     * Memory package naming, versioning, and marshalling semantics provider
     *
     * demonstrates several versioning schemes
     */
    public static function providerVersioningSchemes(): array
    {
        $provider[] = ['foo',              '1-beta'];
        $provider[] = ['node',             '0.5.6'];
        $provider[] = ['li3',              '0.10'];
        $provider[] = ['mongodb_odm',      '1.0.0BETA3'];
        $provider[] = ['DoctrineCommon',   '2.2.0-DEV'];

        return $provider;
    }

    /**
     * @dataProvider providerVersioningSchemes
     */
    public function testPackageHasExpectedNamingSemantics(string $name, string $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        self::assertEquals(strtolower($name), $package->getName());
    }

    /**
     * @dataProvider providerVersioningSchemes
     */
    public function testPackageHasExpectedVersioningSemantics(string $name, string $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        self::assertEquals($version, $package->getPrettyVersion());
        self::assertEquals($normVersion, $package->getVersion());
    }

    /**
     * @dataProvider providerVersioningSchemes
     */
    public function testPackageHasExpectedMarshallingSemantics(string $name, string $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        self::assertEquals(strtolower($name).'-'.$normVersion, (string) $package);
    }

    public function testGetTargetDir(): void
    {
        $package = new Package('a', '1.0.0.0', '1.0');

        self::assertNull($package->getTargetDir());

        $package->setTargetDir('./../foo/');
        self::assertEquals('foo/', $package->getTargetDir());

        $package->setTargetDir('foo/../../../bar/');
        self::assertEquals('foo/bar/', $package->getTargetDir());

        $package->setTargetDir('../..');
        self::assertEquals('', $package->getTargetDir());

        $package->setTargetDir('..');
        self::assertEquals('', $package->getTargetDir());

        $package->setTargetDir('/..');
        self::assertEquals('', $package->getTargetDir());

        $package->setTargetDir('/foo/..');
        self::assertEquals('foo/', $package->getTargetDir());

        $package->setTargetDir('/foo/..//bar');
        self::assertEquals('foo/bar', $package->getTargetDir());
    }

    /**
     * @dataProvider provideTargetDirectories
     */
    public function testTargetDirectoryPreservesSeparators(?string $target, ?string $expected): void
    {
        $package = new Package('a', '1.0.0.0', '1.0');
        $package->setTargetDir($target);

        self::assertSame($expected, $package->getTargetDir());
    }

    public static function provideTargetDirectories(): array
    {
        return [
            [null, null],
            ['', ''],
            ['src', 'src'],
            ['//vendor/pkg', 'vendor/pkg'],
            ['vendor/pkg/', 'vendor/pkg/'],
            ['\Foo\Bar', '\Foo\Bar'],
            ['a.b/c', 'a.b/c'],
            ['foo/../bar', 'foo/bar'],
        ];
    }

    public function testLastDateSetterWins(): void
    {
        $package = new Package('foo', '1.0.0.0', '1.0');

        $package->setReleaseDateString('2025-01-15T10:00:00+00:00');
        $package->setReleaseDate(new \DateTime('2020-01-01T00:00:00+00:00'));
        self::assertNotNull($date = $package->getReleaseDate());
        self::assertSame('2020-01-01', $date->format('Y-m-d'));

        $package->setReleaseDate(new \DateTime('2020-01-01T00:00:00+00:00'));
        $package->setReleaseDateString('2025-01-15T10:00:00+00:00');
        self::assertNotNull($date = $package->getReleaseDate());
        self::assertSame('2025-01-15', $date->format('Y-m-d'));

        $package->setPublishedDateString('2025-01-15T10:00:00+00:00');
        $package->setPublishedDate(null);
        self::assertNull($package->getPublishedDate());

        $package->setPublishedDate(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'));
        $package->setPublishedDateString('1747685025');
        self::assertNotNull($publishedDate = $package->getPublishedDate());
        self::assertSame(1747685025, $publishedDate->getTimestamp());
    }

    /**
     * @dataProvider provideSourceDistReferenceUrls
     */
    public function testSetSourceDistReferencesReplacesReferenceInDistUrl(string $distUrl, string $expectedUrl): void
    {
        $oldRef = str_repeat('a', 40);
        $newRef = str_repeat('b', 40);

        $package = new Package('foo/bar', '1.0.0.0', '1.0');
        $package->setSourceReference($oldRef);
        $package->setDistType('zip');
        $package->setDistUrl($distUrl);
        $package->setDistReference($oldRef);

        $package->setSourceDistReferences($newRef);

        self::assertSame($newRef, $package->getSourceReference());
        self::assertSame($newRef, $package->getDistReference());
        self::assertSame($expectedUrl, $package->getDistUrl());
    }

    public static function provideSourceDistReferenceUrls(): array
    {
        $old = str_repeat('a', 40);
        $new = str_repeat('b', 40);

        return [
            'github api zipball' => [
                'https://api.github.com/repos/foo/bar/zipball/'.$old,
                'https://api.github.com/repos/foo/bar/zipball/'.$new,
            ],
            'gitlab sha query' => [
                'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha='.$old,
                'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha='.$new,
            ],
            'bitbucket zip archive' => [
                'https://bitbucket.org/foo/bar/get/'.$old.'.zip',
                'https://bitbucket.org/foo/bar/get/'.$new.'.zip',
            ],
            'codeberg zip archive' => [
                'https://codeberg.org/foo/bar/archive/'.$old.'.zip',
                'https://codeberg.org/foo/bar/archive/'.$new.'.zip',
            ],
            'codeberg uppercase host' => [
                'https://CODEBERG.ORG/foo/bar/archive/'.$old.'.zip',
                'https://CODEBERG.ORG/foo/bar/archive/'.$new.'.zip',
            ],
            'codeberg api archive' => [
                'https://codeberg.org/api/v1/repos/foo/bar/archive/'.$old.'.zip',
                'https://codeberg.org/api/v1/repos/foo/bar/archive/'.$new.'.zip',
            ],
        ];
    }

    public function testSetSourceDistReferencesSetsDistReferenceForCodebergWithoutPriorDistReference(): void
    {
        $oldRef = str_repeat('a', 40);
        $newRef = str_repeat('b', 40);

        $package = new Package('foo/bar', '1.0.0.0', '1.0');
        $package->setDistType('zip');
        $package->setDistUrl('https://codeberg.org/foo/bar/archive/'.$oldRef.'.zip');

        $package->setSourceDistReferences($newRef);

        self::assertSame($newRef, $package->getDistReference());
        self::assertSame('https://codeberg.org/foo/bar/archive/'.$newRef.'.zip', $package->getDistUrl());
    }

    public function testSetSourceDistReferencesDoesNotReplaceShaFollowedByOtherExtension(): void
    {
        $oldRef = str_repeat('a', 40);
        $newRef = str_repeat('b', 40);
        $url = 'https://codeberg.org/foo/bar/archive/'.$oldRef.'.tar.gz';

        $package = new Package('foo/bar', '1.0.0.0', '1.0');
        $package->setDistType('tar');
        $package->setDistUrl($url);

        $package->setSourceDistReferences($newRef);

        self::assertSame($newRef, $package->getDistReference());
        self::assertSame($url, $package->getDistUrl());
    }

    public function testSetSourceDistReferencesLeavesUnknownHostUrlUntouched(): void
    {
        $oldRef = str_repeat('a', 40);
        $newRef = str_repeat('b', 40);
        $url = 'https://example.org/foo/bar/archive/'.$oldRef.'.zip';

        $package = new Package('foo/bar', '1.0.0.0', '1.0');
        $package->setDistType('zip');
        $package->setDistUrl($url);
        $package->setDistReference($oldRef);

        $package->setSourceDistReferences($newRef);

        self::assertSame($newRef, $package->getSourceReference());
        self::assertSame($newRef, $package->getDistReference());
        self::assertSame($url, $package->getDistUrl());
    }

    public function testSetSourceDistReferencesDoesNotMatchCodebergLookalikeHost(): void
    {
        $oldRef = str_repeat('a', 40);
        $newRef = str_repeat('b', 40);
        $url = 'https://notcodeberg.org/foo/bar/archive/'.$oldRef.'.zip';

        $package = new Package('foo/bar', '1.0.0.0', '1.0');
        $package->setDistType('zip');
        $package->setDistUrl($url);

        $package->setSourceDistReferences($newRef);

        self::assertNull($package->getDistReference());
        self::assertSame($url, $package->getDistUrl());
    }
}
