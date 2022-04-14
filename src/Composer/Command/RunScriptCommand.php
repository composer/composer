<?php declare(strict_types=1);

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
use Composer\Util\Platform;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Fabien Potencier <fabien.potencier@gmail.com>
 */
class RunScriptCommand extends BaseCommand
{
    /**
     * @var string[] Array with command events
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

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('run-script')
            ->setAliases(array('run'))
            ->setDescription('Runs the scripts defined in composer.json.')
            ->setDefinition(array(
                new InputArgument('script', InputArgument::OPTIONAL, 'Script name to run.', null, function () {
                    return array_keys($this->requireComposer()->getPackage()->getScripts());
                }),
                new InputArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, ''),
                new InputOption('timeout', null, InputOption::VALUE_REQUIRED, 'Sets script timeout in seconds, or 0 for never.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Sets the dev mode.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables the dev mode.'),
                new InputOption('list', 'l', InputOption::VALUE_NONE, 'List scripts.'),
            ))
            ->setHelp(
                <<<EOT
The <info>run-script</info> command runs scripts defined in composer.json:

<info>php composer.phar run-script post-update-cmd</info>

Read more at https://getcomposer.org/doc/03-cli.md#run-script
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list')) {
            return $this->listScripts($output);
        }

        $script = $input->getArgument('script');
        if ($script === null) {
            throw new \RuntimeException('Missing required argument "script"');
        }

        if (!in_array($script, $this->scriptEvents)) {
            if (defined('Composer\Script\ScriptEvents::'.str_replace('-', '_', strtoupper($script)))) {
                throw new \InvalidArgumentException(sprintf('Script "%s" cannot be run with this command', $script));
            }
        }

        $composer = $this->requireComposer();
        $devMode = $input->getOption('dev') || !$input->getOption('no-dev');
        $event = new ScriptEvent($script, $composer, $this->getIO(), $devMode);
        $hasListeners = $composer->getEventDispatcher()->hasEventListeners($event);
        if (!$hasListeners) {
            throw new \InvalidArgumentException(sprintf('Script "%s" is not defined in this package', $script));
        }

        $args = $input->getArgument('args');

        if (null !== $timeout = $input->getOption('timeout')) {
            if (!ctype_digit($timeout)) {
                throw new \RuntimeException('Timeout value must be numeric and positive if defined, or 0 for forever');
            }
            // Override global timeout set before in Composer by environment or config
            ProcessExecutor::setTimeout((int) $timeout);
        }

        Platform::putEnv('COMPOSER_DEV_MODE', $devMode ? '1' : '0');

        return $composer->getEventDispatcher()->dispatchScript($script, $devMode, $args);
    }

    /**
     * @return int
     */
    protected function listScripts(OutputInterface $output): int
    {
        $scripts = $this->requireComposer()->getPackage()->getScripts();

        if (!count($scripts)) {
            return 0;
        }

        $io = $this->getIO();
        $io->writeError('<info>scripts:</info>');
        $table = array();
        foreach ($scripts as $name => $script) {
            $description = '';
            try {
                $cmd = $this->getApplication()->find($name);
                if ($cmd instanceof ScriptAliasCommand) {
                    $description = $cmd->getDescription();
                }
            } catch (\Symfony\Component\Console\Exception\CommandNotFoundException $e) {
                // ignore scripts that have no command associated, like native Composer script listeners
            }
            $table[] = array('  '.$name, $description);
        }

        $this->renderTable($table, $output);

        return 0;
    }
}
