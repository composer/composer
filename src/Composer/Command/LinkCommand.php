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
use Composer\Util\ProcessExecutor;
use Composer\Repository\LocalLinksRepository;
use Composer\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Installer;
use Composer\Json\JsonFile;

/**
 * @author Jérémy Romey <jeremy@free-agent.fr>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Filip Procházka <filip.prochazka@kdyby.org>
 */
class LinkCommand extends RequireCommand
{
    protected function configure()
    {
        $this
            ->setName('link')
            ->setDescription('Installs package from a local filesystem using a symbolic link or marks current package as link')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::OPTIONAL, 'Local path to a required package or package name that is registered as a link.'),
                new InputOption('remove', 'r', InputOption::VALUE_NONE, "Instead of creating a link, it removes it.")
            ))
            ->setHelp(<<<EOT
The link command can either install a dependency by linking it, or mark current package as a link available for local install.
If you wanna link a package, it should be managed by vcs.

You mark a package as a link by entering to a directory, that contains your package and calling
    <comment>cd /some/local/path/redis-client</comment>
    <comment>%command.full_name%</comment>

When you have package marked as a link, you can install it wherever you want. Composer will then create a symlink for the dependency, instead of copying it
    <comment>%command.full_name% jack/redis-client</comment>

Package can be also installed by passing a local filesystem path. The package will be first marked as a link and then installed
    <comment>%command.full_name% /some/local/path/redis-client</comment>

By specifying the <comment>--remove</comment> option, you can remove the package from list of local links
    <comment>cd /some/local/path/redis-client</comment>
    <comment>%command.full_name% --remove</comment>

When you request installation of a package, that is not yet in <info>composer.json</info> it will be added as a dependency.
Also, composer will not change the version of the linked package. You have to do that manually using your vcs.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $package = $input->getArgument('package');
        if (preg_match('~^\w[\w.-]*/\w[\w.-]*$~i', $package)) {
            return $this->installFromLocalLink($input, $output, $package);

        } elseif ($package) {
            $packageDir = realpath($package);
            if (false === $packageDir || !is_dir($packageDir)) {
                $output->writeln('<error>Package directory ' . $input->getArgument('package') . ' not found.</error>');
                return 1;
            }

            $command = sprintf("%s link", escapeshellarg($_SERVER['PHP_SELF'])); // todo: will this work in phar?
            $out = NULL;
            $process = new ProcessExecutor();
            if (0 !== $process->execute($command, $out, $packageDir)) {
                $output->writeln('<error>Cannot create link from ' . $packageDir . '.</error>');
                $output->writeln('<error>' . $process->getErrorOutput() . '</error>');
                return 1;
            }
            $output->writeln('<info>' . trim($out) . '</info>');

            $jsonFile = new JsonFile($packageDir . '/composer.json');
            $packageConfig = $jsonFile->read();
            return $this->installFromLocalLink($input, $output, $packageConfig['name']);

        } else {
            return $this->createLocalLink($input, $output);
        }
    }

    private function installFromLocalLink(InputInterface $input, OutputInterface $output, $packageName)
    {
        // ensure the requirements
        $requires = array($packageName . ' dev-master');
        $requirements = $this->updateComposerConfig($input, $output, $requires);
//        $output->writeln('');
//        $output->writeln('Installing package <comment>' . $packageName . '</comment> using a local link.');

        // Update packages
        $composer = $this->getComposer();
        $io = $this->getIO();
        $install = Installer::create($io, $composer);

        $install
            ->setVerbose($input->getOption('verbose'))
            ->setDevMode(false)
            ->setUpdate(true)
            ->setUpdateWhitelist($requirements)
            ->setPreferLinks($requires)
        ;

        return $install->run() ? 0 : 1;
    }

    private function createLocalLink(InputInterface $input, OutputInterface $output)
    {
        // create local links repo
        $composer = $this->getComposer();
        $config = $composer->getConfig();
        $linksRepo = new LocalLinksRepository($config, $this->getIO());
        $rootPackage = clone $composer->getPackage();

        if ($input->getOption('remove')) {
            if (false == $linksRepo->hasPackage($rootPackage)) {
                $output->writeln('Package <comment>' . $rootPackage->getPrettyName() . '</comment> is not a local link</info>.');
                $output->writeln('');
                return 0;
            }

            $linksRepo->removePackage($rootPackage);
            $output->writeln('Package <comment>' . $rootPackage->getPrettyName() . '</comment> is no longer a local link.');
            $output->writeln('');
            return 0;
        }

        // register package
        $rootPackage->setSourceUrl(realpath(dirname($config->get('vendor-dir'))));

        if ($linksRepo->hasPackage($rootPackage)) {
            $output->writeln('Package <comment>' . $rootPackage->getPrettyName() . '</comment> is already a local link pointing at <info>' . $rootPackage->getSourceUrl() . '</info>.');
            $output->writeln('');
            return 0;
        }

        $output->writeln('Creating a local link from package <comment>' . $rootPackage->getPrettyName() . '</comment> at <info>' . $rootPackage->getSourceUrl() . '</info>.');
        $output->writeln('');

        try {
            $linksRepo->addPackage($rootPackage);

        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        return 0;
    }

}
