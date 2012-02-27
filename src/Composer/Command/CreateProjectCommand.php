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
use Symfony\Component\Console\Input\ArrayInput;
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
class CreateProjectCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('create-project')
            ->setDescription('Create new project from a package into given directory.')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('repository-url', null, InputOption::VALUE_REQUIRED, 'Pick a different repository url to look for the package.'),
                new InputArgument('package', InputArgument::REQUIRED),
                new InputArgument('version', InputArgument::OPTIONAL),
                new InputArgument('directory', InputArgument::OPTIONAL),
            ))
            ->setHelp(<<<EOT
The <info>create-project</info> command creates a new project from a given
package into a new directory. You can use this command to bootstrap new
projects or setup a clean version-controlled installation
for developers of your project.

<info>php composer.phar create-project vendor/project intodirectory</info>

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
            $this->getInstallCommand($input, $output),
            $input->getArgument('package'),
            $input->getArgument('directory'),
            $input->getArgument('version'),
            (Boolean)$input->getOption('prefer-source'),
            $input->getOption('repository-url')
        );
    }

    protected function getInstallCommand($input, $output)
    {
        $app = $this->getApplication();
        return function() use ($app, $input, $output) {
            $newInput = new ArrayInput(array('command' => 'install'));
            $app->doRUn($newInput, $output);
        };
    }

    public function installProject(IOInterface $io, $installCommand, $packageName, $directory = null, $version = null, $preferSource = false, $repositoryUrl = null)
    {
        $dm = $this->createDownloadManager($io);
        if ($preferSource) {
            $dm->setPreferSource(true);
        }

        if (null === $repositoryUrl) {
            $sourceRepo = new ComposerRepository(array('url' => 'http://packagist.org'));
        } elseif (".json" === substr($repositoryUrl, -5)) {
            $sourceRepo = new FilesystemRepository($repositoryUrl);
        } elseif (0 === strpos($repositoryUrl, 'http')) {
            $sourceRepo = new ComposerRepository(array('url' => $repositoryUrl));
        } else {
            throw new \InvalidArgumentException("Invalid repository url given. Has to be a .json file or an http url.");
        }

        $package = $sourceRepo->findPackage($packageName, $version);
        if (!$package) {
            throw new \InvalidArgumentException("Could not find package $packageName with version $version.");
        }

        if (null === $directory) {
            $parts = explode("/", $packageName);
            $directory = getcwd() . DIRECTORY_SEPARATOR . array_pop($parts);
        }

        $io->write('<info>Installing ' . $package->getName() . ' as new project.</info>', true);
        $projectInstaller = new ProjectInstaller($directory, $dm);
        $projectInstaller->install($package);

        $io->write('<info>Created project into directory ' . $directory . '</info>', true);
        chdir($directory);
        $installCommand();
    }

    protected function createDownloadManager(IOInterface $io)
    {
        $factory = new Factory();
        return $factory->createDownloadManager($io);
    }
}

