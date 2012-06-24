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

namespace Composer\Repository;

use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\Pear\ChannelReader;
use Composer\Package\MemoryPackage;
use Composer\Repository\Pear\ChannelInfo;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Util\RemoteFilesystem;
use Composer\Config;

/**
 * Builds list of package from PEAR channel.
 *
 * Packages read from channel are named as 'pear-{channelName}/{packageName}'
 * and has aliased as 'pear-{channelAlias}/{packageName}'
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PearRepository extends ArrayRepository
{
    private $url;
    private $io;
    private $rfs;
    private $versionParser;

    /** @var string vendor makes additional alias for each channel as {prefix}/{packagename}. It allows smoother
     * package transition to composer-like repositories.
     */
    private $vendorAlias;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, RemoteFilesystem $rfs = null)
    {
        if (!preg_match('{^https?://}', $repoConfig['url'])) {
            $repoConfig['url'] = 'http://'.$repoConfig['url'];
        }

        if (function_exists('filter_var') && version_compare(PHP_VERSION, '5.3.3', '>=') && !filter_var($repoConfig['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository: '.$repoConfig['url']);
        }

        $this->url = rtrim($repoConfig['url'], '/');
        $this->io = $io;
        $this->rfs = $rfs ?: new RemoteFilesystem($this->io);
        $this->vendorAlias = isset($repoConfig['vendor-alias']) ? $repoConfig['vendor-alias'] : null;
        $this->versionParser = new VersionParser();
    }

    protected function initialize()
    {
        parent::initialize();

        $this->io->write('Initializing PEAR repository '.$this->url);

        $reader = new ChannelReader($this->rfs);
        try {
            $channelInfo = $reader->read($this->url);
        } catch (\Exception $e) {
            $this->io->write('<warning>PEAR repository from '.$this->url.' could not be loaded. '.$e->getMessage().'</warning>');
            return;
        }
        $packages = $this->buildComposerPackages($channelInfo, $this->versionParser);
        foreach ($packages as $package) {
            $this->addPackage($package);
        }
    }

    /**
     * Builds MemoryPackages from PEAR package definition data.
     *
     * @param  ChannelInfo   $channelInfo
     * @return MemoryPackage
     */
    private function buildComposerPackages(ChannelInfo $channelInfo, VersionParser $versionParser)
    {
        $result = array();
        foreach ($channelInfo->getPackages() as $packageDefinition) {
            foreach ($packageDefinition->getReleases() as $version => $releaseInfo) {
                $normalizedVersion = $this->parseVersion($version);
                if (!$normalizedVersion) {
                    continue; // skip packages with unparsable versions
                }

                $composerPackageName = $this->buildComposerPackageName($packageDefinition->getChannelName(), $packageDefinition->getPackageName());

                // distribution url must be read from /r/{packageName}/{version}.xml::/r/g:text()
                // but this location is 'de-facto' standard
                $distUrl = "http://{$packageDefinition->getChannelName()}/get/{$packageDefinition->getPackageName()}-{$version}.tgz";

                $requires = array();
                $suggests = array();
                $conflicts = array();
                $replaces = array();

                // alias package only when its channel matches repository channel,
                // cause we've know only repository channel alias
                if ($channelInfo->getName() == $packageDefinition->getChannelName()) {
                    $composerPackageAlias = $this->buildComposerPackageName($channelInfo->getAlias(), $packageDefinition->getPackageName());
                    $aliasConstraint = new VersionConstraint('==', $normalizedVersion);
                    $replaces[] = new Link($composerPackageName, $composerPackageAlias, $aliasConstraint, 'replaces', (string) $aliasConstraint);
                }

                // alias package with user-specified prefix. it makes private pear channels looks like composer's.
                if (!empty($this->vendorAlias)) {
                    $composerPackageAlias = "{$this->vendorAlias}/{$packageDefinition->getPackageName()}";
                    $aliasConstraint = new VersionConstraint('==', $normalizedVersion);
                    $replaces[] = new Link($composerPackageName, $composerPackageAlias, $aliasConstraint, 'replaces', (string) $aliasConstraint);
                }

                foreach ($releaseInfo->getDependencyInfo()->getRequires() as $dependencyConstraint) {
                    $dependencyPackageName = $this->buildComposerPackageName($dependencyConstraint->getChannelName(), $dependencyConstraint->getPackageName());
                    $constraint = $versionParser->parseConstraints($dependencyConstraint->getConstraint());
                    $link = new Link($composerPackageName, $dependencyPackageName, $constraint, $dependencyConstraint->getType(), $dependencyConstraint->getConstraint());
                    switch ($dependencyConstraint->getType()) {
                        case 'required':
                            $requires[] = $link;
                            break;
                        case 'conflicts':
                            $conflicts[] = $link;
                            break;
                        case 'replaces':
                            $replaces[] = $link;
                            break;
                    }
                }

                foreach ($releaseInfo->getDependencyInfo()->getOptionals() as $group => $dependencyConstraints) {
                    foreach ($dependencyConstraints as $dependencyConstraint) {
                        $dependencyPackageName = $this->buildComposerPackageName($dependencyConstraint->getChannelName(), $dependencyConstraint->getPackageName());
                        $suggests[$group.'-'.$dependencyPackageName] = $dependencyConstraint->getConstraint();
                    }
                }

                $package = new MemoryPackage($composerPackageName, $normalizedVersion, $version);
                $package->setType('library');
                $package->setDescription($packageDefinition->getDescription());
                $package->setDistType('pear');
                $package->setDistUrl($distUrl);
                $package->setAutoload(array('classmap' => array('')));
                $package->setIncludePaths(array('/'));
                $package->setRequires($requires);
                $package->setConflicts($conflicts);
                $package->setSuggests($suggests);
                $package->setReplaces($replaces);
                $result[] = $package;
            }
        }

        return $result;
    }

    private function buildComposerPackageName($channelName, $packageName)
    {
        if ('php' === $channelName) {
            return "php";
        }
        if ('ext' === $channelName) {
            return "ext-{$packageName}";
        }

        return "pear-{$channelName}/{$packageName}";
    }

    /**
     * Softened version parser.
     *
     * @param string $version
     * @return null|string
     */
    private function parseVersion($version)
    {
        if (preg_match('{^v?(\d{1,3})(\.\d+)?(\.\d+)?(\.\d+)?}i', $version, $matches)) {
            $version = $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0')
                .(!empty($matches[4]) ? $matches[4] : '.0');

            return $version;
        }

        return null;
    }
}
