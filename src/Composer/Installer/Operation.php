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

namespace Composer\Installer;

use Composer\Installer\InstallerInterface;
use Composer\Package\PackageInterface;

/**
 * Installer operation command
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class Operation
{
    private $installer;
    private $type;
    private $package;

    public function __construct(InstallerInterface $installer, $type, PackageInterface $package)
    {
        $type = strtolower($type);
        if (!in_array($type, array('install', 'update', 'remove'))) {
            throw new \UnexpectedValueException('Unhandled operation type: ' . $type);
        }

        $this->installer = $installer;
        $this->type      = $type;
        $this->package   = $package;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function execute()
    {
        $method = $this->getType();

        return $this->installer->$method($this->getPackage());
    }
}
