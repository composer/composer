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

use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Fabien Potencier <fabien.potencier@gmail.com>
 */
class RunScriptCommand extends BaseCommand
{
    /**
     * @var array Array with command events
     */
    protected $scriptEvents = array(
        ScriptEvents::PRE_INSTALL_CMD,
        ScriptEvents::POST_INSTALL_CMD,
        ScriptEvents::PRE_UPDATE_CMD,
        ScriptEvents::POST_UPDATE_CMD,
        ScriptEvents::PRE_STATUS_CMD,
        ScriptEvents::POST_STATUS_CMD,
        ScriptEvents::POST_ROOT_PACKAGE_INSTALL,
        ScriptEvents::POST_CREATE_PROJECT_CMD,
        ScriptEvents::PRE_ARCHIVE_CMD,
        ScriptEvents::POST_ARCHIVE_CMD,
        ScriptEvents::PRE_AUTOLOAD_DUMP,
        ScriptEvents::POST_AUTOLOAD_DUMP,
    );

    protected function configure()
    {
        $this
            ->setName('run-script')
            ->setDescription('Run the scripts defined in composer.json.')
            ->setDefinition(array(
                new InputArgument('script', InputArgument::OPTIONAL, 'Script name to run.'),
                new InputArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, ''),
                new InputOption('timeout', null, InputOption::VALUE_REQUIRED, 'Sets script timeout in seconds, or 0 for never.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Sets the dev mode.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables the dev mode.'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List scripts.'),
            ))
            ->setHelp(<<<EOT
The <info>run-script</info> command runs scripts defined in composer.json:

<info>php composer.phar run-script post-update-cmd</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('list')) {
            return $this->listScripts();
        } elseif (!$input->getArgument('script')) {
            throw new \RuntimeException('Missing required argument "script"');
        }

        $script = $input->getArgument('script');
        if (!in_array($script, $this->scriptEvents)) {
            if (defined('Composer\Script\ScriptEvents::'.str_replace('-', '_', strtoupper($script)))) {
                throw new \InvalidArgumentException(sprintf('Script "%s" cannot be run with this command', $script));
            }
        }

        $composer = $this->getComposer();
        $devMode = $input->getOption('dev') || !$input->getOption('no-dev');
        $event = new ScriptEvent($script, $composer, $this->getIO(), $devMode);
        $hasListeners = $composer->getEventDispatcher()->hasEventListeners($event);
        if (!$hasListeners) {
            throw new \InvalidArgumentException(sprintf('Script "%s" is not defined in this package', $script));
        }

        $args = $input->getArgument('args');

        if (!is_null($timeout = $input->getOption('timeout'))) {
            if (!ctype_digit($timeout)) {
                throw new \RuntimeException('Timeout value must be numeric and positive if defined, or 0 for forever');
            }
            // Override global timeout set before in Composer by environment or config
            ProcessExecutor::setTimeout((int) $timeout);
        }

        return $composer->getEventDispatcher()->dispatchScript($script, $devMode, $args);
    }

    protected function listScripts()
    {
        $scripts = $this->getComposer()->getPackage()->getScripts();

        if (!count($scripts)) {
            return 0;
        }

        $io = $this->getIO();
        $io->writeError('<info>scripts:</info>');
        foreach ($scripts as $name => $script) {
            $io->write('  ' . $name);
        }

        return 0;
    }
}
