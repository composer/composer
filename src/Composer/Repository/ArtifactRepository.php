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

/**
 * @author Serge Smertin <serg.smertin@gmail.com>
 */
class ArtifactRepository extends ArrayRepository
{
    /** @var LoaderInterface */
    protected $loader;

    protected $lookup;

    public function __construct(array $repoConfig, IOInterface $io)
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('The artifact repository requires PHP\'s zip extension');
        }

        $this->loader = new ArrayLoader();
        $this->lookup = $repoConfig['url'];
        $this->io = $io;
    }

    protected function initialize()
    {
        parent::initialize();

        $this->scanDirectory($this->lookup);
    }

    private function scanDirectory($path)
    {
        $io = $this->io;

        $directory = new \RecursiveDirectoryIterator($path);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/^.+\.(zip|phar)$/i');
        foreach ($regex as $file) {
            /* @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $package = $this->getComposerInformation($file);
            if (!$package) {
                if ($io->isVerbose()) {
                    $io->write("File <comment>{$file->getBasename()}</comment> doesn't seem to hold a package");
                }
                continue;
            }

            if ($io->isVerbose()) {
                $template = 'Found package <info>%s</info> (<comment>%s</comment>) in file <info>%s</info>';
                $io->write(sprintf($template, $package->getName(), $package->getPrettyVersion(), $file->getBasename()));
            }

            $this->addPackage($package);
        }
    }

    private function getComposerInformation(\SplFileInfo $file)
    {
        $zip = new \ZipArchive();
        $zip->open($file->getPathname());

        if (0 == $zip->numFiles) {
            return false;
        }

        $foundFileIndex = $zip->locateName('composer.json', \ZipArchive::FL_NODIR);
        if (false === $foundFileIndex) {
            return false;
        }

        $configurationFileName = $zip->getNameIndex($foundFileIndex);

        $composerFile = "zip://{$file->getPathname()}#$configurationFileName";
        $json = file_get_contents($composerFile);

        $package = JsonFile::parseJson($json, $composerFile);
        $package['dist'] = array(
            'type' => 'zip',
            'url' => $file->getRealPath(),
            'reference' => $file->getBasename(),
            'shasum' => sha1_file($file->getRealPath())
        );

        $package = $this->loader->load($package);

        return $package;
    }
}
