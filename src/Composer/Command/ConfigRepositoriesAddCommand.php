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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;

class ConfigRepositoriesAddCommand extends Command
{
    /**
     * @var array
     */
    protected $repositories = array();

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        // @todo Make it so the user can pass into this command an array
        $this
            ->setName('config:repositories:add')
            ->setDescription('Add a repository')
            ->setDefinition(array(
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Set this as a global config settings.')
            ))
            ->setHelp(<<<EOT
By running this command you may add a repository of a given type to either your
local composer.json file or to the global composer config file.

EOT
            )
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get the local composer.json or the global config.json
        $configFile = $input->getOption('global')
            ? (Factory::createConfig()->get('home') . '/config.json')
            : 'composer.json';

        $configFile = new JsonFile($configFile);
        if (!$configFile->exists()) {
            touch($globalConfig->getPath());
            // If you read an empty file, Composer throws an error
            $globalConfig->write(array());
        }

        // Make sure we have something to add
        if (count($this->repositories)) {
            // @todo Check and make sure the type/url combo does not
            //       alredy exist.
            $config = $configFile->read();
            foreach ($this->repositories as $repo) {
                $config['repositories'][] = array(
                    'type' => $repo['type'],
                    'url'  => $repo['url'],
                );
            }

            if ($input->isInteractive()) {
                $output->writeln(array(
                    '',
                    JsonFile::encode($config),
                    '',
                ));
                $dialog = $this->getHelperSet()->get('dialog');
                if (!$dialog->askConfirmation($output, $dialog->getQuestion('Do you want to continuw and save the repositories', 'yes', '?'), true)) {
                    $output->writeln('<error>Command aborted by the user.</error>');
                    return 1;
                }
            }
            $configFile->write($config);
        } else {
            $output->writeln('<info>No repositories have been added.</info>');
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');

        $output->writeln(array(
            '',
            'With this command you can add as many repositories to either the',
            'local composer.json file or the global composer config file.',
            '',
            'Type can be any of the following: composer, vcs, pear, package',
            '',
            'For more information see docs: http://getcomposer.org/doc/05-repositories.md',
            '',
        ));

        do {
            $type = $dialog->ask($output, $dialog->getQuestion('Repository Type'));
            $repo = $dialog->ask($output, $dialog->getQuestion('Repository URL'));
            if (null === $type && null === $repo) {
                break;
            }
            $this->repositories[] = array(
                'type' => $type,
                'url'  => $repo,
            );
        } while(true);
    }
}

