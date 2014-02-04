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

namespace Composer\Autoload\Plugin;


use Composer\Autoload\BuildDataInterface;
use Composer\Autoload\BuildInterface;
use Composer\Autoload\PathCodeBuilderInterface;
use Composer\Autoload\ClassLoader;
use Composer\Package\SortedPackageConsumerInterface;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
class Files extends AbstractAutoloadType implements SortedPackageConsumerInterface, PluginInterface
{
    /**
     * Overrides property from AbstractPackageConsumer.
     *
     * @var string
     */
    protected $type = 'files';

    /**
     * Overrides property from AbstractPackageConsumer.
     *
     * @var bool
     */
    protected $mustResolveTargetDir = true;

    /**
     * @param string $path
     * @param string $targetDir
     * @param bool $isMainPackage
     *
     * @return string
     */
    protected function pathResolveTargetDir($path, $targetDir, $isMainPackage)
    {
        if ($isMainPackage) {
            // remove target-dir from file paths of the root package
            $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $targetDir)));
            return ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
        }
        else {
            // add target-dir from file paths that don't have it
            return $targetDir . '/' . $path;
        }
    }

    /**
     * @param ClassLoader $classLoader
     * @param bool $prependAutoloader
     */
    public function initClassLoader(ClassLoader $classLoader, $prependAutoloader)
    {
        foreach ($this->map as $files) {
            foreach ($files as $file) {
                require $file;
            }
        }
    }

    /**
     * @param BuildInterface $build
     * @param BuildDataInterface $buildData
     * @param PathCodeBuilderInterface $buildUtil
     */
    public function generate(BuildInterface $build, BuildDataInterface $buildData, PathCodeBuilderInterface $buildUtil)
    {
        ksort($this->map);

        $filesCode = '';
        foreach ($this->map as $files) {
            foreach ($files as $file) {
                $filesCode .= '    ' . $buildUtil->getPathCode($file, $buildData) . ",\n";
            }
        }

        if (!$filesCode) {
            return;
        }

        $build->addArraySourceFile('autoload_files.php', $filesCode);

        $build->addPhpSnippet(<<<'EOT'
        $includeFiles = require __DIR__ . '/autoload_files.php';
        foreach ($includeFiles as $file) {
            require $file;
        }


EOT
        );
    }
}
