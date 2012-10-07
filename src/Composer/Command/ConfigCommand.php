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
use JsonSchema\Validator;
use Composer\Config;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;

/**
 * @author Joshua Estes <Joshua.Estes@iostudio.com>
 */
class ConfigCommand extends Command
{
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
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Apply command to the global config file'),
                new InputOption('editor', 'e', InputOption::VALUE_NONE, 'Open editor'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List configuration settings'),
                new InputOption('file', 'f', InputOption::VALUE_REQUIRED, 'If you want to choose a different composer.json or config.json', 'composer.json'),
                new InputArgument('setting-key', null, 'Setting key'),
                new InputArgument('setting-value', InputArgument::IS_ARRAY, 'Setting value'),
            ))
            ->setHelp(<<<EOT
This command allows you to edit some basic composer settings in either the
local composer.json file or the global config.json file.

To edit the global config.json file:

    <comment>php composer.phar --global</comment>

To add a repository:

    <comment>php composer.phar repositories.foo vcs http://bar.com</comment>

You can add a repository to the global config.json file by passing in the
<info>--global</info> option.

To edit the file in an external editor:

    <comment>php composer.phar --edit</comment>

To choose your editor you can set the "EDITOR" env variable.

To get a list of configuration values in the file:

    <comment>php composer.phar --list</comment>

You can always pass more than one option. As an example, if you want to edit the
global config.json file.

    <comment>php composer.phar --edit --global</comment>
EOT
            )
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('global') && 'composer.json' !== $input->getOption('file')) {
            throw new \RuntimeException('--file and --global can not be combined');
        }

        // Get the local composer.json, global config.json, or if the user
        // passed in a file to use
        $this->configFile = $input->getOption('global')
            ? (Factory::createConfig()->get('home') . '/config.json')
            : $input->getOption('file');

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
            $editor = system("bash -cl 'echo \$EDITOR'");
            system($editor . ' ' . $this->configFile->getPath() . ' > `tty`');
            return 0;
        }

        // List the configuration of the file settings
        if ($input->getOption('list')) {
            $this->displayFileContents($this->configFile->read(), $output);

            return 0;
        }

        // If the user enters in a config variable, parse it and save to file
        if ($input->getArgument('setting-key')) {
            if (null === $input->getArgument('setting-value')) {
                throw new \RuntimeException('You must include a setting value.');
            }

            /**
             * The user needs the ability to add a repository with one command.
             * For example "config -g repository.foo 'vcs http://example.com'
             */
            $configSettings = $this->configFile->read(); // what is current in the config
            $settings       = array(); // This will what will be merged into the above
            $values         = $input->getArgument('setting-value'); // what the user is trying to add/change

            // Checking for each known config value is going to make this method very large
            // what is a better way to do this?

            // repositories.foo
            if (preg_match('/^repositories\.(.+)/', $input->getArgument('setting-key'), $matches)) {
                if (2 !== count($values)) {
                    throw new \RuntimeException('You must pass the type and a url. Example: php composer.phar config repositories.foo vcs http://bar.com');
                }
                $setting = $this->parseSetting($input->getArgument('setting-key'), array(
                    'type' => $values[0],
                    'url'  => $values[1],
                ));

                // Could there be a better way to do this?
                $settings = array_merge_recursive($configSettings, $setting);
                $this->validateSchema($settings);
            }
            // process-timeout
            elseif (preg_match('/^process-timeout/', $input->getArgument('setting-key'))) {
                if (1 !== count($values)) {
                    throw new \RuntimeException('You can only pass one value. Example: php composer.phar config process-timeout 300');
                }

                if (!is_numeric($values[0])) {
                    throw new \RuntimeException(sprintf('"%s" is not a number.', $values[0]));
                }

                $setting  = $this->parseSetting('config.'.$input->getArgument('setting-key'), (integer) $values[0]);
                $settings = array_merge($configSettings, $setting);
                $this->validateSchema($settings);
            }

            // Make sure we have something to write to disk
            if (!count($settings)) {
                $output->writeln('Trying to update a setting that is supported with this command.');
                return 0;
            }

            // Make confirmation
            if ($input->isInteractive()) {
                $dialog = $this->getHelperSet()->get('dialog');
                $output->writeln(JsonFile::encode($settings));
                if (!$dialog->askConfirmation($output, $dialog->getQuestion('Do you confirm?', 'yes', '?'), true)) {
                    $output->writeln('<error>Command Aborted by User</error>');
                    return 1;
                }
            }

            $this->configFile->write($settings);
        }
    }

    /**
     * Display the contents of the file in a pretty formatted way
     *
     * @param array           $contents
     * @param OutputInterface $output
     * @param string|null     $k
     */
    protected function displayFileContents(array $contents, OutputInterface $output, $k = null)
    {
        // @todo Look into a way to refactor this code, as it is right now, I
        //       don't like it, also the name of the function could be better
        foreach ($contents as $key => $value) {
            if (is_array($value)) {
                $k .= $key . '.';
                $this->displayFileContents($value, $output, $k);

                if (substr_count($k,'.') > 1) {
                    $k = str_split($k,strrpos($k,'.',-2));
                    $k = $k[0] . '.';
                } else {
                    $k = null;
                }

                continue;
            }

            $output->writeln('[<comment>' . $k . $key . '</comment>] <info>' . $value . '</info>');
        }
    }

    /**
     * This function will take a setting key (a.b.c) and return an
     * array that matches this
     *
     * @param string $key
     * @param string $value
     * @return array
     */
    protected function parseSetting($key, $value)
    {
        $parts = array_reverse(explode('.', $key));
        $tmp = array();
        for ($i = 0; $i < count($parts); $i++) {
            $tmp[$parts[$i]] = (0 === $i) ? $value : $tmp;
            if (0 < $i) {
                unset($tmp[$parts[$i - 1]]);
            }
        }

        return $tmp;
    }

    /**
     * After the command sets a new config value, this will parse it writes
     * it to disk to make sure that it is valid according the the composer.json
     * schema.
     *
     * @param array $data
     * @throws JsonValidationException
     * @return boolean
     */
    protected function validateSchema(array $data)
    {
        // TODO Figure out what should be excluded from the validation check
        // TODO validation should vary based on if it's global or local
        $schemaFile = __DIR__ . '/../../../res/composer-schema.json';
        $schemaData = json_decode(file_get_contents($schemaFile));

        unset(
            $schemaData->properties->name,
            $schemaData->properties->description
        );

        $validator = new Validator();
        $validator->check(json_decode(json_encode($data)), $schemaData);

        if (!$validator->isValid()) {
            $errors = array();
            foreach ((array) $validator->getErrors() as $error) {
                $errors[] = ($error['property'] ? $error['property'].' : ' : '').$error['message'];
            }
            throw new JsonValidationException('"'.$this->configFile->getPath().'" does not match the expected JSON schema'."\n". implode("\n",$errors));
        }

        return true;
    }
}
