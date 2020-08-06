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

namespace Composer\Util;

/**
 * @author Wissem Riahi <wissemr@gmail.com>
 */
class Tar
{
    /**
     * @param string $pathToArchive
     *
     * @return string|null
     */
    public static function getComposerJson($pathToArchive)
    {
        $phar = new \PharData($pathToArchive);

        if (!$phar->valid()) {
            return null;
        }

        return self::extractComposerJsonFromFolder($phar, $phar, 2);
    }

    /**
     * @param \PharData PharData
     * @param \PharData $folder
     * @param int      $searchLevels
     * @param string      $path
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    private static function extractComposerJsonFromFolder(\PharData $phar, \PharData $folder, $searchLevels, $path = '')
    {
        $composerJson = null;
        $directories = array();
        foreach ($folder as $folderFile) {
            if ($folderFile->isFile() && $folderFile->getBasename() === 'composer.json') {
                if (count(explode('/', $path))> 2) {
                    throw new \RuntimeException('No composer.json found either at the top level or within the topmost directory');
                }

                return $folder->offsetGet($path . 'composer.json')->getContent();
            }

            if ($folderFile->isDir()) {
                $directories[] = $folderFile;
            }
        }

        if ($searchLevels === 0) {
            throw new \RuntimeException('No composer.json found either at the top level or within the topmost directory');
        }

        $composerJsons = array();
        foreach ($directories as $dir) {
            $pathName = $dir->getPathname();
            $composerJsons[] = self::extractComposerJsonFromFolder($phar, new \PharData($pathName), $searchLevels - 1, $path . $dir->getBasename() . '/');
        }

        if (count($composerJsons) > 1) {
            throw new \RuntimeException('Multiple composer json were found in the archive file. Make sure to have a single composer.json at the root directory.');
        }

        if (count($composerJsons) === 1) {
            return $composerJsons[0];
        }

        throw new \RuntimeException('No composer.json found either at the top level or within the topmost directory');
    }
}
