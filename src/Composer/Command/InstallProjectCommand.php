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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\IO\IOInterface;
use Composer\Factory;
use Composer\Repository\ComposerRepository;
use Composer\Repository\FilesystemRepository;
use Composer\Installer\ProjectInstaller;

/**
 * Install a package as new project into new directory.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class InstallProjectCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('install-project')
            ->setDescription('Install a package as new project into given directory.')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('packagist-url', null, InputOption::VALUE_REQUIRED, 'Pick a different packagist url to look for the package.'),
                new InputArgument('package', InputArgument::REQUIRED),
                new InputArgument('version', InputArgument::OPTIONAL),
                new InputArgument('directory', InputArgument::OPTIONAL),
            ))
            ->setHelp(<<<EOT
The <info>install-project</info> command installs a given package into a new directory.
You can use this command to bootstrap new projects or setup a clean installation for
new developers of your project.

<info>php composer.phar install-project vendor/project intodirectory</info>

To setup a developer workable version you should create the project using the source
controlled code by appending the <info>'--prefer-source'</info> flag.

To install a package from another packagist repository than the default one you
can pass the <info>'--packagist-url=http://mypackagist.org'</info> flag.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getApplication()->getIO();

        return $this->installProject(
            $io,
            $input->getArgument('package'),
            $input->getArgument('directory'),
            $input->getArgument('version'),
            (Boolean)$input->getOption('prefer-source'),
            $input->getOption('packagist-url')
        );
    }

    public function installProject(IOInterface $io, $packageName, $directory = null, $version = null, $preferSource = false, $packagistUrl = null)
    {
        $dm = $this->createDownloadManager($io);
        if ($preferSource) {
            $dm->setPreferSource(true);
        }

        if ($packagistUrl === null) {
            $sourceRepo = new ComposerRepository(array('url' => 'http://packagist.org'));
        } else if (substr($packagistUrl, -5) === ".json") {
            $sourceRepo = new FilesystemRepository($packagistUrl);
        } else if (strpos($packagistUrl, 'http') === 0) {
            $sourceRepo = new ComposerRepository(array('url' => $packagistUrl));
        } else {
            throw new \InvalidArgumentException("Invalid Packagist Url given. Has to be a .json file or an http url.");
        }

        $package = $sourceRepo->findPackage($packageName, $version);
        if (!$package) {
            throw new \InvalidArgumentException("Could not find package $packageName with version $version.");
        }

        if ($directory === null) {
            $parts = explode("/", $packageName);
            $directory = getcwd() . DIRECTORY_SEPARATOR . array_pop($parts);
        }

        $projectInstaller = new ProjectInstaller($directory, $dm);
        $projectInstaller->install($package);

        $io->write('Created new project directory for ' . $package->getName(), true);
        $io->write('Next steps:', true);
        $io->write('1. cd ' . $directory, true);
        $io->write('2. composer install', true);
    }

    protected function createDownloadManager(IOInterface $io)
    {
        $factory = new Factory();
        return $factory->createDownloadManager($io);
    }
}

