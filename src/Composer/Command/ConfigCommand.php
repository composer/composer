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
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Set this as a global config settings.'),
                new InputOption('editor', 'e', InputOption::VALUE_NONE, 'Open editor'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List configuration settings'),
                new InputArgument('setting-key', null, 'Setting key'),
                new InputArgument('setting-value', InputArgument::IS_ARRAY, 'Setting value'),
            ))
            // @todo Document
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
            $setting = $this->parseSetting($input->getArgument('setting-key'), $input->getArgument('setting-value'));
            $configSettings = $this->configFile->read();
            $settings = array_merge($configSettings, $setting);

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
        //       don't like it, also the name of the function could be better
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
        for($i=0;$i<count($parts);$i++) {
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
        // @todo Figure out what should be excluded from the validation check
        // @todo validation should vary based on if it's global or local
        $schemaFile = __DIR__ . '/../../../res/composer-schema.json';
        $schemaData = json_decode(file_get_contents($schemaFile));
        //die(var_dump($schemaData));
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


