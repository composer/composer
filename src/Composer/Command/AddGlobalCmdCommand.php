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
        // only run on Linux!
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $output->writeln('This command cannot be run on Windows. Use the Windows installer.');

            return;
        }

        $installMode = $input->getOption('install-mode');

        // if we're being called by the installer, ask for confirmation first
        if ($installMode) {
            /** @var \Symfony\Component\Console\Helper\DialogHelper $dialogHelper */
            $dialogHelper = $this->getHelper('dialog');

            if (!$dialogHelper->askConfirmation(
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

            return;
        }

        $sourcePhar = \Phar::running(false);
        if (!$sourcePhar) {
            $output->writeln('<error>This command can only be run from a PHAR file</error>');

            return;
        }

        // try to copy the composer file
        $targetPath = $targetDir.'/composer';
        if (!$this->copyComposerExec($sourcePhar, $targetPath)) {
            if (!$installMode) {
                $output->writeln(sprintf('<error>Failed copying composer into %s</error>', $targetDir));
                $this->printErrorMessage($output);
            }

            return;
        }

        // set the executable permissions
        if (!$this->setExecutablePermissions($targetPath)) {
            if (!$installMode) {
                $output->writeln(sprintf('<error>Failed giving %s executable permissions</error>', $targetDir));
                $this->printErrorMessage($output);
            }

            return;
        }

        $output->writeln(sprintf('Composer successfully copied to <info>%s</info>', $targetPath));

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
        }
    }

    /**
     * Can the user type "composer" as a global command already?
     *
     * @return bool
     */
    protected function doesComposerGlobalExist()
    {
        $process = new Process('which composer');
        $process->run();

        // if there is no exectuable, the output is empty
        return (bool) $process->getOutput();
    }

    /**
     * Returns the path to the directory where the PHP binary exists
     *
     * @return bool|string
     */
    protected function findTargetBinDir()
    {
        $phpExecutableFinder = new PhpExecutableFinder();
        $phpBinPath = $phpExecutableFinder->find();
        if (!$phpBinPath) {
            return false;
        }

        return dirname($phpBinPath);
    }

    /**
     * Actually copies this composer executable into the target directory
     *
     * @param string $sourcePhar
     * @param string $targetPath
     * @return bool
     */
    protected function copyComposerExec($sourcePhar, $targetPath)
    {
        // this might not be a phar. In that case, we can't copy it
        if (!$sourcePhar) {
            return false;
        }

        $process = new Process(sprintf('sudo cp %s %s', $sourcePhar, $targetPath));
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Tries to make the composer file executable
     *
     * @param string $targetPath
     * @return bool
     */
    protected function setExecutablePermissions($targetPath)
    {
        $process = new Process(sprintf('sudo chmod +x %s', $targetPath));
        $process->run();

        return $process->isSuccessful();
    }

    protected function printErrorMessage(OutputInterface $output)
    {
        $output->writeln('If you want a global composer command, manually copy <info>composer.phar</info> into a bin directory and rename it to composer');
    }
}
