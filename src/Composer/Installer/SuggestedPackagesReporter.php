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
use Composer\Repository\InstalledRepository;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Add suggested packages from different places to output them in the end.
 *
 * @author Haralan Dobrev <hkdobrev@gmail.com>
 */
class SuggestedPackagesReporter
{
    const MODE_LIST = 1;
    const MODE_BY_PACKAGE = 2;
    const MODE_BY_SUGGESTION = 4;

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
     *
     * Do not list the ones already installed if installed repository provided.
     *
     * @param  int                       $mode             One of the MODE_* constants from this class
     * @param  InstalledRepository|null  $installedRepo    If passed in, suggested packages which are installed already will be skipped
     * @param  PackageInterface|null     $onlyDependentsOf If passed in, only the suggestions from direct dependents of that package, or from the package itself, will be shown
     * @return SuggestedPackagesReporter
     */
    public function output($mode, InstalledRepository $installedRepo = null, PackageInterface $onlyDependentsOf = null)
    {
        $suggestedPackages = $this->getFilteredSuggestions($installedRepo, $onlyDependentsOf);

        $suggesters = array();
        $suggested = array();
        foreach ($suggestedPackages as $suggestion) {
            $suggesters[$suggestion['source']][$suggestion['target']] = $suggestion['reason'];
            $suggested[$suggestion['target']][$suggestion['source']] = $suggestion['reason'];
        }
        ksort($suggesters);
        ksort($suggested);

        // Simple mode
        if ($mode & self::MODE_LIST) {
            foreach (array_keys($suggested) as $name) {
                $this->io->write(sprintf('<info>%s</info>', $name));
            }

            return 0;
        }

        // Grouped by package
        if ($mode & self::MODE_BY_PACKAGE) {
            foreach ($suggesters as $suggester => $suggestions) {
                $this->io->write(sprintf('<comment>%s</comment> suggests:', $suggester));

                foreach ($suggestions as $suggestion => $reason) {
                    $this->io->write(sprintf(' - <info>%s</info>' . ($reason ? ': %s' : ''), $suggestion, $this->escapeOutput($reason)));
                }
                $this->io->write('');
            }
        }

        // Grouped by suggestion
        if ($mode & self::MODE_BY_SUGGESTION) {
            // Improve readability in full mode
            if ($mode & self::MODE_BY_PACKAGE) {
                $this->io->write(str_repeat('-', 78));
            }
            foreach ($suggested as $suggestion => $suggesters) {
                $this->io->write(sprintf('<comment>%s</comment> is suggested by:', $suggestion));

                foreach ($suggesters as $suggester => $reason) {
                    $this->io->write(sprintf(' - <info>%s</info>' . ($reason ? ': %s' : ''), $suggester, $this->escapeOutput($reason)));
                }
                $this->io->write('');
            }
        }

        if ($onlyDependentsOf) {
            $allSuggestedPackages = $this->getFilteredSuggestions($installedRepo);
            $diff = count($allSuggestedPackages) - count($suggestedPackages);
            if ($diff) {
                $this->io->write('<info>'.$diff.' additional suggestions</info> by transitive dependencies can be shown with <info>--all</info>');
            }
        }

        return $this;
    }

    /**
     * Output number of new suggested packages and a hint to use suggest command.
     *
     * @param  InstalledRepository|null  $installedRepo    If passed in, suggested packages which are installed already will be skipped
     * @param  PackageInterface|null     $onlyDependentsOf If passed in, only the suggestions from direct dependents of that package, or from the package itself, will be shown
     * @return SuggestedPackagesReporter
     */
    public function outputMinimalistic(InstalledRepository $installedRepo = null, PackageInterface $onlyDependentsOf = null)
    {
        $suggestedPackages = $this->getFilteredSuggestions($installedRepo, $onlyDependentsOf);
        if ($suggestedPackages) {
            $this->io->writeError('<info>'.count($suggestedPackages).' package suggestions were added by new dependencies, use `composer suggest` to see details.</info>');
        }

        return $this;
    }

    /**
     * @param  InstalledRepository|null $installedRepo    If passed in, suggested packages which are installed already will be skipped
     * @param  PackageInterface|null    $onlyDependentsOf If passed in, only the suggestions from direct dependents of that package, or from the package itself, will be shown
     * @return array[]
     */
    private function getFilteredSuggestions(InstalledRepository $installedRepo = null, PackageInterface $onlyDependentsOf = null)
    {
        $suggestedPackages = $this->getPackages();
        $installedNames = array();
        if (null !== $installedRepo && !empty($suggestedPackages)) {
            foreach ($installedRepo->getPackages() as $package) {
                $installedNames = array_merge(
                    $installedNames,
                    $package->getNames()
                );
            }
        }

        $sourceFilter = array();
        if ($onlyDependentsOf) {
            $sourceFilter = array_map(function ($link) {
                return $link->getTarget();
            }, array_merge($onlyDependentsOf->getRequires(), $onlyDependentsOf->getDevRequires()));
            $sourceFilter[] = $onlyDependentsOf->getName();
        }

        $suggestions = array();
        foreach ($suggestedPackages as $suggestion) {
            if (in_array($suggestion['target'], $installedNames) || ($sourceFilter && !in_array($suggestion['source'], $sourceFilter))) {
                continue;
            }

            $suggestions[] = $suggestion;
        }

        return $suggestions;
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
