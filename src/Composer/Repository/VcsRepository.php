<?php

namespace Composer\Repository;

use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Loader\ArrayLoader;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VcsRepository extends ArrayRepository
{
    protected $url;
    protected $packageName;
    protected $debug;
    protected $io;

    public function __construct(array $config, IOInterface $io, array $drivers = null)
    {
        $this->drivers = $drivers ?: array(
            'Composer\Repository\Vcs\GitHubDriver',
            'Composer\Repository\Vcs\GitBitbucketDriver',
            'Composer\Repository\Vcs\GitDriver',
            'Composer\Repository\Vcs\SvnDriver',
            'Composer\Repository\Vcs\HgBitbucketDriver',
            'Composer\Repository\Vcs\HgDriver',
        );

        $this->url = $config['url'];
        $this->io = $io;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    public function getDriver()
    {
        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->url)) {
                $driver = new $driver($this->url, $this->io);
                $driver->initialize();
                return $driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->url, true)) {
                $driver = new $driver($this->url, $this->io);
                $driver->initialize();
                return $driver;
            }
        }
    }

    protected function initialize()
    {
        parent::initialize();

        $debug = $this->debug;

        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $versionParser = new VersionParser;
        $loader = new ArrayLoader();

        if ($driver->hasComposerFile($driver->getRootIdentifier())) {
            $data = $driver->getComposerInformation($driver->getRootIdentifier());
            $this->packageName = !empty($data['name']) ? $data['name'] : null;
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $this->io->overwrite('Get composer of <info>' . $this->packageName . '</info> (<comment>' . $tag . '</comment>)', false);
            $parsedTag = $this->validateTag($versionParser, $tag);
            if ($parsedTag && $driver->hasComposerFile($identifier)) {
                try {
                    $data = $driver->getComposerInformation($identifier);
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'JSON Parse Error') !== false) {
                        if ($debug) {
                            $this->io->write('Skipped tag '.$tag.', '.$e->getMessage());
                        }
                        continue;
                    } else {
                        throw $e;
                    }
                }

                // manually versioned package
                if (isset($data['version'])) {
                    $data['version_normalized'] = $versionParser->normalize($data['version']);
                } else {
                    // auto-versionned package, read value from tag
                    $data['version'] = $tag;
                    $data['version_normalized'] = $parsedTag;
                }

                // make sure tag packages have no -dev flag
                $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
                $data['version_normalized'] = preg_replace('{[.-]?dev$}i', '', $data['version_normalized']);

                // broken package, version doesn't match tag
                if ($data['version_normalized'] !== $parsedTag) {
                    if ($debug) {
                        $this->io->write('Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json');
                    }
                    continue;
                }

                if ($debug) {
                    $this->io->write('Importing tag '.$tag.' ('.$data['version_normalized'].')');
                }

                $this->addPackage($loader->load($this->preProcess($driver, $data, $identifier)));
            } elseif ($debug) {
                $this->io->write('Skipped tag '.$tag.', '.($parsedTag ? 'no composer file was found' : 'invalid name'));
            }
        }

        $this->io->overwrite('', false);

        foreach ($driver->getBranches() as $branch => $identifier) {
            $this->io->overwrite('Get composer of <info>' . $this->packageName . '</info> (<comment>' . $branch . '</comment>)', false);
            $parsedBranch = $this->validateBranch($versionParser, $branch);
            if ($driver->hasComposerFile($identifier)) {
                $data = $driver->getComposerInformation($identifier);

                // manually versioned package
                if (isset($data['version'])) {
                    $data['version_normalized'] = $versionParser->normalize($data['version']);
                } elseif ($parsedBranch) {
                    // auto-versionned package, read value from branch name
                    $data['version'] = $branch;
                    $data['version_normalized'] = $parsedBranch;
                } else {
                    if ($debug) {
                        $this->io->write('Skipped branch '.$branch.', invalid name and no composer file was found');
                    }
                    continue;
                }

                // make sure branch packages have a -dev flag
                $normalizedStableVersion = preg_replace('{[.-]?dev$}i', '', $data['version_normalized']);
                $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']) . '-dev';
                $data['version_normalized'] = $normalizedStableVersion . '-dev';

                // Skip branches that contain a version that has been tagged already
                foreach ($this->getPackages() as $package) {
                    if ($normalizedStableVersion === $package->getVersion()) {
                        if ($debug) {
                            $this->io->write('Skipped branch '.$branch.', already tagged');
                        }

                        continue 2;
                    }
                }

                if ($debug) {
                    $this->io->write('Importing branch '.$branch.' ('.$data['version_normalized'].')');
                }

                $this->addPackage($loader->load($this->preProcess($driver, $data, $identifier)));
            } elseif ($debug) {
                $this->io->write('Skipped branch '.$branch.', no composer file was found');
            }
        }

        $this->io->overwrite('', false);
    }

    private function preProcess(VcsDriverInterface $driver, array $data, $identifier)
    {
        // keep the name of the main identifier for all packages
        $data['name'] = $this->packageName ?: $data['name'];

        if (!isset($data['dist'])) {
            $data['dist'] = $driver->getDist($identifier);
        }
        if (!isset($data['source'])) {
            $data['source'] = $driver->getSource($identifier);
        }

        return $data;
    }

    private function validateBranch($versionParser, $branch)
    {
        try {
            return $versionParser->normalizeBranch($branch);
        } catch (\Exception $e) {
        }

        return false;
    }

    private function validateTag($versionParser, $version)
    {
        try {
            return $versionParser->normalize($version);
        } catch (\Exception $e) {
        }

        return false;
    }
}
