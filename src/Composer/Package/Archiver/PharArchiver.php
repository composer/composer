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

namespace Composer\Package\Archiver;

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class PharArchiver extends BaseArchiver
{
    static public $formats = array(
        'zip' => \Phar::ZIP,
        'tar' => \Phar::TAR,
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, $sourceRef = null)
    {
        // source reference is useless for this archiver
        $this->createPharArchive($sources, $target, static::$formats[$format]);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return in_array($format, array_keys(static::$formats));
    }
}
