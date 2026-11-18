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
use Composer\Test\TestCase;

class PackageTest extends TestCase
{
    private const OLD_REF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW_REF = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @dataProvider provideSourceDistReferences
     */
    public function testSetSourceDistReferences(string $distUrl, ?string $distReference, string $expectedUrl, ?string $expectedDistReference): void
    {
        $package = new Package('foo/bar', '1.0.0.0', '1.0');
        $package->setSourceReference(self::OLD_REF);
        $package->setDistType('zip');
        $package->setDistUrl($distUrl);
        $package->setDistReference($distReference);

        $package->setSourceDistReferences(self::NEW_REF);

        self::assertSame(self::NEW_REF, $package->getSourceReference());
        self::assertSame($expectedDistReference, $package->getDistReference());
        self::assertSame($expectedUrl, $package->getDistUrl());
    }

    /**
     * @return array<string, array{string, string|null, string, string|null}>
     */
    public static function provideSourceDistReferences(): array
    {
        $old = self::OLD_REF;
        $new = self::NEW_REF;

        return [
            'github api zipball' => [
                'https://api.github.com/repos/foo/bar/zipball/'.$old, $old,
                'https://api.github.com/repos/foo/bar/zipball/'.$new, $new,
            ],
            'gitlab sha query' => [
                'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha='.$old, $old,
                'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha='.$new, $new,
            ],
            'bitbucket zip archive' => [
                'https://bitbucket.org/foo/bar/get/'.$old.'.zip', $old,
                'https://bitbucket.org/foo/bar/get/'.$new.'.zip', $new,
            ],
            'codeberg zip archive' => [
                'https://codeberg.org/foo/bar/archive/'.$old.'.zip', $old,
                'https://codeberg.org/foo/bar/archive/'.$new.'.zip', $new,
            ],
            'codeberg uppercase host' => [
                'https://CODEBERG.ORG/foo/bar/archive/'.$old.'.zip', $old,
                'https://CODEBERG.ORG/foo/bar/archive/'.$new.'.zip', $new,
            ],
            'codeberg api archive' => [
                'https://codeberg.org/api/v1/repos/foo/bar/archive/'.$old.'.zip', $old,
                'https://codeberg.org/api/v1/repos/foo/bar/archive/'.$new.'.zip', $new,
            ],
            'codeberg zip archive with query' => [
                'https://codeberg.org/api/v1/repos/foo/bar/archive/'.$old.'.zip?token=x', $old,
                'https://codeberg.org/api/v1/repos/foo/bar/archive/'.$new.'.zip?token=x', $new,
            ],
            'codeberg without prior dist reference' => [
                'https://codeberg.org/foo/bar/archive/'.$old.'.zip', null,
                'https://codeberg.org/foo/bar/archive/'.$new.'.zip', $new,
            ],
            'codeberg tar.gz is not rewritten' => [
                'https://codeberg.org/foo/bar/archive/'.$old.'.tar.gz', null,
                'https://codeberg.org/foo/bar/archive/'.$old.'.tar.gz', $new,
            ],
            'unknown host keeps url' => [
                'https://example.org/foo/bar/archive/'.$old.'.zip', $old,
                'https://example.org/foo/bar/archive/'.$old.'.zip', $new,
            ],
            'codeberg lookalike host is not matched' => [
                'https://notcodeberg.org/foo/bar/archive/'.$old.'.zip', null,
                'https://notcodeberg.org/foo/bar/archive/'.$old.'.zip', null,
            ],
        ];
    }
}
