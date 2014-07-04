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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;

/**
 * @author Stefano Varesi <stefano.varesi@gmail.com>
 */
class UnrequireCommand extends InitCommand
{
    protected function configure()
    {
        $this
            ->setName('unrequire')
            ->setDescription('Removes packages from your composer.json and uninstalls them')
            ->setDefinition([
                new InputArgument(
                    'packages',
                    InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                    'Unrequired packages list without a version constraint, e.g. foo/bar"'
                )
            ])
            ->setHelp(
<<<EOT
The unrequire command removes no more required packages from your composer.json and uninstalls them

If you do not want to uninstall the dependencies immediately you can call it with --no-update

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = Factory::getComposerFile();

        if (!file_exists($file) && !file_put_contents($file, "{\n}\n")) {
            $output->writeln('<error>'.$file.' could not be created.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $output->writeln('<error>'.$file.' is not readable.</error>');

            return 1;
        }
        if (!is_writable($file)) {
            $output->writeln('<error>'.$file.' is not writable.</error>');

            return 1;
        }

        $json = new JsonFile($file);
        $composer = $json->read();
        $composerBackup = file_get_contents($json->getPath());

        // get the list of requirements to remove, given as command line argument or in the wizard
        $requirements = $this->determineRequirements($input, $output, $input->getArgument('packages'));

        // remove the found packages from the configuration file
        $found = false;
        foreach ($requirements as $package) {

            if (!isset($composer['require'][$package]) and !isset($composer['require-dev'][$package])) {
                $output->writeln("<warning>${package} not found in composer.json, skipping...</warning>");
            } else {
                $found = true;
                unset($composer['require'][$package]);
                unset($composer['require-dev'][$package]);
            }
        }

        // if any package has been found
        if ($found) {

            // update the composer.json file
            $json->write($composer);
            $output->writeln('<info>'.$file.' has been updated</info>');

            $composer = $this->getComposer();
            $io = $this->getIO();
            $install = Installer::create($io, $composer);

            // emit the unrequire event
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'unrequire', $input, $output);
            $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

            // configure and run the command
            $install
                ->setVerbose($input->getOption('verbose'))
                ->setDevMode(true)
                ->setUpdate(true)
                ->setUpdateWhitelist($requirements)
                // do not leave orphaned dependencies in composer.lock
                ->setWhitelistDependencies(true)
            ;

            $status = $install->run();
            if ($status !== 0) {
                $output->writeln("\n<error>Installation failed, reverting '.$file.' to its original content.</error>");
                file_put_contents($json->getPath(), $composerBackup);
            }

        } else {
            $output->writeln('Nothing to do');
            $status = 0;
        }

        return $status;
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // do nothing
    }

    protected function determineRequirements(InputInterface $input, OutputInterface $output, $requires = array())
    {
        if (!$requires) {

            /* @var \Composer\Command\Helper\DialogHelper $dialog */
            $dialog = $this->getHelperSet()->get('dialog');
            $prompt = $dialog->getQuestion('Search for a package', false, ':');

            while (null !== $package = $dialog->ask($output, $prompt)) {
                $matches = $this->findPackages($package);

                if (count($matches)) {
                    $output->writeln(array(
                        '',
                        sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                        ''
                    ));

                    $exactMatch = null;
                    $choices = array();
                    foreach ($matches as $position => $package) {
                        $choices[] = sprintf(' <info>%5s</info> %s', "[$position]", $package['name']);
                        if ($package['name'] === $package) {
                            $exactMatch = true;
                            break;
                        }
                    }

                    // no match, prompt which to pick
                    if (!$exactMatch) {
                        $output->writeln($choices);
                        $output->writeln('');

                        $validator = function ($selection) use ($matches) {
                            if ('' === $selection) {
                                return false;
                            }

                            if (!is_numeric($selection)
                            && preg_match('{^\s*(\S+)\s+(\S.*)\s*$}', $selection, $matches)) {
                                return $matches[1].' '.$matches[2];
                            }

                            if (!isset($matches[(int) $selection])) {
                                throw new \Exception('Not a valid selection');
                            }

                            $package = $matches[(int) $selection];

                            return $package['name'];
                        };

                        $package = $dialog->askAndValidate(
                            $output,
                            $dialog->getQuestion(
                                'Enter package # to add, or the complete package name if it is not listed',
                                false,
                                ':'
                            ),
                            $validator,
                            3
                        );
                    }

                    if (false !== $package) {
                        $requires[] = $package;
                    }
                }
            }
        }

        return $requires;
    }
}
