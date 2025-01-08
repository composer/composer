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
 * @author Wissem Riahi <wissemr@gmail.com>
 */
class Tar
{
    public static function getComposerJson(string $pathToArchive): ?string
    {
        $phar = new \PharData($pathToArchive);

        if (!$phar->valid()) {
            return null;
        }

        return self::extractComposerJsonFromFolder($phar);
    }

    /**
     * @throws \RuntimeException
     */
    private static function extractComposerJsonFromFolder(\PharData $phar): string
    {
        if (isset($phar['composer.json'])) {
            return $phar['composer.json']->getContent();
        }

        $topLevelPaths = [];
        foreach ($phar as $folderFile) {
            $name = $folderFile->getBasename();

            if ($folderFile->isDir()) {
                $topLevelPaths[$name] = true;
                if (\count($topLevelPaths) > 1) {
                    throw new \RuntimeException('Archive has more than one top level directories, and no composer.json was found on the top level, so it\'s an invalid archive. Top level paths found were: '.implode(',', array_keys($topLevelPaths)));
                }
            }
        }

        $composerJsonPath = key($topLevelPaths).'/composer.json';
        if (\count($topLevelPaths) > 0 && isset($phar[$composerJsonPath])) {
            return $phar[$composerJsonPath]->getContent();
        }

        throw new \RuntimeException('No composer.json found either at the top level or within the topmost directory');
    }
}
