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

namespace Composer\Downloader\Util\Archive;

use ZipArchive;

/**
 * Zip Archiver
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class ZipArchiver implements ArchiverInterface
{
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
            throw new \RuntimeException("Target file '$targetFile' already exist");
        }

        if (!is_dir($dir) || !is_readable($dir)) {
            throw new \RuntimeException("Directory '$dir' is not exists or is not readable");
        }

        $zip = new ZipArchive();
        $status = $zip->open($targetFile, ZipArchive::CREATE);
        if ($status !== true) {
            throw new \RuntimeException("Can not create zip archive '$targetFile'");
        }

        try {
            $this->addDirectoryToArchive($zip, $dir);
        } catch (\RuntimeException $e) {
            $zip->close();
            @unlink($targetFile);
            throw new \RuntimeException("Can not create zip archive '$targetFile' from '$dir'", 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function extractTo($file, $targetDir)
    {
        $zip = new ZipArchive();

        if (true !== ($retval = $zip->open($file))) {
            throw new UnsupportedArchiveException(
                $file,
                $this->getArchiveType(),
                new \UnexpectedValueException($file.' is not a valid zip archive, got error code '.$retval)
            );
        }

        if (!$zip->extractTo($targetDir)) {
            throw new \RuntimeException("Can not extract zip archive '$file' to '$targetDir'");
        }
        $zip->close();
    }

    private function addDirectoryToArchive(ZipArchive $zip, $dir, $parent = '')
    {
        if ($parent) {
            if (!$zip->addEmptyDir($parent)) {
                throw new \RuntimeException("Can not add directory '{$dir}' to zip archive");
            }
        }
        /* @var \DirectoryIterator $fileInfo */
        foreach (new \DirectoryIterator($dir) as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            if ($fileInfo->isDir()) {
                $this->addDirectoryToArchive($zip, $fileInfo->getPathname(), $parent . $fileInfo->getFilename() . '/');
            } else {
                if (!$zip->addFile($fileInfo->getPathname(), $parent . $fileInfo->getFilename())) {
                    throw new \RuntimeException("Can not add file '{$fileInfo->getPathname()}' to zip archive");
                }
            }
        }
    }
}
