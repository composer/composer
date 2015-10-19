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

namespace Composer\Command;

use Composer\Installer;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Marek Viger <marek.viger@gmail.com>
 */
class OutdatedCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('outdated')
            ->setDescription('Check for outdated packages')
            ->setDefinition(array(
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Skip require-dev packages.'),
                new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
            ))
            ->setHelp(<<<EOT
The <info>outdated</info> command reads the composer.json file from the
current directory, processes it, and shows outdated packages and their latest versions.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer(true);

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, $this->getName(), $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $preferStable = $composer->getPackage()->getPreferStable();
        if ($input->getOption('prefer-stable')) {
            $preferStable = true;
        }

        // Load required packages
        $requiredPackages = $this->getRequiredPackages($input->getOption('no-dev'));

        // Load installed packages
        $installedPackages = $this->getIndexedPackageArray($composer->getRepositoryManager()->getLocalRepository());

        // Load latest versions
        $latestVersions = $this->getLatestVersions($requiredPackages, $preferStable);

        $outputLines = array(
            // Table header
            $this->getPaddedLine(array(
                '<options=underscore>Package</>',
                '<options=underscore>Current</>',
                '<options=underscore>Wanted</>',
                '<options=underscore>Latest</>',
            )),
        );

        // Process packages
        foreach ($requiredPackages as $packageName => $package) {
            $installedVersion = '';
            $latestVersion = '';

            if (isset($installedPackages[$packageName])) {
                $installedVersion = $installedPackages[$packageName]->getPrettyVersion();
            }

            if (isset($latestVersions[$packageName])) {
                $latestVersion = $latestVersions[$packageName];
            }

            if (Comparator::greaterThanOrEqualTo($installedVersion, $latestVersion)) {
                continue;
            }

            // Table line
            $outputLines[] = $this->getPaddedLine(array(
                sprintf('<comment>%s</comment>', $packageName),
                $installedVersion,
                sprintf('<comment>%s</comment>', $package->getPrettyConstraint()),
                sprintf('<info>%s</info>', $latestVersion),
            ));
        }

        // Print package table if there is more than a header
        if ($outputLines > 1) {
            $this->getIO()->write($outputLines);
        }

        return 0;
    }

    /**
     * Get all required packages
     *
     * @param bool|false $noDev
     * @return \Composer\Package\Link[]
     */
    private function getRequiredPackages($noDev = false)
    {
        $rootPackage = $this->getComposer()->getPackage();

        // Required packages
        $packages = $rootPackage->getRequires();

        // Add dev packages
        if (!$noDev) {
            $packages = array_merge($packages, $rootPackage->getDevRequires());
        }

        // Remove platform and extensions
        $platformRepository = new PlatformRepository();
        foreach ($platformRepository->getPackages() as $platformPackage) {
            if (array_key_exists($platformPackage->getPrettyName(), $packages)) {
                unset($packages[$platformPackage->getPrettyName()]);
            }
        }

        return $packages;
    }

    /**
     * Get latest versions of required packages
     *
     * @param \Composer\Package\Link[] $requiredPackages
     * @param bool $preferStable
     * @return array
     */
    private function getLatestVersions($requiredPackages, $preferStable = true)
    {
        $repositoryManager = $this->getComposer()->getRepositoryManager();

        $versions = array();
        foreach ($requiredPackages as $packageName => $package) {
            /** @var \Composer\Package\PackageInterface[] $foundPackages */
            $foundPackages = $repositoryManager->findPackages($packageName, '*');

            $packageVersions = array();
            foreach ($foundPackages as $foundPackage) {
                // skip branches
                if (strpos($foundPackage->getPrettyVersion(), 'dev-') === 0) {
                    continue;
                }

                // skip dev versions
                if ($preferStable && strpos($foundPackage->getPrettyVersion(), '-dev') !== false) {
                    continue;
                }

                $packageVersions[] = $foundPackage->getPrettyVersion();
            }

            $packageVersions = Semver::sort($packageVersions);
            $versions[$packageName] = array_pop($packageVersions);
        }

        return $versions;
    }

    /**
     * Return array of packages indexed with their name
     *
     * @param \Composer\Repository\RepositoryInterface $repository
     * @return \Composer\Package\PackageInterface[]
     */
    private function getIndexedPackageArray($repository)
    {
        $packages = array();
        foreach ($repository->getPackages() as $package) {
            $packages[$package->getPrettyName()] = $package;
        }

        return $packages;
    }

    /**
     * Correct padding of string with formatting tags
     *
     * @param string $string String to be padded
     * @param integer $length Length of padded string
     * @param integer|null $padType Padding type, default is STR_PAD_LEFT
     * @param string|null $padString Padding string, default is space
     * @return string
     * @see str_pad
     */
    private function padFormattedString($string, $length, $padType = STR_PAD_LEFT, $padString = ' ')
    {
        $strippedString = strip_tags($string);
        $length += strlen($string) - strlen($strippedString);

        return str_pad($string, $length, $padString, $padType);
    }

    /**
     * Return padded line
     *
     * @param array $columns
     * @return string
     */
    private function getPaddedLine($columns)
    {
        $paddings = array(
            array(40, STR_PAD_RIGHT),
            array(12, STR_PAD_LEFT),
            array(12, STR_PAD_LEFT),
            array(12, STR_PAD_LEFT),
        );

        $columns = (array) $columns;
        $line = '';
        foreach ($columns as $index => $string) {
            $line .= $this->padFormattedString($string, $paddings[$index][0], $paddings[$index][1]);
        }

        return $line;
    }
}
