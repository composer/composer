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

namespace Composer\Console;

use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\Json\JsonValidationException;
use Composer\Util\ErrorHandler;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\Exception\NoSslException;

/**
 * The console application that handles the commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class Application extends BaseApplication
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    private static $logo = '   ______
  / ____/___  ____ ___  ____  ____  ________  _____
 / /   / __ \/ __ `__ \/ __ \/ __ \/ ___/ _ \/ ___/
/ /___/ /_/ / / / / / / /_/ / /_/ (__  )  __/ /
\____/\____/_/ /_/ /_/ .___/\____/____/\___/_/
                    /_/
';

    private $hasPluginCommands = false;
    private $disablePluginsByDefault = false;

    public function __construct()
    {
        static $shutdownRegistered = false;

        if (function_exists('ini_set') && extension_loaded('xdebug')) {
            ini_set('xdebug.show_exception_trace', false);
            ini_set('xdebug.scream', false);
        }

        if (function_exists('date_default_timezone_set') && function_exists('date_default_timezone_get')) {
            date_default_timezone_set(Silencer::call('date_default_timezone_get'));
        }

        if (!$shutdownRegistered) {
            $shutdownRegistered = true;

            register_shutdown_function(function () {
                $lastError = error_get_last();

                if ($lastError && $lastError['message'] &&
                   (strpos($lastError['message'], 'Allowed memory') !== false /*Zend PHP out of memory error*/ ||
                    strpos($lastError['message'], 'exceeded memory') !== false /*HHVM out of memory errors*/)) {
                    echo "\n". 'Check https://getcomposer.org/doc/articles/troubleshooting.md#memory-limit-errors for more info on how to handle out of memory errors.';
                }
            });
        }

        parent::__construct('Composer', Composer::VERSION);
    }

    /**
     * {@inheritDoc}
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $output) {
            $output = Factory::createOutput();
        }

        return parent::run($input, $output);
    }

    /**
     * {@inheritDoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->disablePluginsByDefault = $input->hasParameterOption('--no-plugins');

        $io = $this->io = new ConsoleIO($input, $output, $this->getHelperSet());
        ErrorHandler::register($io);

        // switch working dir
        if ($newWorkDir = $this->getNewWorkingDir($input)) {
            $oldWorkingDir = getcwd();
            chdir($newWorkDir);
            $io->writeError('Changed CWD to ' . getcwd(), true, IOInterface::DEBUG);
        }

        // determine command name to be executed without including plugin commands
        $commandName = '';
        if ($name = $this->getCommandName($input)) {
            try {
                $commandName = $this->find($name)->getName();
            } catch (\InvalidArgumentException $e) {
            }
        }

        if (!$this->disablePluginsByDefault && !$this->hasPluginCommands && 'global' !== $commandName) {
            try {
                foreach ($this->getPluginCommands() as $command) {
                    if ($this->has($command->getName())) {
                        $io->writeError('<warning>Plugin command '.$command->getName().' ('.get_class($command).') would override a Composer command and has been skipped</warning>');
                    } else {
                        $this->add($command);
                    }
                }
            } catch (NoSslException $e) {
                // suppress these as they are not relevant at this point
            }

            $this->hasPluginCommands = true;
        }

        // determine command name to be executed incl plugin commands, and check if it's a proxy command
        $isProxyCommand = false;
        if ($name = $this->getCommandName($input)) {
            try {
                $command = $this->find($name);
                $commandName = $command->getName();
                $isProxyCommand = ($command instanceof Command\BaseCommand && $command->isProxyCommand());
            } catch (\InvalidArgumentException $e) {
            }
        }

        if (!$isProxyCommand) {
            $io->writeError(sprintf(
                'Running %s (%s) with %s on %s',
                Composer::VERSION,
                Composer::RELEASE_DATE,
                defined('HHVM_VERSION') ? 'HHVM '.HHVM_VERSION : 'PHP '.PHP_VERSION,
                php_uname('s') . ' / ' . php_uname('r')
            ), true, IOInterface::DEBUG);

            if (PHP_VERSION_ID < 50302) {
                $io->writeError('<warning>Composer only officially supports PHP 5.3.2 and above, you will most likely encounter problems with your PHP '.PHP_VERSION.', upgrading is strongly recommended.</warning>');
            }

            if (extension_loaded('xdebug') && !getenv('COMPOSER_DISABLE_XDEBUG_WARN') || getenv('COMPOSER_ALLOW_XDEBUG')) {
                $io->writeError('<warning>You are running composer with xdebug enabled. This has a major impact on runtime performance. See https://getcomposer.org/xdebug</warning>');
            }

            if (defined('COMPOSER_DEV_WARNING_TIME') && $commandName !== 'self-update' && $commandName !== 'selfupdate' && time() > COMPOSER_DEV_WARNING_TIME) {
                $io->writeError(sprintf('<warning>Warning: This development build of composer is over 60 days old. It is recommended to update it by running "%s self-update" to get the latest version.</warning>', $_SERVER['PHP_SELF']));
            }

            if (getenv('COMPOSER_NO_INTERACTION')) {
                $input->setInteractive(false);
            }

            if (!Platform::isWindows() && function_exists('exec') && !getenv('COMPOSER_ALLOW_SUPERUSER')) {
                if (function_exists('posix_getuid') && posix_getuid() === 0) {
                    if ($commandName !== 'self-update' && $commandName !== 'selfupdate') {
                        $io->writeError('<warning>Do not run Composer as root/super user! See https://getcomposer.org/root for details</warning>');
                    }
                    if ($uid = (int) getenv('SUDO_UID')) {
                        // Silently clobber any sudo credentials on the invoking user to avoid privilege escalations later on
                        // ref. https://github.com/composer/composer/issues/5119
                        Silencer::call('exec', "sudo -u \\#{$uid} sudo -K > /dev/null 2>&1");
                    }
                }
                // Silently clobber any remaining sudo leases on the current user as well to avoid privilege escalations
                Silencer::call('exec', 'sudo -K > /dev/null 2>&1');
            }

            // Check system temp folder for usability as it can cause weird runtime issues otherwise
            Silencer::call(function () use ($io) {
                $tempfile = sys_get_temp_dir() . '/temp-' . md5(microtime());
                if (!(file_put_contents($tempfile, __FILE__) && (file_get_contents($tempfile) == __FILE__) && unlink($tempfile) && !file_exists($tempfile))) {
                    $io->writeError(sprintf('<error>PHP temp directory (%s) does not exist or is not writable to Composer. Set sys_temp_dir in your php.ini</error>', sys_get_temp_dir()));
                }
            });

            // add non-standard scripts as own commands
            $file = Factory::getComposerFile();
            if (is_file($file) && is_readable($file) && is_array($composer = json_decode(file_get_contents($file), true))) {
                if (isset($composer['scripts']) && is_array($composer['scripts'])) {
                    foreach ($composer['scripts'] as $script => $dummy) {
                        if (!defined('Composer\Script\ScriptEvents::'.str_replace('-', '_', strtoupper($script)))) {
                            if ($this->has($script)) {
                                $io->writeError('<warning>A script named '.$script.' would override a Composer command and has been skipped</warning>');
                            } else {
                                $this->add(new Command\ScriptAliasCommand($script));
                            }
                        }
                    }
                }
            }
        }

        try {
            if ($input->hasParameterOption('--profile')) {
                $startTime = microtime(true);
                $this->io->enableDebugging($startTime);
            }

            $result = parent::doRun($input, $output);

            if (isset($oldWorkingDir)) {
                chdir($oldWorkingDir);
            }

            if (isset($startTime)) {
                $io->writeError('<info>Memory usage: '.round(memory_get_usage() / 1024 / 1024, 2).'MB (peak: '.round(memory_get_peak_usage() / 1024 / 1024, 2).'MB), time: '.round(microtime(true) - $startTime, 2).'s');
            }

            restore_error_handler();

            return $result;
        } catch (ScriptExecutionException $e) {
            return $e->getCode();
        } catch (\Exception $e) {
            $this->hintCommonErrors($e);
            restore_error_handler();
            throw $e;
        }
    }

    /**
     * @param  InputInterface    $input
     * @throws \RuntimeException
     * @return string
     */
    private function getNewWorkingDir(InputInterface $input)
    {
        $workingDir = $input->getParameterOption(array('--working-dir', '-d'));
        if (false !== $workingDir && !is_dir($workingDir)) {
            throw new \RuntimeException('Invalid working directory specified, '.$workingDir.' does not exist.');
        }

        return $workingDir;
    }

    /**
     * {@inheritDoc}
     */
    private function hintCommonErrors($exception)
    {
        $io = $this->getIO();

        Silencer::suppress();
        try {
            $composer = $this->getComposer(false, true);
            if ($composer) {
                $config = $composer->getConfig();

                $minSpaceFree = 1024 * 1024;
                if ((($df = disk_free_space($dir = $config->get('home'))) !== false && $df < $minSpaceFree)
                    || (($df = disk_free_space($dir = $config->get('vendor-dir'))) !== false && $df < $minSpaceFree)
                    || (($df = disk_free_space($dir = sys_get_temp_dir())) !== false && $df < $minSpaceFree)
                ) {
                    $io->writeError('<error>The disk hosting '.$dir.' is full, this may be the cause of the following exception</error>', true, IOInterface::QUIET);
                }
            }
        } catch (\Exception $e) {
        }
        Silencer::restore();

        if (Platform::isWindows() && false !== strpos($exception->getMessage(), 'The system cannot find the path specified')) {
            $io->writeError('<error>The following exception may be caused by a stale entry in your cmd.exe AutoRun</error>', true, IOInterface::QUIET);
            $io->writeError('<error>Check https://getcomposer.org/doc/articles/troubleshooting.md#-the-system-cannot-find-the-path-specified-windows- for details</error>', true, IOInterface::QUIET);
        }

        if (false !== strpos($exception->getMessage(), 'fork failed - Cannot allocate memory')) {
            $io->writeError('<error>The following exception is caused by a lack of memory or swap, or not having swap configured</error>', true, IOInterface::QUIET);
            $io->writeError('<error>Check https://getcomposer.org/doc/articles/troubleshooting.md#proc-open-fork-failed-errors for details</error>', true, IOInterface::QUIET);
        }
    }

    /**
     * @param  bool                    $required
     * @param  bool|null               $disablePlugins
     * @throws JsonValidationException
     * @return \Composer\Composer
     */
    public function getComposer($required = true, $disablePlugins = null)
    {
        if (null === $disablePlugins) {
            $disablePlugins = $this->disablePluginsByDefault;
        }

        if (null === $this->composer) {
            try {
                $this->composer = Factory::create($this->io, null, $disablePlugins);
            } catch (\InvalidArgumentException $e) {
                if ($required) {
                    $this->io->writeError($e->getMessage());
                    exit(1);
                }
            } catch (JsonValidationException $e) {
                $errors = ' - ' . implode(PHP_EOL . ' - ', $e->getErrors());
                $message = $e->getMessage() . ':' . PHP_EOL . $errors;
                throw new JsonValidationException($message);
            }
        }

        return $this->composer;
    }

    /**
     * Removes the cached composer instance
     */
    public function resetComposer()
    {
        $this->composer = null;
    }

    /**
     * @return IOInterface
     */
    public function getIO()
    {
        return $this->io;
    }

    public function getHelp()
    {
        return self::$logo . parent::getHelp();
    }

    /**
     * Initializes all the composer commands.
     */
    protected function getDefaultCommands()
    {
        $commands = array_merge(parent::getDefaultCommands(), array(
            new Command\AboutCommand(),
            new Command\ConfigCommand(),
            new Command\DependsCommand(),
            new Command\ProhibitsCommand(),
            new Command\InitCommand(),
            new Command\InstallCommand(),
            new Command\CreateProjectCommand(),
            new Command\UpdateCommand(),
            new Command\SearchCommand(),
            new Command\ValidateCommand(),
            new Command\ShowCommand(),
            new Command\SuggestsCommand(),
            new Command\RequireCommand(),
            new Command\DumpAutoloadCommand(),
            new Command\StatusCommand(),
            new Command\ArchiveCommand(),
            new Command\DiagnoseCommand(),
            new Command\RunScriptCommand(),
            new Command\LicensesCommand(),
            new Command\GlobalCommand(),
            new Command\ClearCacheCommand(),
            new Command\RemoveCommand(),
            new Command\HomeCommand(),
            new Command\ExecCommand(),
            new Command\OutdatedCommand(),
        ));

        if ('phar:' === substr(__FILE__, 0, 5)) {
            $commands[] = new Command\SelfUpdateCommand();
        }

        return $commands;
    }

    /**
     * {@inheritDoc}
     */
    public function getLongVersion()
    {
        if (Composer::BRANCH_ALIAS_VERSION) {
            return sprintf(
                '<info>%s</info> version <comment>%s (%s)</comment> %s',
                $this->getName(),
                Composer::BRANCH_ALIAS_VERSION,
                $this->getVersion(),
                Composer::RELEASE_DATE
            );
        }

        return parent::getLongVersion() . ' ' . Composer::RELEASE_DATE;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultInputDefinition()
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption('--profile', null, InputOption::VALUE_NONE, 'Display timing and memory usage information'));
        $definition->addOption(new InputOption('--no-plugins', null, InputOption::VALUE_NONE, 'Whether to disable plugins.'));
        $definition->addOption(new InputOption('--working-dir', '-d', InputOption::VALUE_REQUIRED, 'If specified, use the given directory as working directory.'));

        return $definition;
    }

    private function getPluginCommands()
    {
        $commands = array();

        $composer = $this->getComposer(false, false);
        if (null === $composer) {
            $composer = Factory::createGlobal($this->io, false);
        }

        if (null !== $composer) {
            $pm = $composer->getPluginManager();
            foreach ($pm->getPluginCapabilities('Composer\Plugin\Capability\CommandProvider', array('composer' => $composer, 'io' => $this->io)) as $capability) {
                $newCommands = $capability->getCommands();
                if (!is_array($newCommands)) {
                    throw new \UnexpectedValueException('Plugin capability '.get_class($capability).' failed to return an array from getCommands');
                }
                foreach ($newCommands as $command) {
                    if (!$command instanceof Command\BaseCommand) {
                        throw new \UnexpectedValueException('Plugin capability '.get_class($capability).' returned an invalid value, we expected an array of Composer\Command\BaseCommand objects');
                    }
                }
                $commands = array_merge($commands, $newCommands);
            }
        }

        return $commands;
    }
}
