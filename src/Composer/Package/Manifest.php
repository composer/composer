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

use Composer\Package\Archiver\ArchivableFilesFinder;
use Composer\Util\Filesystem;

/**
 * Generates a manifest array of all packaged files and their checksum/length.
 * This can later be transferred to JSON format for cryptographic signing.
 *
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class Manifest
{

    private $directory = null;

    private $finder = null;

    private $fs = null;

    public function __construct($directory = null, $excludes = array(), ArchivableFilesFinder $finder = null)
    {
        if (null === $directory) {
            $directory = getcwd();
        }
        if (!file_exists($directory) || !is_readable($directory)) {
            throw new \InvalidArgumentException(
                'The directory does not exist or is not readable: ' . $directory
            );
        }
        $this->directory = rtrim($directory, '/\\');
        $this->fs = new FileSystem;
        if ($finder) {
            $this->finder = $finder;
        } else {
            // Reuse internal class but we ignore any composer.json excludes
            // TODO: Support signing for composer created package archives via $excludes
            $this->finder = new ArchivableFilesFinder($this->directory, $excludes);
        }
    }

    public function assemble($checksumAlgo = 'sha256')
    {
        $files = array();
        foreach ($this->finder as $file) {
            $relativePath = empty($file->getRelativePath()) ? '' : $file->getRelativePath() . DIRECTORY_SEPARATOR;
            $files[$relativePath . $file->getFileName()] = array(
                'hashes' => array(
                    $checksumAlgo => hash_file($checksumAlgo, $file->getRealPath())
                ),
                'length' => $file->getSize(),
            );
        }
        ksort($files, SORT_STRING);
        return $files;
    }

}