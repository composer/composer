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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Package repository.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class PackageRepository extends ArrayRepository
{
    private $config;
    private $input;
    private $output;

    /**
     * Initializes filesystem repository.
     *
     * @param InputInterface  $input  The Input instance
     * @param OutputInterface $output The Output instance
     * @param array           $config package definition
     */
    public function __construct(InputInterface $input, OutputInterface $output, array $config)
    {
        $this->config = $config;
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize()
    {
        parent::initialize();

        if (!is_numeric(key($this->config))) {
            $this->config = array($this->config);
        }

        $loader = new ArrayLoader();
        foreach ($this->config as $package) {
            $package = $loader->load($package);
            $this->addPackage($package);
        }
    }
}
