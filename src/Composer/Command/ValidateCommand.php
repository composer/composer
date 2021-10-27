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
use Composer\IO\IOInterface;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Util\ConfigValidator;
use Composer\Util\Filesystem;
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
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('validate')
            ->setDescription('Validates a composer.json and composer.lock.')
            ->setDefinition(array(
                new InputOption('no-check-all', null, InputOption::VALUE_NONE, 'Do not validate requires for overly strict/loose constraints'),
                new InputOption('no-check-lock', null, InputOption::VALUE_NONE, 'Do not check if lock file is up to date'),
                new InputOption('no-check-publish', null, InputOption::VALUE_NONE, 'Do not check for publish errors'),
                new InputOption('no-check-version', null, InputOption::VALUE_NONE, 'Do not report a warning if the version field is present'),
                new InputOption('with-dependencies', 'A', InputOption::VALUE_NONE, 'Also validate the composer.json of all installed dependencies'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Return a non-zero exit code for warnings as well as errors'),
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file'),
            ))
            ->setHelp(
                <<<EOT
The validate command validates a given composer.json and composer.lock

Exit codes in case of errors are:
1 validation warning(s), only when --strict is given
2 validation error(s)
3 file unreadable or missing

Read more at https://getcomposer.org/doc/03-cli.md#validate
EOT
            );
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('file') ?: Factory::getComposerFile();
        $io = $this->getIO();

        if (!file_exists($file)) {
            $io->writeError('<error>' . $file . ' not found.</error>');

            return 3;
        }
        if (!Filesystem::isReadable($file)) {
            $io->writeError('<error>' . $file . ' is not readable.</error>');

            return 3;
        }

        $validator = new ConfigValidator($io);
        $checkAll = $input->getOption('no-check-all') ? 0 : ValidatingArrayLoader::CHECK_ALL;
        $checkPublish = !$input->getOption('no-check-publish');
        $checkLock = !$input->getOption('no-check-lock');
        $checkVersion = $input->getOption('no-check-version') ? 0 : ConfigValidator::CHECK_VERSION;
        $isStrict = $input->getOption('strict');
        list($errors, $publishErrors, $warnings) = $validator->validate($file, $checkAll, $checkVersion);

        $lockErrors = array();
        $composer = Factory::create($io, $file, $input->hasParameterOption('--no-plugins'));
        $locker = $composer->getLocker();
        if ($locker->isLocked() && !$locker->isFresh()) {
            $lockErrors[] = '- The lock file is not up to date with the latest changes in composer.json, it is recommended that you run `composer update` or `composer update <package name>`.';
        }

        if ($locker->isLocked()) {
            $missingRequirements = false;
            $sets = array(
                array('repo' => $locker->getLockedRepository(false), 'method' => 'getRequires', 'description' => 'Required'),
                array('repo' => $locker->getLockedRepository(true), 'method' => 'getDevRequires', 'description' => 'Required (in require-dev)'),
            );
            foreach ($sets as $set) {
                $installedRepo = new InstalledRepository(array($set['repo']));

                foreach (call_user_func(array($composer->getPackage(), $set['method'])) as $link) {
                    if (PlatformRepository::isPlatformPackage($link->getTarget())) {
                        continue;
                    }
                    if (!$installedRepo->findPackagesWithReplacersAndProviders($link->getTarget(), $link->getConstraint())) {
                        if ($results = $installedRepo->findPackagesWithReplacersAndProviders($link->getTarget())) {
                            $provider = reset($results);
                            $lockErrors[] = '- ' . $set['description'].' package "' . $link->getTarget() . '" is in the lock file as "'.$provider->getPrettyVersion().'" but that does not satisfy your constraint "'.$link->getPrettyConstraint().'".';
                        } else {
                            $lockErrors[] = '- ' . $set['description'].' package "' . $link->getTarget() . '" is not present in the lock file.';
                        }
                        $missingRequirements = true;
                    }
                }
            }

            if ($missingRequirements) {
                $lockErrors[] = 'This usually happens when composer files are incorrectly merged or the composer.json file is manually edited.';
                $lockErrors[] = 'Read more about correctly resolving merge conflicts https://getcomposer.org/doc/articles/resolving-merge-conflicts.md';
                $lockErrors[] = 'and prefer using the "require" command over editing the composer.json file directly https://getcomposer.org/doc/03-cli.md#require';
            }
        }

        $this->outputResult($io, $file, $errors, $warnings, $checkPublish, $publishErrors, $checkLock, $lockErrors, true);

        // $errors include publish and lock errors when exists
        $exitCode = $errors ? 2 : ($isStrict && $warnings ? 1 : 0);

        if ($input->getOption('with-dependencies')) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            foreach ($localRepo->getPackages() as $package) {
                $path = $composer->getInstallationManager()->getInstallPath($package);
                $file = $path . '/composer.json';
                if (is_dir($path) && file_exists($file)) {
                    list($errors, $publishErrors, $warnings) = $validator->validate($file, $checkAll, $checkVersion);

                    $this->outputResult($io, $package->getPrettyName(), $errors, $warnings, $checkPublish, $publishErrors);

                    // $errors include publish errors when exists
                    $depCode = $errors ? 2 : ($isStrict && $warnings ? 1 : 0);
                    $exitCode = max($depCode, $exitCode);
                }
            }
        }

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'validate', $input, $output);
        $eventCode = $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        return max($eventCode, $exitCode);
    }

    /**
     * @param string $name
     * @param string[] $errors
     * @param string[] $warnings
     * @param bool $checkPublish
     * @param string[] $publishErrors
     * @param bool $checkLock
     * @param string[] $lockErrors
     * @param bool $printSchemaUrl
     *
     * @return void
     */
    private function outputResult(IOInterface $io, $name, &$errors, &$warnings, $checkPublish = false, $publishErrors = array(), $checkLock = false, $lockErrors = array(), $printSchemaUrl = false)
    {
        $doPrintSchemaUrl = false;

        if ($errors) {
            $io->writeError('<error>' . $name . ' is invalid, the following errors/warnings were found:</error>');
        } elseif ($publishErrors) {
            $io->writeError('<info>' . $name . ' is valid for simple usage with Composer but has</info>');
            $io->writeError('<info>strict errors that make it unable to be published as a package</info>');
            $doPrintSchemaUrl = $printSchemaUrl;
        } elseif ($warnings) {
            $io->writeError('<info>' . $name . ' is valid, but with a few warnings</info>');
            $doPrintSchemaUrl = $printSchemaUrl;
        } elseif ($lockErrors) {
            $io->write('<info>' . $name . ' is valid but your composer.lock has some '.($checkLock ? 'errors' : 'warnings').'</info>');
        } else {
            $io->write('<info>' . $name . ' is valid</info>');
        }

        if ($doPrintSchemaUrl) {
            $io->writeError('<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>');
        }

        if ($errors) {
            $errors = array_map(function ($err) {
                return '- ' . $err;
            }, $errors);
            array_unshift($errors, '# General errors');
        }
        if ($warnings) {
            $warnings = array_map(function ($err) {
                return '- ' . $err;
            }, $warnings);
            array_unshift($warnings, '# General warnings');
        }

        // Avoid setting the exit code to 1 in case --strict and --no-check-publish/--no-check-lock are combined
        $extraWarnings = array();

        // If checking publish errors, display them as errors, otherwise just show them as warnings
        if ($publishErrors) {
            $publishErrors = array_map(function ($err) {
                return '- ' . $err;
            }, $publishErrors);

            if ($checkPublish) {
                array_unshift($publishErrors, '# Publish errors');
                $errors = array_merge($errors, $publishErrors);
            } else {
                array_unshift($publishErrors, '# Publish warnings');
                $extraWarnings = array_merge($extraWarnings, $publishErrors);
            }
        }

        // If checking lock errors, display them as errors, otherwise just show them as warnings
        if ($lockErrors) {
            if ($checkLock) {
                array_unshift($lockErrors, '# Lock file errors');
                $errors = array_merge($errors, $lockErrors);
            } else {
                array_unshift($lockErrors, '# Lock file warnings');
                $extraWarnings = array_merge($extraWarnings, $lockErrors);
            }
        }

        $messages = array(
            'error' => $errors,
            'warning' => array_merge($warnings, $extraWarnings),
        );

        foreach ($messages as $style => $msgs) {
            foreach ($msgs as $msg) {
                if (strpos($msg, '#') === 0) {
                    $io->writeError('<' . $style . '>' . $msg . '</' . $style . '>');
                } else {
                    $io->writeError($msg);
                }
            }
        }
    }
}
