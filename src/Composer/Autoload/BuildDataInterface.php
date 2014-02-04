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


use Composer\Util\Filesystem;

/**
 * @author Andreas hennings <andreas@dqxtech.net>
 */
interface BuildDataInterface
{
    /**
     * @return string
     */
    public function getSuffix();

    /**
     * @return Filesystem
     */
    public function getFilesystem();

    /**
     * @return string
     */
    public function getBasePath();

    /**
     * @return string
     */
    public function getTargetDir();

    /**
     * @return string
     */
    public function getAppDirBaseCode();

    /**
     * @return string
     */
    public function getVendorPath();

    /**
     * @return string
     */
    public function getVendorPathCode();

    /**
     * @return string
     */
    public function getVendorPathCode52();

    /**
     * @return bool
     */
    public function useGlobalIncludePath();

    /**
     * @return bool
     */
    public function prependAutoloader();

    /**
     * @return string
     */
    public function getVendorPathToTargetDirCode();
}
