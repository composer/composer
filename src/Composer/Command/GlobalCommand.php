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

use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\XdebugHandler\Process;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GlobalCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('global')
            ->setDescription('Allows running commands in the global composer dir ($COMPOSER_HOME).')
            ->setDefinition(array(
                new InputArgument('command-name', InputArgument::REQUIRED, ''),
                new InputArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, ''),
            ))
            ->setHelp(
                <<<EOT
Use this command as a wrapper to run other Composer commands
within the global context of COMPOSER_HOME.

You can use this to install CLI utilities globally, all you need
is to add the COMPOSER_HOME/vendor/bin dir to your PATH env var.

This is already done for you on Windows, when a global command is run.

COMPOSER_HOME is c:\Users\<user>\AppData\Roaming\Composer on Windows
and /home/<user>/.composer on unix systems.

If your system uses freedesktop.org standards, then it will first check
XDG_CONFIG_HOME or default to /home/<user>/.config/composer

Note: This path may vary depending on customizations to bin-dir in
composer.json or the environmental variable COMPOSER_BIN_DIR.

Read more at https://getcomposer.org/doc/03-cli.md#global
EOT
            )
        ;
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        // extract real command name
        $tokens = preg_split('{\s+}', $input->__toString());
        $args = array();
        foreach ($tokens as $token) {
            if ($token && $token[0] !== '-') {
                $args[] = $token;
                if (count($args) >= 2) {
                    break;
                }
            }
        }

        // show help for this command if no command was found
        if (count($args) < 2) {
            return parent::run($input, $output);
        }

        // change to global dir
        $io = $this->getIO();
        $config = Factory::createConfig();
        $home = $config->get('home');

        if (!is_dir($home)) {
            $fs = new Filesystem();
            $fs->ensureDirectoryExists($home);
            if (!is_dir($home)) {
                throw new \RuntimeException('Could not create home directory');
            }
        }

        try {
            chdir($home);
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not switch to home directory "'.$home.'"', 0, $e);
        }
        $io->writeError('<info>Changed current directory to '.$home.'</info>');

        $binDir = $home.'/'.$config->get('bin-dir', Config::RELATIVE_PATHS);

        if (Platform::isWindows()) {
            $this->checkWindowsPath($io, $binDir);
        }

        // create new input without "global" command prefix
        $input = new StringInput(preg_replace('{\bg(?:l(?:o(?:b(?:a(?:l)?)?)?)?)?\b}', '', $input->__toString(), 1));
        $this->getApplication()->resetComposer();

        return $this->getApplication()->run($input, $output);
    }

    /**
     * {@inheritDoc}
     */
    public function isProxyCommand()
    {
        return true;
    }

    /**
     * Adds the global bin directory to the user path if it is missing
     *
     * @param IOInterface $io
     * @param string $binDir The global bin directory
     */
    private function checkWindowsPath(IOInterface $io, $binDir)
    {
        // Check local environment. Any %..% value will have been expanded
        if ($this->findInWindowsPath((string) getenv('Path'), $binDir)) {
            return;
        }

        // Check user environment
        exec('reg query HKCU\Environment -v Path 2> nul', $output, $exitCode);
        $regex = '{[[:blank:]]*REG_[A-Z_]{2,}[[:blank:]]*(.*)$}';

        if (!preg_match($regex, implode(PHP_EOL, $output), $matches)) {
            // Shouldn't happen
            return;
        }

        $regPath = trim($matches[1]);

        if ($this->findInWindowsPath($regPath, $binDir)) {
            return;
        }

        // Update Path in user environment
        $path = Process::escape($this->appendToWindowsPath($regPath, $binDir));
        $command = 'setx Path '.$path.' 2>&1';
        exec($command, $output, $exitCode);

        $details = '"'.$binDir.'" to your PATH.';

        if ($exitCode !== 0) {
            $io->writeError('<warning>Failed to add '.$details.'</warning>');
        } else {
            $io->writeError('<info>Added '.$details.' Open a new terminal to use it.</info>');
        }
    }

    /**
     * Returns true if the directory is found in the path list
     *
     * @param string $path Environment path list
     * @param string $directory The directory to search for
     * @return bool
     */
    private function findInWindowsPath($path, $directory)
    {
        $path = preg_replace('{/+}', '/', strtr($path, '\\', '/'));
        $path = ';'.$path.';';

        $directory = preg_replace('{/+}', '/', strtr($directory, '\\', '/'));
        $directory = preg_quote(rtrim($directory, '/'), '{}');

        // Search for trailing slash in path
        $regex = '{;'.$directory.'/?'.';}';

        return (bool) preg_match($regex, $path);
    }

    /**
     * Adds a directory entry to the end of a path list
     *
     * @param string $path Environment path list
     * @param string $directory The directory to append
     * @return string The appended path list
     */
    private function appendToWindowsPath($path, $directory)
    {
        // Normalize to single backslashes with no trailing backslash
        $directory = preg_replace('{\\\\+}', '\\', strtr($directory, '/', '\\'));
        $directory = rtrim($directory, '\\');

        if ($path = rtrim($path, ';')) {
            return $path.';'.$directory;
        }

        return $directory;
    }
}
