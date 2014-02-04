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
use Composer\Package\PackageConsumerInterface;
use Composer\Package\PackageInterface;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
class IncludePaths implements PluginInterface, PackageConsumerInterface
{
    /**
     * @var string[]|null
     */
    protected $includePaths;

    /**
     * @param PackageInterface $package
     * @param string $installPath
     * @param bool $isMainPackage
     * @internal param $order
     */
    public function addPackage(PackageInterface $package, $installPath, $isMainPackage)
    {
        $targetDir = $package->getTargetDir();

        if (null !== $targetDir && strlen($targetDir) > 0) {
            $installPath = substr($installPath, 0, -strlen('/' . $targetDir));
        }

        foreach ($package->getIncludePaths() as $includePath) {
            $includePath = trim($includePath, '/');
            $this->includePaths[] = empty($installPath)
              ? $includePath
              : $installPath . '/' . $includePath;
        }
    }

    /**
     * @param ClassLoader $classLoader
     * @param bool $prependAutoloader
     */
    public function initClassLoader(ClassLoader $classLoader, $prependAutoloader)
    {
        if (!isset($this->includePaths)) {
            return;
        }

        $includePaths = $this->includePaths;
        array_push($includePaths, get_include_path());
        set_include_path(join(PATH_SEPARATOR, $includePaths));
    }

    /**
     * @param BuildInterface $build
     * @param BuildDataInterface $buildData
     * @param PathCodeBuilderInterface $buildUtil
     */
    public function generate(BuildInterface $build, BuildDataInterface $buildData, PathCodeBuilderInterface $buildUtil)
    {
        if (!isset($this->includePaths)) {
            return;
        }

        $includePathsCode = '';
        foreach ($this->includePaths as $path) {
            $includePathsCode .= "    " . $buildUtil->getPathCode($path, $buildData) . ",\n";
        }
        $build->addArraySourceFile('include_paths.php', $includePathsCode);

        $build->addPhpSnippet(<<<'EOT'
        $includePaths = require __DIR__ . '/include_paths.php';
        array_push($includePaths, get_include_path());
        set_include_path(join(PATH_SEPARATOR, $includePaths));


EOT
        );
    }
}
