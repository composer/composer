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

namespace Composer\Repository;

use Composer\Installer\InstallationManager;

/**
 * Writable array repository.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WritableArrayRepository extends ArrayRepository implements WritableRepositoryInterface
{
    use CanonicalPackagesTrait;

    /**
     * @var string[]
     */
    protected $devPackageNames = [];

    /** @var bool|null */
    private $devMode = null;

    /**
     * @return bool|null true if dev requirements were installed, false if --no-dev was used, null if yet unknown
     */
    public function getDevMode()
    {
        return $this->devMode;
    }

    /**
     * @inheritDoc
     */
    public function setDevPackageNames(array $devPackageNames)
    {
        $this->devPackageNames = $devPackageNames;
    }

    /**
     * @inheritDoc
     */
    public function getDevPackageNames()
    {
        return $this->devPackageNames;
    }

    /**
     * @inheritDoc
     */
    public function write(bool $devMode, InstallationManager $installationManager, ?array $restrictedRootFeatures = null)
    {
        $this->devMode = $devMode;
    }

    /**
     * @inheritDoc
     */
    public function reload()
    {
        $this->devMode = null;
    }
}
