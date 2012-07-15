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

namespace Composer\Util\Archive;

use FilesystemIterator;
use RuntimeException;
use ZipArchive;

/**
 * Zip Archiver
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class ZipArchiver implements ArchiverInterface
{
    /**
     * Constructor
     *
     * @throws RuntimeException If zip extension is not enabled
     */
    public function __construct()
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('You need the zip extension enabled to use the ZipArchiver');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getArchiveType()
    {
        return 'zip';
    }

    /**
     * {@inheritDoc}
     */
    public function compressDir($dir, $targetFile)
    {
        if (file_exists($targetFile)) {
            throw new RuntimeException("Target file '$targetFile' already exist");
        }

        if (!is_dir($dir) || !is_readable($dir)) {
            throw new RuntimeException("Directory '$dir' is not exists or is not readable");
        }

        $zip = new ZipArchive();

        if (true !== ($retval = $zip->open($targetFile, ZipArchive::CREATE))) {
            throw new RuntimeException($this->getErrorMessage($retval, $targetFile));
        }

        try {
            $this->addDirectoryToArchive($zip, $dir);
        } catch (RuntimeException $e) {
            $zip->close();
            @unlink($targetFile);
            throw new RuntimeException("Can not create zip archive '$targetFile' from '$dir'", 0, $e);
        }
        $zip->close();
    }

    /**
     * {@inheritDoc}
     */
    public function extractTo($file, $targetDir)
    {
        $zip = new ZipArchive();

        if (true !== ($retval = $zip->open($file))) {
            throw new RuntimeException($this->getErrorMessage($retval, $file));
        }

        if (!$zip->extractTo($targetDir)) {
            throw new RuntimeException("Can not extract zip archive '$file' to '$targetDir'");
        }
        $zip->close();
    }

    /**
     * Add directory to archive recursively
     *
     * @param ZipArchive $zip    Archive file
     * @param string     $dir    Directory to add
     * @param string     $parent Parent directory
     *
     * @throws RuntimeException On any error
     */
    private function addDirectoryToArchive(ZipArchive $zip, $dir, $parent = '')
    {
        if ($parent && !$zip->addEmptyDir($parent)) {
            throw new RuntimeException("Can not add directory '{$dir}' to zip archive.");
        }

        /* @var \SplFileInfo $fileInfo */
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
            if ($fileInfo->isDir()) {
                $this->addDirectoryToArchive($zip, $fileInfo->getPathname(), $parent . $fileInfo->getFilename() . '/');
            } elseif (!$zip->addFile($fileInfo->getPathname(), $parent . $fileInfo->getFilename())) {
                throw new RuntimeException("Can not add file '{$fileInfo->getPathname()}' to zip archive.");
            }
        }
    }

    /**
     * Give a meaningful error message to the user.
     *
     * @param int    $retval ZipArchive error code
     * @param string $file   File name
     *
     * @return string Error message
     */
    private function getErrorMessage($retval, $file)
    {
        switch ($retval) {
            case ZipArchive::ER_EXISTS:
                return sprintf("File '%s' already exists.", $file);
            case ZipArchive::ER_INCONS:
                return sprintf("Zip archive '%s' is inconsistent.", $file);
            case ZipArchive::ER_INVAL:
                return sprintf("Invalid argument (%s)", $file);
            case ZipArchive::ER_MEMORY:
                return sprintf("Malloc failure (%s)", $file);
            case ZipArchive::ER_NOENT:
                return sprintf("No such zip file: '%s'", $file);
            case ZipArchive::ER_NOZIP:
                return sprintf("'%s' is not a zip archive.", $file);
            case ZipArchive::ER_OPEN:
                return sprintf("Can't open zip file: %s", $file);
            case ZipArchive::ER_READ:
                return sprintf("Zip read error (%s)", $file);
            case ZipArchive::ER_SEEK:
                return sprintf("Zip seek error (%s)", $file);
            default:
                return sprintf("'%s' is not a valid zip archive, got error code: %s", $file, $retval);
        }
    }
}
