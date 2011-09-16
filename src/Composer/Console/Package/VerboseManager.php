<?php

namespace Composer\Console\Package;

use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Manager;

class VerboseManager extends Manager
{
    private $output;

    public function __construct(Composer $composer, OutputInterface $output)
    {
        parent::__construct($output);

        $this->composer = $composer;
    }

    public function install(PackageInterface $package)
    {
        $this->output->writeln('> Installing '.$package->getName());

        parent::install($package);
    }

    public function update(PackageInterface $package)
    {
        $this->output->writeln('> Updating '.$package->getName());

        parent::update($package);
    }

    public function remove(PackageInterface $package)
    {
        $this->output->writeln('> Removing '.$package->getName());

        parent::remove($package);
    }
}
