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
 * @author Andreas Schempp <andreas.schempp@terminal42.ch>
 */
class Zip
{
    /**
     * Gets content of the root composer.json inside a ZIP archive.
     *
     * @param string $pathToZip
     * @param string $filename
     *
     * @return string|null
     */
    public static function getComposerJson($pathToZip)
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('The Zip Util requires PHP\'s zip extension');
        }

        $zip = new \ZipArchive();
        if ($zip->open($pathToZip) !== true) {
            return null;
        }

        if (0 == $zip->numFiles) {
            $zip->close();

            return null;
        }

        $foundFileIndex = self::locateFile($zip, 'composer.json');
        if (false === $foundFileIndex) {
            $zip->close();

            return null;
        }

        $content = null;
        $configurationFileName = $zip->getNameIndex($foundFileIndex);
        $stream = $zip->getStream($configurationFileName);

        if (false !== $stream) {
            $content = stream_get_contents($stream);
        }

        $zip->close();

        return $content;
    }

    /**
     * Find a file by name, returning the one that has the shortest path.
     *
     * @param \ZipArchive $zip
     * @param string      $filename
     *
     * @return bool|int
     */
    private static function locateFile(\ZipArchive $zip, $filename)
    {
        $indexOfShortestMatch = false;
        $lengthOfShortestMatch = -1;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (strcmp(basename($stat['name']), $filename) === 0) {
                $directoryName = dirname($stat['name']);
                if ($directoryName === '.') {
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
                if ($indexOfShortestMatch === false || $length < $lengthOfShortestMatch) {
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
}
