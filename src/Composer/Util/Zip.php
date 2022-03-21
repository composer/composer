<?php declare(strict_types=1);

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
     *
     * @return string|null
     */
    public static function getComposerJson(string $pathToZip): ?string
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
     * @param  \ZipArchive       $zip
     * @param  string            $filename
     * @throws \RuntimeException
     *
     * @return int
     */
    private static function locateFile(\ZipArchive $zip, string $filename): int
    {
        // return root composer.json if it is there and is a file
        if (false !== ($index = $zip->locateName($filename)) && $zip->getFromIndex($index) !== false) {
            return $index;
        }

        $topLevelPaths = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $dirname = dirname($name);

            // ignore OSX specific resource fork folder
            if (strpos($name, '__MACOSX') !== false) {
                continue;
            }

            // handle archives with proper TOC
            if ($dirname === '.') {
                $topLevelPaths[$name] = true;
                if (\count($topLevelPaths) > 1) {
                    throw new \RuntimeException('Archive has more than one top level directories, and no composer.json was found on the top level, so it\'s an invalid archive. Top level paths found were: '.implode(',', array_keys($topLevelPaths)));
                }
                continue;
            }

            // handle archives which do not have a TOC record for the directory itself
            if (false === strpos($dirname, '\\') && false === strpos($dirname, '/')) {
                $topLevelPaths[$dirname.'/'] = true;
                if (\count($topLevelPaths) > 1) {
                    throw new \RuntimeException('Archive has more than one top level directories, and no composer.json was found on the top level, so it\'s an invalid archive. Top level paths found were: '.implode(',', array_keys($topLevelPaths)));
                }
            }
        }

        if ($topLevelPaths && false !== ($index = $zip->locateName(key($topLevelPaths).$filename)) && $zip->getFromIndex($index) !== false) {
            return $index;
        }

        throw new \RuntimeException('No composer.json found either at the top level or within the topmost directory');
    }
}
