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
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    private $localRepository;

    public function __construct(RepositoryInterface $localRepository)
    {
        $this->localRepository = $localRepository;
    }

    protected function initialize()
    {
        parent::initialize();

        $versionParser = new VersionParser();

        try {
            $version = $versionParser->normalize(PHP_VERSION);
        } catch (\UnexpectedValueException $e) {
            $version = $versionParser->normalize(preg_replace('#^(.+?)(-.+)?$#', '$1', PHP_VERSION));
        }

        $php = new MemoryPackage('php', $version);
        parent::addPackage($php);

        foreach (get_loaded_extensions() as $ext) {
            if (in_array($ext, array('standard', 'Core'))) {
                continue;
            }

            $reflExt = new \ReflectionExtension($ext);
            try {
                $version = $versionParser->normalize($reflExt->getVersion());
            } catch (\UnexpectedValueException $e) {
                $version = $versionParser->normalize('0');
            }

            $ext = new MemoryPackage('ext-'.strtolower($ext), $version);
            parent::addPackage($ext);
        }
    }

    public function getPackages()
    {
        return array_merge(parent::getPackages(), $this->localRepository->getPackages());
    }
}
