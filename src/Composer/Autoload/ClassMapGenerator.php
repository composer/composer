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

/*
 * This file is copied from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 */

namespace Composer\Autoload;

use Composer\ClassMapGenerator\FileList;
use Composer\IO\IOInterface;

/**
 * ClassMapGenerator
 *
 * @author Gyula Sallai <salla016@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @deprecated Since Composer 2.4.0 use the composer/class-map-generator package instead
 */
class ClassMapGenerator
{
    /**
     * Generate a class map file
     *
     * @param \Traversable<string>|array<string> $dirs Directories or a single path to search in
     * @param string                             $file The name of the class map file
     * @return void
     */
    public static function dump(iterable $dirs, string $file): void
    {
        $maps = array();

        foreach ($dirs as $dir) {
            $maps = array_merge($maps, static::createMap($dir));
        }

        file_put_contents($file, sprintf('<?php return %s;', var_export($maps, true)));
    }

    /**
     * Iterate over all files in the given directory searching for classes
     *
     * @param \Traversable<\SplFileInfo>|string|array<\SplFileInfo> $path The path to search in or an iterator
     * @param non-empty-string|null                                 $excluded     Regex that matches file paths to be excluded from the classmap
     * @param ?IOInterface                                          $io           IO object
     * @param null|string                                           $namespace    Optional namespace prefix to filter by
     * @param null|'psr-0'|'psr-4'|'classmap'                       $autoloadType psr-0|psr-4 Optional autoload standard to use mapping rules
     * @param array<non-empty-string, true>                         $scannedFiles
     * @return array<class-string, non-empty-string> A class map array
     * @throws \RuntimeException When the path is neither an existing file nor directory
     */
    public static function createMap($path, string $excluded = null, IOInterface $io = null, ?string $namespace = null, ?string $autoloadType = null, array &$scannedFiles = array()): array
    {
        $generator = new \Composer\ClassMapGenerator\ClassMapGenerator(['php', 'inc', 'hh']);
        $fileList = new FileList();
        $fileList->files = $scannedFiles;
        $generator->avoidDuplicateScans($fileList);

        $generator->scanPaths($path, $excluded, $autoloadType ?? 'classmap', $namespace);

        $classMap = $generator->getClassMap();

        $scannedFiles = $fileList->files;

        if ($io !== null) {
            foreach ($classMap->getPsrViolations() as $msg) {
                $io->writeError("<warning>$msg</warning>");
            }

            foreach ($classMap->getAmbiguousClasses() as $class => $paths) {
                if (count($paths) > 1) {
                    $io->writeError(
                        '<warning>Warning: Ambiguous class resolution, "'.$class.'"'.
                        ' was found '. (count($paths) + 1) .'x: in "'.$classMap->getClassPath($class).'" and "'. implode('", "', $paths) .'", the first will be used.</warning>'
                    );
                } else {
                    $io->writeError(
                        '<warning>Warning: Ambiguous class resolution, "'.$class.'"'.
                        ' was found in both "'.$classMap->getClassPath($class).'" and "'. implode('", "', $paths) .'", the first will be used.</warning>'
                    );
                }
            }
        }

        return $classMap->getMap();
    }
}
