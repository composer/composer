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
class LinkCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('link')
            ->setDescription('Add current package to a "links repository".')
            ->setDefinition(array(
                new InputArgument('path', InputArgument::OPTIONAL, 'Target path for the links repository. If not provided defaults to Composer home.'),
            ))
            ->setHelp(
                <<<EOT
The link command "exports" a package to a "links repository", a
JSON file containing a map of package names to to local paths.
When running update command with `--load-links` or `--links-from`
Composer will symlink packages in links repository instead of
pulling them from their remote repositories.
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
        if ($customPath) {
            $realPath = realpath($filesystem->normalizePath(Platform::expandPath($customPath)));
            if (!$realPath || !is_dir($realPath) || !is_writable($realPath)) {
                $io->writeError('<error>"'.$customPath.'" is not a valid writable directory.</error>');

                return 2;
            }
        }

        $package = $composer->getPackage();
        $name = $package->getName();
        $configPath = $composer->getConfig()->getConfigSource()->getName();
        $source = $filesystem->normalizePath(dirname($configPath));

        $path = $customPath ? $realPath : $composer->getConfig()->get('home');
        $filepath = $filesystem->normalizePath($path).'/composer-links.json';

        $jsonFile = new JsonFile($filepath, null, $io);

        $data = $jsonFile->exists() ? $jsonFile->read() : array();
        $packages = isset($data['packages']) ? $data['packages'] : array();
        if (!is_array($packages)) {
            $data = array('packages' => array());
        }
        $newPackages = array();
        $updated = false;

        foreach ($packages as $package) {
            if ($package['name'] !== $name) {
                $newPackages[] = $package;
                continue;
            }

            $newPackages[] = array('name' => $name, 'path' => $source);
            $updated = true;
        }

        if (!$updated) {
            $newPackages[] = array('name' => $name, 'path' => $source);
        }

        $data['packages'] = $newPackages;
        $jsonFile->write($data);

        $message = sprintf('Link for package "%s" %s successfully %s links repository at '.$path.'.', $name, $updated ? 'updated' : 'added', $updated ? 'in' : 'to');
        $io->write('<info>'.$message.'</info>');

        return 0;
    }
}
