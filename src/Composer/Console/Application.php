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

namespace Composer\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Composer\Command\InstallCommand;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Package\PackageLock;

/**
 * The console application that handles the commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 */
class Application extends BaseApplication
{
    private $composer;
    private $package;
    private $lock;

    public function __construct(Composer $composer, PackageInterface $package, PackageLock $lock)
    {
        parent::__construct('Composer', Composer::VERSION);

        $this->composer = $composer;
        $this->package  = $package;
        $this->lock     = $lock;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->registerCommands();

        return parent::doRun($input, $output);
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * @return PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @return PackageLock
     */
    public function getLock()
    {
        return $this->lock;
    }

    /**
     * Looks for all *Command files in Composer's Command directory
     */
    protected function registerCommands()
    {
        $this->add(new InstallCommand());
    }
}
