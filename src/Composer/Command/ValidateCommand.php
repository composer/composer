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
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Util\RemoteFilesystem;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ValidateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('validate')
            ->setDescription('Validates a composer.json')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file', './composer.json')
            ))
            ->setHelp(<<<EOT
The validate command validates a given composer.json

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
        } catch (JsonValidationException $e) {
            if ($laxValid) {
                $output->writeln('<info>'.$file.' is valid for simple usage with composer but has</info>');
                $output->writeln('<info>strict errors that make it unable to be published as a package:</info>');
            } else {
                $output->writeln('<error>'.$file.' is invalid, the following errors were found:</error>');
            }
            foreach ($e->getErrors() as $message) {
                $output->writeln('<error>'.$message.'</error>');
            }
            return 1;
        } catch (\Exception $e) {
            $output->writeln('<error>'.$file.' contains a JSON Syntax Error:</error>');
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return 1;
        }

        $output->writeln('<info>'.$file.' is valid</info>');
    }
}
