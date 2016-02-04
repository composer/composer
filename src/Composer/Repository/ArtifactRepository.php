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
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\LoaderInterface;

/**
 * @author Serge Smertin <serg.smertin@gmail.com>
 */
class ArtifactRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /** @var LoaderInterface */
    protected $loader;

    protected $lookup;
    protected $repoConfig;

    public function __construct(array $repoConfig, IOInterface $io)
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('The artifact repository requires PHP\'s zip extension');
        }

        $this->loader = new ArrayLoader();
        $this->lookup = $repoConfig['url'];
        $this->io = $io;
        $this->repoConfig = $repoConfig;
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    protected function initialize()
    {
        parent::initialize();

        $this->scanDirectory($this->lookup);
    }

    private function scanDirectory($path)
    {
        $io = $this->io;

        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/^.+\.(zip|phar)$/i');
        foreach ($regex as $file) {
            /* @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $package = $this->getComposerInformation($file);
            if (!$package) {
                $io->writeError("File <comment>{$file->getBasename()}</comment> doesn't seem to hold a package", true, IOInterface::VERBOSE);
                continue;
            }

            $template = 'Found package <info>%s</info> (<comment>%s</comment>) in file <info>%s</info>';
            $io->writeError(sprintf($template, $package->getName(), $package->getPrettyVersion(), $file->getBasename()), true, IOInterface::VERBOSE);

            $this->addPackage($package);
        }
    }

    /**
     * Find a file by name, returning the one that has the shortest path.
     *
     * @param \ZipArchive $zip
     * @param $filename
     * @return bool|int
     */
    private function locateFile(\ZipArchive $zip, $filename)
    {
        $indexOfShortestMatch = false;
        $lengthOfShortestMatch = -1;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (strcmp(basename($stat['name']), $filename) === 0) {
                $directoryName = dirname($stat['name']);
                if ($directoryName == '.') {
                    //if composer.json is in root directory
                    //it has to be the one to use.
                    return $i;
                }

                if (strpos($directoryName, '\\') !== false ||
                   strpos($directoryName, '/') !== false) {
                    //composer.json files below first directory are rejected
                    continue;
                }

                $length = strlen($stat['name']);
                if ($indexOfShortestMatch == false || $length < $lengthOfShortestMatch) {
                    //Check it's not a directory.
                    $contents = $zip->getFromIndex($i);
                    if ($contents !== false) {
                        $indexOfShortestMatch = $i;
                        $lengthOfShortestMatch = $length;
                    }
                }
            }
        }

        return $indexOfShortestMatch;
    }

    private function getComposerInformation(\SplFileInfo $file)
    {
        $zip = new \ZipArchive();
        $zip->open($file->getPathname());

        if (0 == $zip->numFiles) {
            return false;
        }

        $foundFileIndex = $this->locateFile($zip, 'composer.json');
        if (false === $foundFileIndex) {
            return false;
        }

        $configurationFileName = $zip->getNameIndex($foundFileIndex);

        $composerFile = "zip://{$file->getPathname()}#$configurationFileName";
        $json = file_get_contents($composerFile);

        $package = JsonFile::parseJson($json, $composerFile);
        $package['dist'] = array(
            'type' => 'zip',
            'url' => $file->getPathname(),
            'shasum' => sha1_file($file->getRealPath()),
        );

        $package = $this->loader->load($package);

        return $package;
    }
}
