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

namespace Composer\Command;

use Composer\Composer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class CleanCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('clean')
            ->setDescription('cleans the composer packages')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Forces removing composer files'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
            ))
            ->setHelp(<<<EOT
The <info>clean</info> command completly removes all composer
related things from your project

<info>php composer.phar clean</info>

EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        if ($input->getOption('force')) {
            $this->forceClean($composer, $output);
        } else {
            $this->softClean($input, $output);
        }
    }

    /**
     * uninstall all packages with composer
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function softClean(InputInterface $input, OutputInterface $output)
    {
        $installCommand = $this->getApplication()->find('install');

        $installCommand->uninstall($input, $output);
    }

    /**
     * forces the removing of all composer related files and packages
     *
     * @param Composer $composer
     * @param OutputInterface $output
     */
    protected function forceClean(Composer $composer, OutputInterface $output)
    {
        $vendorPath = $composer->getInstallationManager()->getVendorPath(true);
        $composerDir = $vendorPath.DIRECTORY_SEPARATOR.'.composer';

        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package){
            $path = $composer->getInstallationManager()->getInstallPath($package);
            $output->writeln('<comment>removing package</comment> '.str_replace(getcwd(),'.',$path));
            $this->cleanDirectory($path);
        }

        if (is_readable($composerDir)) {
           $this->cleanDirectory($composerDir);
        }

        $output->writeln('<comment>removing lock</comment>    ./composer.lock');
        @unlink(getcwd() . DIRECTORY_SEPARATOR . 'composer.lock');

        $output->writeln('<info>composer successfully removed</info>');
    }

    /**
     * recursivly clean vendor dirs
     *
     * @param string $dir
     */
    private function cleanDirectory($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . DIRECTORY_SEPARATOR . $object) == "dir") {
                        $this->cleanDirectory($dir . DIRECTORY_SEPARATOR . $object);
                    } else {
                        unlink($dir . DIRECTORY_SEPARATOR . $object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }
}