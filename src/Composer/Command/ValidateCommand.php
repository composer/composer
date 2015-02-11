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

use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Util\ConfigValidator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ValidateCommand
 *
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ValidateCommand extends Command
{
    /**
     * configure
     */
    protected function configure()
    {
        $this
            ->setName('validate')
            ->setDescription('Validates a composer.json')
            ->setDefinition(array(
                new InputOption('no-check-all', null, InputOption::VALUE_NONE, 'Do not make a complete validation'),
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file', './composer.json')
            ))
            ->setHelp(<<<EOT
The validate command validates a given composer.json

EOT
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln('<error>' . $file . ' not found.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $output->writeln('<error>' . $file . ' is not readable.</error>');

            return 1;
        }

        $validator = new ConfigValidator($this->getIO());
        $checkAll = $input->getOption('no-check-all') ? 0 : ValidatingArrayLoader::CHECK_ALL;
        list($errors, $publishErrors, $warnings) = $validator->validate($file, $checkAll);

        // output errors/warnings
        if (!$errors && !$publishErrors && !$warnings) {
            $output->writeln('<info>' . $file . ' is valid</info>');
        } elseif (!$errors && !$publishErrors) {
            $output->writeln('<info>' . $file . ' is valid, but with a few warnings</info>');
            $output->writeln('<warning>See http://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
        } elseif (!$errors) {
            $output->writeln('<info>' . $file . ' is valid for simple usage with composer but has</info>');
            $output->writeln('<info>strict errors that make it unable to be published as a package:</info>');
            $output->writeln('<warning>See http://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
        } else {
            $output->writeln('<error>' . $file . ' is invalid, the following errors/warnings were found:</error>');
        }

        $messages = array(
            'error' => array_merge($errors, $publishErrors),
            'warning' => $warnings,
        );

        foreach ($messages as $style => $msgs) {
            foreach ($msgs as $msg) {
                $output->writeln('<' . $style . '>' . $msg . '</' . $style . '>');
            }
        }

        return $errors || $publishErrors ? 1 : 0;
    }
}
