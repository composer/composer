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
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{

    protected function initialize()
    {
        parent::initialize();

        $versionParser = new VersionParser();

        try {
            $prettyVersion = PHP_VERSION;
            $version = $versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            $prettyVersion = preg_replace('#^(.+?)(-.+)?$#', '$1', PHP_VERSION);
            $version = $versionParser->normalize($prettyVersion);
        }

        $php = new MemoryPackage('php', $version, $prettyVersion);
        $php->setDescription('The PHP interpreter');
        parent::addPackage($php);

        foreach (get_loaded_extensions() as $name) {
            if (in_array($name, array('standard', 'Core'))) {
                continue;
            }

            $reflExt = new \ReflectionExtension($name);
            try {
                $prettyVersion = $reflExt->getVersion();
                $version = $versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                $prettyVersion = '0';
                $version = $versionParser->normalize($prettyVersion);
            }

            $ext = new MemoryPackage('ext-'.$name, $version, $prettyVersion);
            $ext->setDescription('The '.$name.' PHP extension');
            parent::addPackage($ext);
        }
    }
}
