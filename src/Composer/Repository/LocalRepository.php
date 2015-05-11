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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Package;

/**
 * @author Tim Glabisch <tim.glabisch@sensiolabs.de>
 */
class LocalRepository extends ArrayRepository
{
    /** @var LoaderInterface */
    protected $loader;

    protected $lookup;

    protected $config;

    public function __construct(array $repoConfig, IOInterface $io, Config $config = null)
    {
        $this->loader = new ArrayLoader();
        $this->lookup = $repoConfig['url'];
        $this->io = $io;
        $this->config = $config;
    }

    protected function initialize()
    {
        parent::initialize();

        $this->scanDirectory($this->lookup);
    }

    private function getPathBlacklist($path)
    {
        $blacklist = [];

        $directory = new \RecursiveDirectoryIterator($path);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/\/composer.json$/i');
        foreach ($regex as $file) {

            /* @var $file \SplFileInfo */

            $package = $this->getComposerInformation($file);

            if ($package instanceof CompletePackageInterface) {
                //$package->getV
            }

            // if the package is a part of the vendor dir, exclude it.
            // exclude all other vendor directories
            if ($this->config->has('vendor-dir') && strpos($file->getRealpath(), $this->config->get('vendor-dir')) === 0) {
                $blacklist[] = $file->getPath();
                continue;
            } elseif (strpos($file->getPathname(), '/vendor/') !== false) {
                $blacklist[] = $file->getPath();
                continue;
            }

            // don't try to find vendors that are managed by composer/installers
            if (in_array('composer/installers', array_keys($package->getRequires()))) {
                $blacklist[] = $file->getPath();
                continue;
            }
        }

        return $blacklist;
    }

    private function isLocalDependency(Package $package)
    {
        return in_array('local-dependency', array_keys($package->getExtra()));
    }

    private function scanDirectory($path)
    {
        $io = $this->io;

        $directory = new \RecursiveDirectoryIterator($path);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/composer.json/i');
        foreach ($regex as $file) {

            /* @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $package = $this->getComposerInformation($file);
            foreach($this->getPathBlacklist($path) as $pathBlacklist) {
                if (strpos($file->getPathname(), $pathBlacklist) !== false) {
                    continue 2;
                }
            }

            if (!$this->isLocalDependency($package)) {
                if ($io->isVerbose()) {
                    $io->writeError("Package <comment>{$package->getName()}</comment> seems to hold a local package, but the extra key 'local-dependency' is not defined.");
                }
                continue;
            }

            if (!$package) {
                if ($io->isVerbose()) {
                    $io->writeError("File <comment>{$file->getBasename()}</comment> doesn't seem to hold a package");
                }
                continue;
            }

            if ($io->isVerbose()) {
                $template = 'Found package <info>%s</info> (<comment>%s</comment>) in file <info>%s</info>';
                $io->writeError(sprintf($template, $package->getName(), $package->getPrettyVersion(), $file->getBasename()));
            }

            $this->addPackage($package);
        }
    }

    private function getComposerInformation(\SplFileInfo $file)
    {
        $json = file_get_contents($file->getPathname());

        $package = JsonFile::parseJson($json);
        $package['dist'] = array(
            'type' => 'local',
            'url' => $file->getRealPath(),
            'shasum' => sha1_file($file->getRealPath())
        );
        $package['version'] = '1.0.0';

        $pkg = $this->loader->load($package);
        $pkg->ensurePackageReinstalls();

        return $pkg;
    }
}
