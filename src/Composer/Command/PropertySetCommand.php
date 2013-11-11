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

use Composer\Command\Helper\FileHelper;
use Composer\Json\JsonManipulator;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;

/**
 * @author Frank Stelzer <dev@frankstelzer.de>
 */
class PropertySetCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('property-set')
            ->setDescription('Sets or overwrites a property')
            ->setDefinition(
                array(
                    new InputArgument('name', InputArgument::REQUIRED, 'The property name, e.g. name, description, minimum-stability'),
                    new InputArgument('value', InputArgument::REQUIRED, 'The property value, e.g. foo, "foo bar"'),
                )
            )
            ->setHelp(<<<EOT
The property-set command sets or overwrites a property with assigned value.

Note that your composer.json is immediately modified. On failure the original file content will be restored.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // do some file checks
        $file = Factory::getComposerFile();

        try {
            $this->getHelperSet()->get('filesystem')->ensureFileExists($file, "{\n}\n");
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        // fire event before real command logic is executed
        $composer = $this->getComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'property-set', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        // start modifying the file
        $json = new JsonFile($file);
        $composer = $json->read();
        $composerBackup = file_get_contents($json->getPath());

        // only simple properties could be set currently
        $whitelist = array('name', 'description', 'homepage', 'minimum-stability', 'license');

        // validate name argument, abort with error on failure
        $name = $input->getArgument('name');
        if (!in_array($name, $whitelist)) {
            $output->writeln(
                '<error>property "' . $name . '" is not supported (allowed: ' .
                implode(', ', array_keys($whitelist)) .
                ')</error>'
            );

            return 1;
        }

        // go really sure that we are overwriting strings only
        if (array_key_exists($name, $composer) && is_array($composer[$name])) {
            $output->writeln(
                '<error>array/object detected in property "' . $name . '", overwriting not allowed.</error>'
            );

            return 1;
        }

        $this->updateFile($json, $name, $input->getArgument('value'));

        // validate generated json, rollback on failure
        try {
            $jsonValidate = new JsonFile($file);
            $jsonValidate->read();
        } catch (\RuntimeException $e) {
            $output->writeln("\n" . '<error>Update failed, reverting ' . $file . ' to its original content.</error>');
            file_put_contents($json->getPath(), $composerBackup);

            return 1;
        }

        return 0;
    }

    private function updateFile(JsonFile $json, $key, $value)
    {
        $contents = file_get_contents($json->getPath());

        $manipulator = new JsonManipulator($contents);
        $manipulator->addMainKey($key, $value);

        file_put_contents($json->getPath(), $manipulator->getContents());

        return true;
    }
}
