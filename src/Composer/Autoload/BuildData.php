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
use Composer\Util\Filesystem;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
class BuildData implements BuildDataInterface
{
    /**
     * @var string
     */
    private $vendorPath;

    /**
     * @var string
     */
    private $targetDir;

    /**
     * @var string
     */
    private $basePath;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $vendorPathCode;

    /**
     * @var string
     */
    private $vendorPathCode52;

    /**
     * @var string
     */
    private $vendorPathToTargetDirCode;

    /**
     * @var string
     */
    private $appBaseDirCode;

    /**
     * @var string
     */
    private $suffix;

    /**
     * @var bool
     */
    private $useGlobalIncludePath;

    /**
     * @var bool
     */
    private $prependAutoloader;

    /**
     * @param Config $config
     * @param string $targetDir
     * @param string $suffix
     */
    public function __construct(Config $config, $targetDir, $suffix)
    {
        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));

        $basePath = $filesystem->normalizePath(realpath(getcwd()));
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

        $targetDir = $vendorPath . '/' . $targetDir;
        $filesystem->ensureDirectoryExists($targetDir);

        $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
        $vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);
        $vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

        $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
        $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

        $useGlobalIncludePath = (bool) $config->get('use-include-path');
        $prependAutoloader = (false !== $config->get('prepend-autoloader'));

        $this->filesystem = $filesystem;
        $this->targetDir = $targetDir;
        $this->basePath = $basePath;
        $this->vendorPath = $vendorPath;
        $this->suffix = $suffix;
        $this->vendorPathCode = $vendorPathCode;
        $this->vendorPathCode52 = $vendorPathCode52;
        $this->vendorPathToTargetDirCode = $vendorPathToTargetDirCode;
        $this->appBaseDirCode = $appBaseDirCode;
        $this->useGlobalIncludePath = $useGlobalIncludePath;
        $this->prependAutoloader = $prependAutoloader;
    }

    /**
     * @return string
     */
    public function getSuffix()
    {
        return $this->suffix;
    }

    /**
     * @return Filesystem
     */
    public function getFilesystem()
    {
        return $this->filesystem;
    }

    /**
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * @return string
     */
    public function getTargetDir()
    {
        return $this->targetDir;
    }

    /**
     * @return string
     */
    public function getAppDirBaseCode()
    {
        return $this->appBaseDirCode;
    }

    /**
     * @return string
     */
    public function getVendorPath()
    {
        return $this->vendorPath;
    }

    /**
     * @return string
     */
    public function getVendorPathCode()
    {
        return $this->vendorPathCode;
    }

    /**
     * @return string
     */
    public function getVendorPathCode52()
    {
        return $this->vendorPathCode52;
    }

    /**
     * @return bool
     */
    public function useGlobalIncludePath()
    {
        return $this->useGlobalIncludePath;
    }

    /**
     * @return bool
     */
    public function prependAutoloader()
    {
        return $this->prependAutoloader;
    }

    /**
     * @return string
     */
    public function getVendorPathToTargetDirCode()
    {
        return $this->vendorPathToTargetDirCode;
    }
}
