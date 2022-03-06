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

namespace Composer\DependencyResolver\Operation;

use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * Solver update operation.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class UpdateOperation extends SolverOperation implements OperationInterface
{
    protected const TYPE = 'update';

    /**
     * @var PackageInterface
     */
    protected $initialPackage;

    /**
     * @var PackageInterface
     */
    protected $targetPackage;

    /**
     * @param PackageInterface $initial initial package
     * @param PackageInterface $target  target package (updated)
     */
    public function __construct(PackageInterface $initial, PackageInterface $target)
    {
        $this->initialPackage = $initial;
        $this->targetPackage = $target;
    }

    /**
     * Returns initial package.
     *
     * @return PackageInterface
     */
    public function getInitialPackage(): PackageInterface
    {
        return $this->initialPackage;
    }

    /**
     * Returns target package.
     *
     * @return PackageInterface
     */
    public function getTargetPackage(): PackageInterface
    {
        return $this->targetPackage;
    }

    /**
     * @inheritDoc
     */
    public function show($lock): string
    {
        return self::format($this->initialPackage, $this->targetPackage, $lock);
    }

    /**
     * @param bool $lock
     * @return string
     */
    public static function format(PackageInterface $initialPackage, PackageInterface $targetPackage, bool $lock = false): string
    {
        $fromVersion = $initialPackage->getFullPrettyVersion();
        $toVersion = $targetPackage->getFullPrettyVersion();

        if ($fromVersion === $toVersion && $initialPackage->getSourceReference() !== $targetPackage->getSourceReference()) {
            $fromVersion = $initialPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_SOURCE_REF);
            $toVersion = $targetPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_SOURCE_REF);
        } elseif ($fromVersion === $toVersion && $initialPackage->getDistReference() !== $targetPackage->getDistReference()) {
            $fromVersion = $initialPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_DIST_REF);
            $toVersion = $targetPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_DIST_REF);
        }

        $actionName = VersionParser::isUpgrade($initialPackage->getVersion(), $targetPackage->getVersion()) ? 'Upgrading' : 'Downgrading';

        return $actionName.' <info>'.$initialPackage->getPrettyName().'</info> (<comment>'.$fromVersion.'</comment> => <comment>'.$toVersion.'</comment>)';
    }
}
