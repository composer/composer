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
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Util\RemoteFilesystem;

/**
 * @author Jérémy Romey <jeremy@free-agent.fr>
 */
class RequireCommand extends InitCommand
{
    protected function configure()
    {
        $this
            ->setName('require')
            ->setDescription('Adds a required package to a composer.json')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file', './composer.json'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'An array of required packages'),
            ))
            ->setHelp(<<<EOT
The add package command adds a required package to a given composer.json

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln('<error>'.$file.' not found.</error>');
            return 1;
        }
        if (!is_readable($file)) {
            $output->writeln('<error>'.$file.' is not readable.</error>');
            return 1;
        }

        $laxValid = false;
        try {
            $json = new JsonFile($file, new RemoteFilesystem($this->getIO()));
            $json->read();

            $json->validateSchema(JsonFile::LAX_SCHEMA);
            $laxValid = true;
            $json->validateSchema();

        } catch (\Exception $e) {
            $output->writeln('<error>'.$file.' has an error. Run the validate command for more info</error>');
            return 1;
        }

        $output->writeln(array(
            '',
            'Updating your dependencies.',
            ''
        ));

        $dialog = $this->getHelperSet()->get('dialog');

        $options = json_decode($json->getResult(), true);

        $requirements = array();
        $requirements = $this->determineRequirements($input, $output);

        $baseRequirements = array_key_exists('require', $options) ? $options['require'] : array();
        $requirements     = $this->formatRequirements($requirements);

        foreach ($requirements as $package => $version) {
            if (array_key_exists($package, $baseRequirements)) {
                if ($dialog->askConfirmation($output, $dialog->getQuestion('The package '.$package.' is already in requirements. Would you like to update the version required from '.$baseRequirements[$package].' to '.$version, 'yes', '?'), true)) {
                    $baseRequirements[$package] = $version;
                }
            } else {
                $baseRequirements[$package] = $version;
            }
        }

        $options['require'] = $baseRequirements;

        $json->encode($options);
        $json->write($options);

        $output->writeln('<info>'.$file.' has been updated</info>');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        return;
    }
}
