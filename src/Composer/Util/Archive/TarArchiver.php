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

use BadMethodCallException;
use PharData;
use PharException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tar Archiver
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class TarArchiver implements ArchiverInterface
{
    /**
     * {@inheritDoc}
     */
    public function getArchiveType()
    {
        return 'tar';
    }

    /**
     * {@inheritDoc}
     */
    public function compressDir($dir, $targetFile)
    {
        if (file_exists($targetFile)) {
            throw new RuntimeException("Target file '$targetFile' already exist");
        }

        try {
            $archive = new PharData($targetFile);
            $archive->buildFromDirectory($dir);
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException(sprintf("Tar file '%s' can not be prepared", $targetFile), 0, $e);
        } catch (BadMethodCallException $e) {
            throw new RuntimeException(sprintf("Can not read '%s' dir", $dir), 0, $e);
        } catch (PharException $e) {
            throw new RuntimeException(sprintf("Can not save tar file '%s'", $targetFile), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function extractTo($file, $targetDir)
    {
        try {
            $archive = new PharData($file);
            $archive->extractTo($targetDir, null, true);
        } catch (UnexpectedValueException $e) { // Can be thrown on construct
            throw new RuntimeException(sprintf("Tar file '%s' can not be opened", $file), 0, $e);
        } catch (PharException $e) { // Can be thrown on extract
            throw new RuntimeException(sprintf("Can not write extracted files to '%s' dir", $targetDir), 0, $e);
        }
    }
}
