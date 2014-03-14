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

namespace Composer\Package;

use Symfony\Component\Finder\Finder;
use Composer\IO\IOInterface;

/**
 * Generates a manifest of all packaged files and checksums in JSON format
 *
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class Manifest
{

    private $directory = null;

    public function __construct($directory, Finder $finder = null)
    {
        if (!file_exists($directory)) {
            // throw exception
        }
        $this->directory = rtrim($directory, '/\\');
        if ($finder) {
            $this->finder = $finder;
        } else {
            $this->finder = new Finder;
        }
    }

    public function assemble($checksumAlgo = 'sha256')
    {
        $patterns = array();
        $gitignoreFile = $this->directory . DIRECTORY_SEPARATOR . '.gitignore');
        if (file_exists($gitignoreFile)) {
            $gitignore = file_get_contents($gitignoreFile));
            $list = explode("\n", $gitignore);
            foreach ($list as $item) {
                $item = trim($item, "\n\r\t ");
                $patterns[] = $item;
            }
        }
        $this->setPatterns($patterns);
        $files = array();
        foreach ($this->finder->in($this->directory) as $file) {
            $files[$file->getRelativePath()] = array(
                'hashes' => array(
                    $checksumAlgo => hash_file($checksumAlgo, $file->getRealPath())
                ),
                'length' => $file->getSize(),
            );
        }
        return $files;
    }

    private function setPatterns(array $patterns)
    {
        $finder = $this->finder->files()->ignoreVCS(true);
        foreach ($patterns as $pattern) {
            $finder->notName($pattern);
        }
    }

}