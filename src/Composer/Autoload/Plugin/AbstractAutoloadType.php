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


use Composer\Package\PackageConsumerInterface;
use Composer\Package\PackageInterface;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
abstract class AbstractAutoloadType implements PackageConsumerInterface
{
    /**
     * One of 'psr-0', 'psr-4', 'classmap', 'files'.
     *
     * @var string
     */
    protected $type;

    /**
     * @var bool
     */
    protected $mustResolveTargetDir = false;

    /**
     * @var array
     */
    protected $map = array();

    /**
     * @param PackageInterface $package
     * @param string $installPath
     * @param bool $isMainPackage
     */
    public function addPackage(PackageInterface $package, $installPath, $isMainPackage)
    {
        $autoload = $package->getAutoload();

        if (!isset($autoload[$this->type]) || !is_array($autoload[$this->type])) {
            // Skip this package.
            return;
        }

        $targetDir = $package->getTargetDir();
        if (null !== $targetDir && !$isMainPackage) {
            $installPath = substr($installPath, 0, -strlen('/' . $targetDir));
        }

        foreach ($autoload[$this->type] as $namespace => $paths) {
            foreach ((array) $paths as $path) {
                if ($this->mustResolveTargetDir && $targetDir && !is_readable($installPath . '/' . $path)) {
                    $path = $this->pathResolveTargetDir($path, $targetDir, $isMainPackage);
                }
                if (!empty($installPath)) {
                    $path = $installPath . '/' . $path;
                } elseif (empty($path)) {
                    $path = '.';
                }
                $this->map[$namespace][] = $path;
            }
        }
    }

    /**
     * @param string $path
     * @param string $targetDir
     * @param bool $isMainPackage
     *
     * @throws \Exception
     * @return string
     */
    protected function pathResolveTargetDir($path, $targetDir, $isMainPackage)
    {
        throw new \Exception('Not implemented.');
    }
}
