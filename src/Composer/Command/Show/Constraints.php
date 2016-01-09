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

namespace Composer\Command\Show;

use Composer\Repository\RepositoryInterface;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;

class Constraints
{

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var array
     */
    private $minPackageLinks;

    /**
     * @var int
     */
    private $nameLength;

    /**
     * @var RepositoryInterface
     */
    private $repos;

    /**
     *
     * @var int
     */
    private $versionLength;

    /**
     * @param RepositoryInterface $repos
     * @param IOInterface $io
     */
    public function __construct(RepositoryInterface $repos, IOInterface $io)
    {
        $this->repos = $repos;
        $this->io = $io;
        $this->minPackageLinks = array();
        $this->nameLength = 0;
        $this->versionLength = 0;
    }

    public function writeToOutput()
    {
        $this->processPackages();
        foreach($this->minPackageLinks as $packageLink) {
            $this->io->write($this->formatPackageLink($packageLink));
        }
    }

    private function processPackages()
    {
        foreach ($this->repos->getPackages() as $package) {
            if ($package instanceof CompletePackageInterface) {
                $this->processPackageDependencies($package);
            }
        }
    }

    /**
     * @param CompletePackageInterface $package
     */
    private function processPackageDependencies(CompletePackageInterface $package)
    {
        $dependencies = $package->getRequires();
        if (is_array($dependencies)) {
            foreach ($dependencies as $packageLink) {
                if ($packageLink instanceof Link) {
                    $this->processPackageLink($packageLink);
                }
            }
        }
    }

    /**
     * @param Link $packageLink
     */
    private function processPackageLink(Link $packageLink)
    {
        if (!$this->hasMinLinkForTarget($packageLink) || $this->compareVersions($packageLink)) {
            $this->minPackageLinks[$packageLink->getTarget()] = $packageLink;
            $this->nameLength = max($this->nameLength, strlen($packageLink->getTarget()));
            $this->versionLength = max($this->versionLength, strlen($packageLink->getPrettyConstraint()));
        }
    }

    /**
     * @param Link $packageLink
     * @return boolean
     */
    private function compareVersions(Link $packageLink)
    {
        return version_compare(
            $packageLink->getPrettyConstraint(),
            $this->minPackageLinks[$packageLink->getTarget()]->getPrettyConstraint()
        ) > -1;
    }

    /**
     * @param Link $packageLink
     * @return boolean
     */
    private function hasMinLinkForTarget(Link $packageLink)
    {
        return isset($this->minPackageLinks[$packageLink->getTarget()]);
    }

    /**
     * @param Link $packageLink
     * @return string
     */
    private function formatPackageLink(Link $packageLink)
    {
        return sprintf(
            '<info>%s</info> %s <comment>%s</comment>',
            str_pad($packageLink->getTarget(), $this->nameLength),
            str_pad($packageLink->getPrettyConstraint(), $this->versionLength),
            $packageLink->getSource()
        );
    }
}