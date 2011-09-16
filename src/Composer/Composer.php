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

namespace Composer;

use Composer\Installer\InstallerInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class Composer
{
    const VERSION = '1.0.0-DEV';

    private $repositories = array();
    private $installers = array();

    public function setInstaller($type, InstallerInterface $installer = null)
    {
        if (null === $installer) {
            unset($this->installers[$type]);

            return;
        }

        $this->installers[$type] = $installer;
    }

    public function getInstaller($type)
    {
        if (!isset($this->installers[$type])) {
            throw new \UnexpectedValueException('Unknown dependency type: '.$type);
        }

        return $this->installers[$type];
    }

    public function setRepository($name, RepositoryInterface $repository = null)
    {
        if (null === $repository) {
            unset($this->repositories[$name]);

            return;
        }

        $this->repositories[$name] = $repository;
    }

    public function getRepository($name)
    {
        if (!isset($this->repositories[$name])) {
            throw new \UnexpectedValueException('Unknown repository: '.$name);
        }

        return $this->repositories[$name];
    }

    public function getRepositories()
    {
        return $this->repositories;
    }
}
