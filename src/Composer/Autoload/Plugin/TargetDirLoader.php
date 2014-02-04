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
class TargetDirLoader implements PluginInterface, PackageConsumerInterface
{
    /**
     * @var string|null
     */
    private $mainPackageTargetDir;

    /**
     * @var array|null
     */
    private $mainPackagePsr0;

    /**
     * @param PackageInterface $package
     * @param string $installPath
     * @param bool $isMainPackage
     * @internal param $order
     */
    public function addPackage(PackageInterface $package, $installPath, $isMainPackage)
    {
        if (!$isMainPackage) {
            return;
        }

        $mainAutoload = $package->getAutoload();
        $targetDir = $package->getTargetDir();

        if (!$targetDir || empty($mainAutoload['psr-0'])) {
            return;
        }

        $this->mainPackageTargetDir = $targetDir;
        $this->mainPackagePsr0 = $mainAutoload['psr-0'];
    }

    /**
     * @param ClassLoader $classLoader
     * @param bool $prependAutoloader
     */
    public function initClassLoader(ClassLoader $classLoader, $prependAutoloader)
    {
        // Do nothing.
    }

    /**
     * @param BuildInterface $build
     * @param BuildDataInterface $buildData
     * @param PathCodeBuilderInterface $buildUtil
     */
    public function generate(BuildInterface $build, BuildDataInterface $buildData, PathCodeBuilderInterface $buildUtil)
    {
        if (!isset($this->mainPackageTargetDir) || !isset($this->mainPackagePsr0)) {
            return;
        }

        $prefixes = array();
        foreach (array_keys($this->mainPackagePsr0) as $prefix) {
            $prefixes[] = var_export($prefix, true);
        }
        $prefixes = implode(', ', $prefixes);

        $filesystem = $buildData->getFilesystem();
        $levels = count(explode('/', $filesystem->normalizePath($this->mainPackageTargetDir)));
        $baseDirFromTargetDirCode = $filesystem->findShortestPathCode($buildData->getTargetDir(), $buildData->getBasePath(), true);

        $build->addMethod(<<<EOF

    public static function autoload(\$class)
    {
        \$dir = $baseDirFromTargetDirCode . '/';
        \$prefixes = array($prefixes);
        foreach (\$prefixes as \$prefix) {
            if (0 !== strpos(\$class, \$prefix)) {
                continue;
            }
            \$path = \$dir . implode('/', array_slice(explode('\\\\', \$class), $levels)).'.php';
            if (!\$path = stream_resolve_include_path(\$path)) {
                return false;
            }
            require \$path;

            return true;
        }
    }

EOF
        );

        $suffix = $buildData->getSuffix();

        $build->addPhpSnippet(<<<EOF
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'), true, true);


EOF
        );
    }
}
