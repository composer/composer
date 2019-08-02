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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Add suggested packages from different places to output them in the end.
 *
 * @author Haralan Dobrev <hkdobrev@gmail.com>
 */
class SuggestedPackagesReporter
{
    /**
     * @var array
     */
    protected $suggestedPackages = array();

    /**
     * @var IOInterface
     */
    private $io;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * @return array Suggested packages with source, target and reason keys.
     */
    public function getPackages()
    {
        return $this->suggestedPackages;
    }

    /**
     * Add suggested packages to be listed after install
     *
     * Could be used to add suggested packages both from the installer
     * or from CreateProjectCommand.
     *
     * @param  string                    $source Source package which made the suggestion
     * @param  string                    $target Target package to be suggested
     * @param  string                    $reason Reason the target package to be suggested
     * @return SuggestedPackagesReporter
     */
    public function addPackage($source, $target, $reason)
    {
        $this->suggestedPackages[] = array(
            'source' => $source,
            'target' => $target,
            'reason' => $reason,
        );

        return $this;
    }

    /**
     * Add all suggestions from a package.
     *
     * @param  PackageInterface          $package
     * @return SuggestedPackagesReporter
     */
    public function addSuggestionsFromPackage(PackageInterface $package)
    {
        $source = $package->getPrettyName();
        foreach ($package->getSuggests() as $target => $reason) {
            $this->addPackage(
                $source,
                $target,
                $reason
            );
        }

        return $this;
    }

    /**
     * Output suggested packages.
     * Do not list the ones already installed if installed repository provided.
     *
     * @param  RepositoryInterface       $installedRepo Installed packages
     * @return SuggestedPackagesReporter
     */
    public function output(RepositoryInterface $installedRepo = null)
    {
        $suggestedPackages = $this->getPackages();
        $installedPackages = array();
        if (null !== $installedRepo && ! empty($suggestedPackages)) {
            foreach ($installedRepo->getPackages() as $package) {
                $installedPackages = array_merge(
                    $installedPackages,
                    $package->getNames()
                );
            }
        }

        foreach ($suggestedPackages as $suggestion) {
            if (in_array($suggestion['target'], $installedPackages)) {
                continue;
            }

            $this->io->writeError(sprintf(
                '%s suggests installing %s%s',
                $suggestion['source'],
                $this->escapeOutput($suggestion['target']),
                $this->escapeOutput('' !== $suggestion['reason'] ? ' ('.$suggestion['reason'].')' : '')
            ));
        }

        return $this;
    }

    /**
     * @param  string $string
     * @return string
     */
    private function escapeOutput($string)
    {
        return OutputFormatter::escape(
            $this->removeControlCharacters($string)
        );
    }

    /**
     * @param  string $string
     * @return string
     */
    private function removeControlCharacters($string)
    {
        return preg_replace(
            '/[[:cntrl:]]/',
            '',
            str_replace("\n", ' ', $string)
        );
    }
}
