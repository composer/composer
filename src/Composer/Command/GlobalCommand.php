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
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
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
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, ''),
                new InputArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, ''),
            ))
            ->setHelp(
                <<<EOT
Use this command as a wrapper to run other Composer commands
within the global context of COMPOSER_HOME.

You can use this to install CLI utilities globally, all you need
is to add the COMPOSER_HOME/vendor/bin dir to your PATH env var.

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
        $globalInput = $this->parseGlobalInput($input);
        $realInput = $this->parseRealInput($input);

        // show help for this command if no command was found
        if ($realInput === null) {
            return parent::run($input, $output);
        }

        // change to global dir
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

        if ($globalInput->hasOption('verbose') && $globalInput->getOption('verbose')) {
            $this->getIO()->write('<info>Changed current directory to '.$home.'</info>');
        }

        // create new input without "global" command prefix
        $input = new StringInput(preg_replace('{\bg(?:l(?:o(?:b(?:a(?:l)?)?)?)?)?\b}', '', $input->__toString(), 1));
        $this->getApplication()->resetComposer();

        return $this->getApplication()->run($input, $output);
    }

    protected function splitTokens(InputInterface $input)
    {
        return preg_split('{\s+}', $input->__toString());
    }

    /**
     * Parse the input which is only meant for the "global" command, not the "real" command after it
     *
     * @param InputInterface $input
     * @return InputInterface
     */
    protected function parseGlobalInput(InputInterface $input)
    {
        $tokens = $this->splitTokens($input);
        $arguments = array();
        $options = array();

        foreach ($tokens as $token) {
            if ($token[0] !== '-') {
                if (count($arguments) >= 1) {
                    // break when the "global" token and its options are parsed, anything after is part of the real command
                    break;
                }

                $arguments[] = $token;
            } else if (count($arguments) >= 1) {
                // only parse options passed after the "global" token, not before (e.g. "composer -v global ...")
                $options[] = $token;
            }
        }

        $input = new StringInput(join(' ', array_merge($arguments, $options)));
        $input->bind($this->getDefinition());
        return $input;
    }

    /**
     * Parse the input which is only meant for the "real" command, basically the inverse of {@see GlobalCommand::parseGlobalInput()}
     *
     * @param InputInterface $input
     * @return InputInterface
     */
    protected function parseRealInput(InputInterface $input)
    {
        $tokens = $this->splitTokens($input);
        $allParts = array();
        $realParts = array();

        foreach ($tokens as $index => $token) {
            if ($token[0] !== '-') {
                $allParts[] = $token;

                if (count($allParts) < 2) {
                    // continue if we are still parsing the arguments and options of the "global" command
                    continue;
                }

                $realParts[] = $token;
            } else if (count($realParts) >= 1) {
                // only parse options which are meant for the "real" command
                $realParts[] = $token;
            }
        }

        return ($realParts ? new StringInput(join(' ', $realParts)) : null);
    }

    /**
     * {@inheritDoc}
     */
    public function isProxyCommand()
    {
        return true;
    }
}
