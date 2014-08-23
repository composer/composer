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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @author Ryan Weaver <ryan@knpuniversity.com>
 */
class AddGlobalCmdCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('add-global-cmd')
            ->setDescription('Creates a global system "composer" command')
            ->addOption('install-mode', null, InputOption::VALUE_NONE, 'Used when installing Composer - different input/output')
            ->setHelp(<<<EOT
<info>php composer.phar add-global-cmd</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isWindows = defined('PHP_WINDOWS_VERSION_BUILD');
        $installMode = $input->getOption('install-mode');

        // if we're being called by the installer, ask for confirmation first
        if ($installMode) {
            /** @var \Symfony\Component\Console\Helper\DialogHelper $dialogHelper */
            $dialogHelper = $this->getHelper('dialog');

            if ($input->isInteractive() && !$dialogHelper->askConfirmation(
                $output,
                "Do you want to install a global executable?\nThis will let you type <info>composer</info> anywhere to use it (requires root access). [Y/n] "
            )) {
                return;
            }
        } else {
            // give normal user's a notice that the you might be asked for your sudo password
            $output->writeln('<comment>This will attempt to create a global composer executable, which requires root access.</comment>');
        }

        // try to find a target bin directory
        $targetDir = $this->findTargetBinDir();
        if (!$targetDir) {
            // hide the output if we're being called by the installer
            if (!$installMode) {
                $output->writeln('<error>Could not determine a valid bin directory</error>');
            }

            return 1;
        }

        $sourcePhar = \Phar::running(false);
        if (!$sourcePhar) {
            $output->writeln('<error>This command can only be run from a PHAR file</error>');

            return 1;
        }

        // try to copy the composer file
        $targetPath = $targetDir.'/composer';
        if ($isWindows) {
            // windows will have a composer.phar AND a composer.bat that will call it
            $targetPath .= '.phar';
        }

        if (!$this->copyComposerExec($sourcePhar, $targetPath, $isWindows)) {
            if (!$installMode) {
                $output->writeln(sprintf('<error>Failed copying composer into %s</error>', $targetDir));
                $this->printErrorMessage($output);
            }

            return 1;
        }

        $output->writeln(sprintf('Composer successfully copied to <info>%s</info>', $targetPath));

        if ($isWindows) {
            // create a .bat file for Windows that executes composer
            $batPath = $targetDir.'/composer.bat';
            if (!$this->createWindowsBatFile($batPath)) {
                if (!$installMode) {
                    $output->writeln(sprintf('<error>Failed copying Windows bat file into %s</error>', $targetDir));
                    $this->printErrorMessage($output);
                }

                return 1;
            }

            $output->writeln(sprintf('A .bat file was also created at <info>%s</info>', $batPath));
        } else {
            // for Unix, make sure the file is executable
            if (!$this->setExecutablePermissions($targetPath)) {
                if (!$installMode) {
                    $output->writeln(sprintf('<error>Failed giving %s executable permissions</error>', $targetDir));
                    $this->printErrorMessage($output);
                }

                return 1;
            }
        }

        // check to see if we now have a composer command or not (PATH problem?)
        if ($this->doesComposerGlobalExist()) {
            if (!$installMode) {
                $output->writeln('Success! Run <info>composer</info> to use the global executable.');
            }
        } else {
            if (!$installMode) {
                $output->writeln('<error>Global executable command failed</error>');
                $this->printErrorMessage($output);
            }

            return 1;
        }
    }

    /**
     * Can the user type "composer" as a global command already?
     *
     * @return bool
     */
    protected function doesComposerGlobalExist()
    {
        $process = new Process('composer');
        $process->run();

        // which returns an error code if no command is found
        return $process->isSuccessful();
    }

    /**
     * Returns the path to the directory where the PHP binary exists
     *
     * @return string|null
     */
    protected function findTargetBinDir()
    {
        $phpExecutableFinder = new PhpExecutableFinder();
        $phpBinPath = $phpExecutableFinder->find();

        return $phpBinPath ? dirname($phpBinPath) : null;
    }

    /**
     * Actually copies this composer executable into the target directory
     *
     * @param string $sourcePhar
     * @param string $targetPath
     * @param bool   $isWindows
     * @return bool
     */
    protected function copyComposerExec($sourcePhar, $targetPath, $isWindows)
    {
        if ($isWindows) {
            // use a normal copy for Windows
            return copy($sourcePhar, $targetPath);
        } else {
            // use a command with "sudo", which is likely needed
            $cmd = sprintf('sudo cp %s %s', $sourcePhar, $targetPath);
            $process = new Process($cmd);
            $process->run();

            return $process->isSuccessful();
        }
    }

    /**
     * Tries to make the composer file executable
     *
     * @param string $targetPath
     * @return bool
     */
    protected function setExecutablePermissions($targetPath)
    {
        // if we're already executable, no need to change permissions
        if (is_executable($targetPath)) {
            return true;
        }

        $process = new Process(sprintf('sudo chmod +x %s', $targetPath));
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Writes a Windows .bat file that executes the composer.phar file next to it
     *
     * @param string $targetPath The path where the .bat file should live
     * @return bool
     */
    protected function createWindowsBatFile($targetPath)
    {
        // create a .bat file that executes composer
        $batFile = <<<EOT
@echo off
:: Composer CLI Shortcut
"%~dp0php" "%~dp0composer.phar" %*
EOT;

        return (bool) file_put_contents($targetPath, $batFile);
    }

    protected function printErrorMessage(OutputInterface $output)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $output->writeln('If you want a global composer command, try using the windows installer: https://getcomposer.org/doc/00-intro.md#using-the-installer');
        } else {
            $output->writeln('If you want a global composer command, manually copy <info>composer.phar</info> into a bin directory and rename it to composer');
        }
    }
}
