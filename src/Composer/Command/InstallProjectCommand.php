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
                new InputOption('repository-url', null, InputOption::VALUE_REQUIRED, 'Pick a different repository url to look for the package.'),
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

To install a package from another repository repository than the default one you
can pass the <info>'--repository-url=http://myrepository.org'</info> flag.

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
            $input->getOption('repository-url')
        );
    }

    public function installProject(IOInterface $io, $packageName, $directory = null, $version = null, $preferSource = false, $repositoryUrl = null)
    {
        $dm = $this->createDownloadManager($io);
        if ($preferSource) {
            $dm->setPreferSource(true);
        }

        if ($repositoryUrl === null) {
            $sourceRepo = new ComposerRepository(array('url' => 'http://packagist.org'));
        } else if (substr($repositoryUrl, -5) === ".json") {
            $sourceRepo = new FilesystemRepository($repositoryUrl);
        } else if (strpos($repositoryUrl, 'http') === 0) {
            $sourceRepo = new ComposerRepository(array('url' => $repositoryUrl));
        } else {
            throw new \InvalidArgumentException("Invalid repository url given. Has to be a .json file or an http url.");
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

