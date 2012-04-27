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

use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;

/**
 * Package repository.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageRepository extends ArrayRepository
{
    private $config;

    /**
     * Initializes filesystem repository.
     *
     * @param array $config package definition
     */
    public function __construct(array $config)
    {
        $this->config = $config['package'];

        // make sure we have an array of package definitions
        if (!is_numeric(key($this->config))) {
            $this->config = array($this->config);
        }
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize()
    {
        parent::initialize();

        $loader = new ArrayLoader();
        foreach ($this->config as $package) {
            $package = $loader->load($package);
            $this->addPackage($package);
        }
    }
}
