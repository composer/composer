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
use Composer\Json\JsonManipulator;

class ConfigRepositoriesAddCommand extends Command
{
    protected $repositories = array();

    protected function configure()
    {
        $this
            ->setName('config:repositories:add')
            ->setDescription('Add a repository')
            ->setDefinition(array(
                new InputOption('global', null, InputOption::VALUE_NONE, 'Set this as a global config settings.')
            ))
            ->setHelp(<<<EOT

EOT
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $config = Factory::createConfig();

        $globalConfig = new JsonFile(Factory::createConfig()->get('home') . '/config.json');
        if (!$globalConfig->exists()) {
            touch($globalConfig->getPath());
            $globalConfig->write(array());
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getOption('global')
            ? (Factory::createConfig()->get('home') . '/config.json')
            : 'composer.json';

        $configFile = new JsonFile($configFile);
        
        if (count($this->repositories)) {
            $config = $configFile->read();
            foreach ($this->repositories as $repo) {
                $config['repositories'][] = array(
                    'type' => $repo['type'],
                    'url'  => $repo['url'],
                );
            }
            $configFile->write($config);
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');

        /**
         * @todo Update this with more info
         */
        $output->writeln(array(
            '',
            'Add a repository',
            '',
        ));

        /**
         * @todo put this into a loop so user can add many repositories at
         *       the same time.
         */
        $type = $dialog->ask($output, $dialog->getQuestion('Repository Type'));
        $repo = $dialog->ask($output, $dialog->getQuestion('Repository URL'));
        if (null !== $type && null !== $repo) {
            $this->repositories[] = array(
                'type' => $type,
                'url' => $repo,
            );
        }
    }
}

