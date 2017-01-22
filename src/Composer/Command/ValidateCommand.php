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
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
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
class ValidateCommand extends BaseCommand
{
    /**
     * configure
     */
    protected function configure()
    {
        $this
            ->setName('validate')
            ->setDescription('Validates a composer.json and composer.lock.')
            ->setDefinition(array(
                new InputOption('no-check-all', null, InputOption::VALUE_NONE, 'Do not make a complete validation'),
                new InputOption('no-check-lock', null, InputOption::VALUE_NONE, 'Do not check if lock file is up to date'),
                new InputOption('no-check-publish', null, InputOption::VALUE_NONE, 'Do not check for publish errors'),
                new InputOption('with-dependencies', 'A', InputOption::VALUE_NONE, 'Also validate the composer.json of all installed dependencies'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Return a non-zero exit code for warnings as well as errors'),
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file', './composer.json'),
            ))
            ->setHelp(<<<EOT
The validate command validates a given composer.json and composer.lock

Exit codes in case of errors are:
1 validation warning(s), only when --strict is given
2 validation error(s)
3 file unreadable or missing

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
        $io = $this->getIO();

        if (!file_exists($file)) {
            $io->writeError('<error>' . $file . ' not found.</error>');

            return 3;
        }
        if (!is_readable($file)) {
            $io->writeError('<error>' . $file . ' is not readable.</error>');

            return 3;
        }

        $validator = new ConfigValidator($io);
        $checkAll = $input->getOption('no-check-all') ? 0 : ValidatingArrayLoader::CHECK_ALL;
        $checkPublish = !$input->getOption('no-check-publish');
        $checkLock = !$input->getOption('no-check-lock');
        $isStrict = $input->getOption('strict');
        list($errors, $publishErrors, $warnings) = $validator->validate($file, $checkAll);

        $lockErrors = array();
        $composer = Factory::create($io, $file);
        $locker = $composer->getLocker();
        if ($locker->isLocked() && !$locker->isFresh()) {
            $lockErrors[] = 'The lock file is not up to date with the latest changes in composer.json, it is recommended that you run `composer update`.';
        }

        $this->outputResult($io, $file, $errors, $warnings, $checkPublish, $publishErrors, $checkLock, $lockErrors, true);

        $exitCode = $errors || ($publishErrors && $checkPublish) || ($lockErrors && $checkLock) ? 2 : ($isStrict && $warnings ? 1 : 0);

        if ($input->getOption('with-dependencies')) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            foreach ($localRepo->getPackages() as $package) {
                $path = $composer->getInstallationManager()->getInstallPath($package);
                $file = $path . '/composer.json';
                if (is_dir($path) && file_exists($file)) {
                    list($errors, $publishErrors, $warnings) = $validator->validate($file, $checkAll);
                    $this->outputResult($io, $package->getPrettyName(), $errors, $warnings, $checkPublish, $publishErrors);

                    $depCode = $errors || ($publishErrors && $checkPublish) ? 2 : ($isStrict && $warnings ? 1 : 0);
                    $exitCode = max($depCode, $exitCode);
                }
            }
        }

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'validate', $input, $output);
        $eventCode = $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);
        $exitCode = max($eventCode, $exitCode);

        return $exitCode;
    }

    private function outputResult($io, $name, &$errors, &$warnings, $checkPublish = false, $publishErrors = array(), $checkLock = false, $lockErrors = array(), $printSchemaUrl = false)
    {
        if (!$errors && !$publishErrors && !$warnings) {
            $io->write('<info>' . $name . ' is valid</info>');
        } elseif (!$errors && !$publishErrors) {
            $io->writeError('<info>' . $name . ' is valid, but with a few warnings</info>');
            if ($printSchemaUrl) {
                $io->writeError('<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
            }
        } elseif (!$errors) {
            $io->writeError('<info>' . $name . ' is valid for simple usage with composer but has</info>');
            $io->writeError('<info>strict errors that make it unable to be published as a package:</info>');
            if ($printSchemaUrl) {
                $io->writeError('<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
            }
        } else {
            $io->writeError('<error>' . $name . ' is invalid, the following errors/warnings were found:</error>');
        }

        // If checking publish errors, display them as errors, otherwise just show them as warnings
        if ($checkPublish) {
            $errors = array_merge($errors, $publishErrors);
        } else {
            $warnings = array_merge($warnings, $publishErrors);
        }

        // If checking lock errors, display them as errors, otherwise just show them as warnings
        if ($checkLock) {
            $errors = array_merge($errors, $lockErrors);
        } else {
            $warnings = array_merge($warnings, $lockErrors);
        }

        $messages = array(
            'error' => $errors,
            'warning' => $warnings,
        );

        foreach ($messages as $style => $msgs) {
            foreach ($msgs as $msg) {
                $io->writeError('<' . $style . '>' . $msg . '</' . $style . '>');
            }
        }
    }
}
