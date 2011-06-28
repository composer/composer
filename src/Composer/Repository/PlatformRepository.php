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

namespace Composer\Repository;

use Composer\Package\MemoryPackage;
use Composer\Package\BasePackage;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    protected $packages;

    protected function initialize()
    {
        parent::initialize();

        try {
            $version = BasePackage::parseVersion(PHP_VERSION);
        } catch (\UnexpectedValueException $e) {
            $version = BasePackage::parseVersion(preg_replace('#^(.+?)(-.+)?$#', '$1', PHP_VERSION));
        }

        $php = new MemoryPackage('php', $version['version'], $version['type']);
        $this->addPackage($php);

        foreach (get_loaded_extensions() as $ext) {
            if (in_array($ext, array('standard', 'Core'))) {
                continue;
            }

            $reflExt = new \ReflectionExtension($ext);
            try {
                $version = BasePackage::parseVersion($reflExt->getVersion());
            } catch (\UnexpectedValueException $e) {
                $version = array('version' => '0', 'type' => 'stable');
            }

            $ext = new MemoryPackage('ext/'.strtolower($ext), $version['version'], $version['type']);
            $this->addPackage($ext);
        }
    }
}