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

use Composer\Factory;
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
            ->setDescription('Validates a composer.json and composer.lock')
            ->setDefinition(array(
                new InputOption('no-check-all', null, InputOption::VALUE_NONE, 'Do not make a complete validation'),
                new InputOption('no-check-lock', null, InputOption::VALUE_NONE, 'Do not check if lock file is up to date'),
                new InputOption('no-check-publish', null, InputOption::VALUE_NONE, 'Do not check for publish errors'),
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file', './composer.json')
            ))
            ->setHelp(<<<EOT
The validate command validates a given composer.json and composer.lock

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
            $this->getIO()->writeError('<error>' . $file . ' not found.</error>');

            return 1;
        }
        if (!is_readable($file)) {
            $this->getIO()->writeError('<error>' . $file . ' is not readable.</error>');

            return 1;
        }

        $validator = new ConfigValidator($this->getIO());
        $checkAll = $input->getOption('no-check-all') ? 0 : ValidatingArrayLoader::CHECK_ALL;
        $checkPublish = !$input->getOption('no-check-publish');
        list($errors, $publishErrors, $warnings) = $validator->validate($file, $checkAll);

        $checkLock = !$input->getOption('no-check-lock');

        $lockErrors = array();
        $composer = Factory::create($this->getIO(), $file);
        $locker = $composer->getLocker();
        if ($locker->isLocked() && !$locker->isFresh()) {
            $lockErrors[] = 'The lock file is not up to date with the latest changes in composer.json.';
        }

        // output errors/warnings
        if (!$errors && !$publishErrors && !$warnings) {
            $this->getIO()->write('<info>' . $file . ' is valid</info>');
        } elseif (!$errors && !$publishErrors) {
            $this->getIO()->writeError('<info>' . $file . ' is valid, but with a few warnings</info>');
            $this->getIO()->writeError('<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
        } elseif (!$errors) {
            $this->getIO()->writeError('<info>' . $file . ' is valid for simple usage with composer but has</info>');
            $this->getIO()->writeError('<info>strict errors that make it unable to be published as a package:</info>');
            $this->getIO()->writeError('<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
        } else {
            $this->getIO()->writeError('<error>' . $file . ' is invalid, the following errors/warnings were found:</error>');
        }

        $messages = array(
            'error' => $errors,
            'warning' => $warnings,
        );

        // If checking publish errors, display them as errors, otherwise just show them as warnings
        if ($checkPublish) {
            $messages['error'] = array_merge($messages['error'], $publishErrors);
        } else {
            $messages['warning'] = array_merge($messages['warning'], $publishErrors);
        }

        // If checking lock errors, display them as errors, otherwise just show them as warnings
        if ($checkLock) {
            $messages['error'] = array_merge($messages['error'], $lockErrors);
        } else {
            $messages['warning'] = array_merge($messages['warning'], $lockErrors);
        }

        foreach ($messages as $style => $msgs) {
            foreach ($msgs as $msg) {
                $this->getIO()->writeError('<' . $style . '>' . $msg . '</' . $style . '>');
            }
        }

        return $errors || ($publishErrors && $checkPublish) || ($lockErrors && $checkLock) ? 1 : 0;
    }
}
