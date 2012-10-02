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
use Composer\Config;
use Composer\Factory;
use Composer\Json\JsonFile;

class ConfigCommand extends Command
{
    /**
     * @var array
     */
    protected $repositories = array();

    /**
     * @var Composer\Json\JsonFile
     */
    protected $configFile;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('config')
            ->setDescription('Set config options')
            ->setDefinition(array(
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Set this as a global config settings.'),
                new InputOption('editor', 'e', InputOption::VALUE_NONE, 'Open editor'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List configuration settings'),
                // @todo insert argument here
            ))
            ->setHelp(<<<EOT

EOT
            )
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Get the local composer.json or the global config.json
        $this->configFile = $input->getOption('global')
            ? (Factory::createConfig()->get('home') . '/config.json')
            : 'composer.json';

        $this->configFile = new JsonFile($this->configFile);
        if (!$this->configFile->exists()) {
            touch($this->configFile->getPath());
            // If you read an empty file, Composer throws an error
            // Toss some of the defaults in there
            $defaults = Config::$defaultConfig;
            $defaults['repositories'] = Config::$defaultRepositories;
            $this->configFile->write($defaults);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Open file in editor
        if ($input->getOption('editor')) {
            // @todo Find a way to use another editor
            $editor = 'vim';
            system($editor . ' ' . $this->configFile->getPath() . ' > `tty`');
            return 0;
        }

        // List the configuration of the file settings
        if ($input->getOption('list')) {
            $this->displayFileContents($this->configFile->read(), $output);
            return 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
    }

    /**
     * Display the contents of the file in a pretty formatted way
     *
     * @param array           $contents
     * @param OutputInterface $output
     * @param integer         $depth
     * @param string|null     $k
     */
    protected function displayFileContents(array $contents, OutputInterface $output, &$depth = 0, $k = null)
    {
        // @todo Look into a way to refactor this code, as it is right now, I
        //       don't like it
        foreach ($contents as $key => $value) {
            if (is_array($value)) {
                $depth++;
                $k .= $key . '.';
                $this->displayFileContents($value, $output, $depth, $k);
                if (substr_count($k,'.') > 1) {
                    $k = str_split($k,strrpos($k,'.',-2));
                    $k = $k[0] . '.';
                } else { $k = null; }
                $depth--;
                continue;
            }
            $output->writeln('[<comment>' . $k . $key . '</comment>] <info>' . $value . '</info>');
        }
    }
}


