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

namespace Composer\Package\Loader;

use Composer\Package\CompletePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackage;
use Composer\Package\BasePackage;

/**
 * Defines a loader that takes an array to create package instances
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface LoaderInterface
{
    /**
     * Converts a package from an array to a real instance
     *
     * @param  array<int|string|bool|null|array<int|string|bool|null|array<int|string|bool|null|array<int|string|bool|null|array<int|string|bool|null|array<int|string|bool|null|array<int|string|bool|null>>>>>>> $config package data
     * @param  string  $class  FQCN to be instantiated
     *
     * @return CompletePackage|CompleteAliasPackage|RootPackage|RootAliasPackage
     *
     * @phpstan-param class-string<CompletePackage|RootPackage> $class
     */
    public function load(array $config, string $class = 'Composer\Package\CompletePackage'): BasePackage;
}
