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

namespace Composer\Advisory;

use Composer\Package\BasePackage;

/**
 * @readonly
 * @final
 */
class PackageWithSecurityAdvisories
{
    /** @var list<PartialSecurityAdvisory|SecurityAdvisory>  */
    public $advisories;
    /** @var string */
    private $packageName;
    /** @var string */
    private $prettyVersion;

    /**
     * @param list<PartialSecurityAdvisory|SecurityAdvisory> $advisories
     */
    public function __construct(
        string $packageName,
        string $prettyVersion,
        array $advisories
    ) {
        $this->packageName = $packageName;
        $this->prettyVersion = $prettyVersion;
        $this->advisories = $advisories;
    }
}
