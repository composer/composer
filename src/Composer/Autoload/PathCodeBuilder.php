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

namespace Composer\Autoload;


use Composer\Config;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
class PathCodeBuilder implements PathCodeBuilderInterface
{
    /**
     * @param string $path
     * @param BuildDataInterface $buildData
     *
     * @return string
     */
    public function getPathCode($path, BuildDataInterface $buildData)
    {
        $filesystem = $buildData->getFilesystem();
        $basePath = $buildData->getBasePath();
        $vendorPath = $buildData->getVendorPath();

        if (!$filesystem->isAbsolutePath($path)) {
            $path = $basePath . '/' . $path;
        }
        $path = $filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path . '/', $vendorPath . '/') === 0) {
            $path = substr($path, strlen($vendorPath));
            $baseDir = '$vendorDir';

            if ($path !== false) {
                $baseDir .= " . ";
            }
        } else {
            $path = $filesystem->findShortestPath($basePath, $path, true);
            $path = $filesystem->normalizePath($path);
            if (!$filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (preg_match('/\.phar$/', $path)) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return $baseDir . (($path !== false) ? var_export($path, true) : "");
    }
}
