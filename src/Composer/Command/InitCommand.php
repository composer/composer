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
use Composer\Command\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 */
class InitCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Creates a basic composer.json file in current directory.')
            ->setDefinition(array(
                new InputOption('name', null, InputOption::VALUE_NONE, 'Name of the package'),
                new InputOption('description', null, InputOption::VALUE_NONE, 'Description of package'),
                // new InputOption('version', null, InputOption::VALUE_NONE, 'Version of package'),
                new InputOption('homepage', null, InputOption::VALUE_NONE, 'Homepage of package'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'An array required packages'),
            ))
            ->setHelp(<<<EOT
The <info>init</info> command creates a basic composer.json file
in the current directory.

<info>php composer.phar init</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();

        $options = array_filter(array_intersect_key($input->getOptions(), array_flip(array('name','description','require'))));

        $options['require'] = $this->formatRequirements(isset($options['require']) ? $options['require'] : array());

        $file = new JsonFile('composer.json');

        $json = $file->encode($options);

        if ($input->isInteractive()) {
            $output->writeln(array(
                '',
                $json,
                ''
            ));
            if (!$dialog->askConfirmation($output, $dialog->getQuestion('Do you confirm generation', 'yes', '?'), true)) {
                $output->writeln('<error>Command aborted</error>');

                return 1;
            }
        }

        $file->write($options);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();
        $dialog->writeSection($output, 'Welcome to the Composer config generator');

        // namespace
        $output->writeln(array(
            '',
            'This command will guide you through creating your composer.json config.',
            '',
        ));

        $cwd = realpath(".");

        $name = $input->getOption('name') ?: basename($cwd);
        $name = $dialog->ask(
            $output,
            $dialog->getQuestion('Package name', $name),
            $name
        );
        $input->setOption('name', $name);

        $description = $input->getOption('description') ?: false;
        $description = $dialog->ask(
            $output,
            $dialog->getQuestion('Description', $description)
        );
        $input->setOption('description', $description);

        $output->writeln(array(
            '',
            'Define your dependencies.',
            ''
        ));

        if ($dialog->askConfirmation($output, $dialog->getQuestion('Would you like to define your dependencies interactively', 'yes', '?'), true)) {
            $requirements = $this->determineRequirements($input, $output);

            $input->setOption('require', $requirements);
        }
    }

    protected function getDialogHelper()
    {
        $dialog = $this->getHelperSet()->get('dialog');
        if (!$dialog || get_class($dialog) !== 'Composer\Command\Helper\DialogHelper') {
            $this->getHelperSet()->set($dialog = new DialogHelper());
        }

        return $dialog;
    }

    protected function findPackages($name)
    {
        $composer = $this->getComposer();

        $packages = array();

        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();

        $token = strtolower($name);
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                if (false === ($pos = strpos($package->getName(), $token))) {
                    continue;
                }

                $packages[] = $package;
            }
        }

        return $packages;
    }

    protected function determineRequirements(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();
        $prompt = $dialog->getQuestion('Search for a package', false, ':');

        $requires = $input->getOption('require') ?: array();

        while (null !== $package = $dialog->ask($output, $prompt)) {
            $matches = $this->findPackages($package);

            if (count($matches)) {
                $output->writeln(array(
                    '',
                    sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                    ''
                ));

                foreach ($matches as $position => $package) {
                    $output->writeln(sprintf(' <info>%5s</info> %s <comment>%s</comment>', "[$position]", $package->getPrettyName(), $package->getPrettyVersion()));
                }

                $output->writeln('');

                $validator = function ($selection) use ($matches) {
                    if ('' === $selection) {
                        return false;
                    }

                    if (!isset($matches[(int) $selection])) {
                        throw new \Exception('Not a valid selection');
                    }

                    return $matches[(int) $selection];
                };

                $package = $dialog->askAndValidate($output, $dialog->getQuestion('Enter package # to add', false, ':'), $validator, 3);

                if (false !== $package) {
                    $requires[] = sprintf('%s %s', $package->getName(), $package->getPrettyVersion());
                }
            }
        }

        return $requires;
    }

    protected function formatRequirements(array $requirements)
    {
        $requires = array();
        foreach ($requirements as $requirement) {
            list($packageName, $packageVersion) = explode(" ", $requirement);

            $requires[$packageName] = $packageVersion;
        }

        return empty($requires) ? new \stdClass : $requires;
    }
}