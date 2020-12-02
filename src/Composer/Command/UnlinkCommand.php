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

use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 */
class UnlinkCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('unlink')
            ->setDescription('Remove current package from a "links repository".')
            ->setDefinition(array(
                new InputArgument('path', InputArgument::OPTIONAL, 'Links repository path to remove link from. If not provided defaults to Composer home.'),
            ))
            ->setHelp(
                <<<EOT
The link command remove a package from a "links repository", a
JSON file containing a map of package names to local paths.
Reverts the link command.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();

        $composer = $this->getComposer(false);
        if (!$composer) {
            $io->writeError('<error>Link command can only be executed from a package root folder.</error>');

            return 1;
        }

        $filesystem = new Filesystem();

        $customPath = $input->getArgument('path');
        $realPath = null;
        if ($customPath) {
            $realPath = realpath($filesystem->normalizePath(Platform::expandPath($customPath)));
            if (!$realPath || !is_dir($realPath) || !is_writable($realPath)) {
                $io->writeError('<error>"'.$customPath.'" is not a valid writable directory.</error>');

                return 1;
            }
        }

        $package = $composer->getPackage();
        $name = $package->getName();
        $path = $customPath ? $realPath : $composer->getConfig()->get('home');
        $filepath = $filesystem->normalizePath($path).'/composer-links.json';

        $jsonFile = new JsonFile($filepath, null, $io);
        if (!$jsonFile->exists()) {
            $io->write('<warning>There is no links repository at '.$path.'.</warning>');

            return 1;
        }

        $data = $jsonFile->read();
        $packages = isset($data['packages']) ? $data['packages'] : array();
        if (!is_array($packages)) {
            $data = array('packages' => array());
        }
        $found = false;

        $newPackages = array();
        foreach ($packages as $package) {
            if ($package['name'] !== $name) {
                $newPackages[] = $package;
                continue;
            }

            $found = true;
        }

        if (!$found) {
            $io->write('<warning>Package "'.$name.'" not found in links repository at '.$path.'.</warning>');

            return 2;
        }

        $data['packages'] = $newPackages;
        $jsonFile->write($data);

        $message = sprintf('Link for package "%s" removed successfully from links repository at '.$path.'.', $name);
        $io->write('<info>'.$message.'</info>');

        return 0;
    }
}
