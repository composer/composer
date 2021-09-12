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

namespace Composer\Package\Archiver;

use ZipArchive;
use Composer\Util\Filesystem;

/**
 * @author Jan Prieser <jan@prieser.net>
 */
class ZipArchiver implements ArchiverInterface
{
    protected static $formats = array(
        'zip' => 1,
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, array $excludes = array(), $ignoreFilters = false)
    {
        $fs = new Filesystem();
        $sources = $fs->normalizePath($sources);

        $zip = new ZipArchive();
        $res = $zip->open($target, ZipArchive::CREATE);
        if ($res === true) {
            $files = new ArchivableFilesFinder($sources, $excludes, $ignoreFilters);
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $filepath = strtr($file->getPath()."/".$file->getFilename(), '\\', '/');
                $localname = $filepath;
                if (strpos($localname, $sources . '/') === 0) {
                    $localname = substr($localname, strlen($sources . '/'));
                }
                if ($file->isDir()) {
                    $zip->addEmptyDir($localname);
                } else {
                    $zip->addFile($filepath, $localname);
                }

                /**
                 * ZipArchive::setExternalAttributesName is available from >= PHP 5.6
                 */
                if (PHP_VERSION_ID >= 50600) {
                    $perms = fileperms($filepath);

                    /**
                     * Ensure to preserve the permission umasks for the filepath in the archive.
                     */
                    $zip->setExternalAttributesName($localname, ZipArchive::OPSYS_UNIX, $perms << 16);
                }
            }
            if ($zip->close()) {
                return $target;
            }
        }
        $message = sprintf(
            "Could not create archive '%s' from '%s': %s",
            $target,
            $sources,
            $zip->getStatusString()
        );
        throw new \RuntimeException($message);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return isset(static::$formats[$format]) && $this->compressionAvailable();
    }

    private function compressionAvailable()
    {
        return class_exists('ZipArchive');
    }
}
