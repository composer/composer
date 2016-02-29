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
use Composer\Semver\VersionParser as SemverVersionParser;
use Composer\Package\Version\VersionParser;
use Composer\Repository\Pear\ChannelReader;
use Composer\Package\CompletePackage;
use Composer\Repository\Pear\ChannelInfo;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\RemoteFilesystem;
use Composer\Config;
use Composer\Factory;

/**
 * Builds list of package from PEAR channel.
 *
 * Packages read from channel are named as 'pear-{channelName}/{packageName}'
 * and has aliased as 'pear-{channelAlias}/{packageName}'
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PearRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    private $url;
    private $io;
    private $rfs;
    private $versionParser;
    private $repoConfig;

    /** @var string vendor makes additional alias for each channel as {prefix}/{packagename}. It allows smoother
     * package transition to composer-like repositories.
     */
    private $vendorAlias;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $dispatcher = null, RemoteFilesystem $rfs = null)
    {
        parent::__construct();
        if (!preg_match('{^https?://}', $repoConfig['url'])) {
            $repoConfig['url'] = 'http://'.$repoConfig['url'];
        }

        $urlBits = parse_url($repoConfig['url']);
        if (empty($urlBits['scheme']) || empty($urlBits['host'])) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository: '.$repoConfig['url']);
        }

        $this->url = rtrim($repoConfig['url'], '/');
        $this->io = $io;
        $this->rfs = $rfs ?: Factory::createRemoteFilesystem($this->io, $config);
        $this->vendorAlias = isset($repoConfig['vendor-alias']) ? $repoConfig['vendor-alias'] : null;
        $this->versionParser = new VersionParser();
        $this->repoConfig = $repoConfig;
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    protected function initialize()
    {
        parent::initialize();

        $this->io->writeError('Initializing PEAR repository '.$this->url);

        $reader = new ChannelReader($this->rfs);
        try {
            $channelInfo = $reader->read($this->url);
        } catch (\Exception $e) {
            $this->io->writeError('<warning>PEAR repository from '.$this->url.' could not be loaded. '.$e->getMessage().'</warning>');

            return;
        }
        $packages = $this->buildComposerPackages($channelInfo, $this->versionParser);
        foreach ($packages as $package) {
            $this->addPackage($package);
        }
    }

    /**
     * Builds CompletePackages from PEAR package definition data.
     *
     * @param  ChannelInfo         $channelInfo
     * @param  SemverVersionParser $versionParser
     * @return CompletePackage
     */
    private function buildComposerPackages(ChannelInfo $channelInfo, SemverVersionParser $versionParser)
    {
        $result = array();
        foreach ($channelInfo->getPackages() as $packageDefinition) {
            foreach ($packageDefinition->getReleases() as $version => $releaseInfo) {
                try {
                    $normalizedVersion = $versionParser->normalize($version);
                } catch (\UnexpectedValueException $e) {
                    $this->io->writeError('Could not load '.$packageDefinition->getPackageName().' '.$version.': '.$e->getMessage(), true, IOInterface::VERBOSE);
                    continue;
                }

                $composerPackageName = $this->buildComposerPackageName($packageDefinition->getChannelName(), $packageDefinition->getPackageName());

                // distribution url must be read from /r/{packageName}/{version}.xml::/r/g:text()
                // but this location is 'de-facto' standard
                $urlBits = parse_url($this->url);
                $scheme = (isset($urlBits['scheme']) && 'https' === $urlBits['scheme'] && extension_loaded('openssl')) ? 'https' : 'http';
                $distUrl = "{$scheme}://{$packageDefinition->getChannelName()}/get/{$packageDefinition->getPackageName()}-{$version}.tgz";

                $requires = array();
                $suggests = array();
                $conflicts = array();
                $replaces = array();

                // alias package only when its channel matches repository channel,
                // cause we've know only repository channel alias
                if ($channelInfo->getName() == $packageDefinition->getChannelName()) {
                    $composerPackageAlias = $this->buildComposerPackageName($channelInfo->getAlias(), $packageDefinition->getPackageName());
                    $aliasConstraint = new Constraint('==', $normalizedVersion);
                    $replaces[] = new Link($composerPackageName, $composerPackageAlias, $aliasConstraint, 'replaces', (string) $aliasConstraint);
                }

                // alias package with user-specified prefix. it makes private pear channels looks like composer's.
                if (!empty($this->vendorAlias)
                    && ($this->vendorAlias != 'pear-'.$channelInfo->getAlias() || $channelInfo->getName() != $packageDefinition->getChannelName())
                ) {
                    $composerPackageAlias = "{$this->vendorAlias}/{$packageDefinition->getPackageName()}";
                    $aliasConstraint = new Constraint('==', $normalizedVersion);
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

                $package = new CompletePackage($composerPackageName, $normalizedVersion, $version);
                $package->setType('pear-library');
                $package->setDescription($packageDefinition->getDescription());
                $package->setLicense(array($packageDefinition->getLicense()));
                $package->setDistType('file');
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
}
