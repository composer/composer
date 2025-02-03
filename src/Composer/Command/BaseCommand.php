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

use Composer\Composer;
use Composer\Config;
use Composer\Console\Application;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Composer\Factory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Advisory\Auditor;
use Composer\Util\Platform;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Terminal;

/**
 * Base class for Composer commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class BaseCommand extends Command
{
    /**
     * @var Composer|null
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * Gets the application instance for this command.
     */
    public function getApplication(): Application
    {
        $application = parent::getApplication();
        if (!$application instanceof Application) {
            throw new \RuntimeException('Composer commands can only work with an '.Application::class.' instance set');
        }

        return $application;
    }

    /**
     * @param  bool              $required       Should be set to false, or use `requireComposer` instead
     * @param  bool|null         $disablePlugins If null, reads --no-plugins as default
     * @param  bool|null         $disableScripts If null, reads --no-scripts as default
     * @throws \RuntimeException
     * @return Composer|null
     * @deprecated since Composer 2.3.0 use requireComposer or tryComposer depending on whether you have $required set to true or false
     */
    public function getComposer(bool $required = true, ?bool $disablePlugins = null, ?bool $disableScripts = null)
    {
        if ($required) {
            return $this->requireComposer($disablePlugins, $disableScripts);
        }

        return $this->tryComposer($disablePlugins, $disableScripts);
    }

    /**
     * Retrieves the default Composer\Composer instance or throws
     *
     * Use this instead of getComposer if you absolutely need an instance
     *
     * @param bool|null $disablePlugins If null, reads --no-plugins as default
     * @param bool|null $disableScripts If null, reads --no-scripts as default
     * @throws \RuntimeException
     */
    public function requireComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): Composer
    {
        if (null === $this->composer) {
            $application = parent::getApplication();
            if ($application instanceof Application) {
                $this->composer = $application->getComposer(true, $disablePlugins, $disableScripts);
                assert($this->composer instanceof Composer);
            } else {
                throw new \RuntimeException(
                    'Could not create a Composer\Composer instance, you must inject '.
                    'one if this command is not used with a Composer\Console\Application instance'
                );
            }
        }

        return $this->composer;
    }

    /**
     * Retrieves the default Composer\Composer instance or null
     *
     * Use this instead of getComposer(false)
     *
     * @param bool|null $disablePlugins If null, reads --no-plugins as default
     * @param bool|null $disableScripts If null, reads --no-scripts as default
     */
    public function tryComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
    {
        if (null === $this->composer) {
            $application = parent::getApplication();
            if ($application instanceof Application) {
                $this->composer = $application->getComposer(false, $disablePlugins, $disableScripts);
            }
        }

        return $this->composer;
    }

    /**
     * @return void
     */
    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * Removes the cached composer instance
     *
     * @return void
     */
    public function resetComposer()
    {
        $this->composer = null;
        $this->getApplication()->resetComposer();
    }

    /**
     * Whether or not this command is meant to call another command.
     *
     * This is mainly needed to avoid duplicated warnings messages.
     *
     * @return bool
     */
    public function isProxyCommand()
    {
        return false;
    }

    /**
     * @return IOInterface
     */
    public function getIO()
    {
        if (null === $this->io) {
            $application = parent::getApplication();
            if ($application instanceof Application) {
                $this->io = $application->getIO();
            } else {
                $this->io = new NullIO();
            }
        }

        return $this->io;
    }

    /**
     * @return void
     */
    public function setIO(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * @inheritdoc
     *
     * Backport suggested values definition from symfony/console 6.1+
     *
     * TODO drop when PHP 8.1 / symfony 6.1+ can be required
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $definition = $this->getDefinition();
        $name = (string) $input->getCompletionName();
        if (CompletionInput::TYPE_OPTION_VALUE === $input->getCompletionType()
            && $definition->hasOption($name)
            && ($option = $definition->getOption($name)) instanceof InputOption
        ) {
            $option->complete($input, $suggestions);
        } elseif (CompletionInput::TYPE_ARGUMENT_VALUE === $input->getCompletionType()
            && $definition->hasArgument($name)
            && ($argument = $definition->getArgument($name)) instanceof InputArgument
        ) {
            $argument->complete($input, $suggestions);
        } else {
            parent::complete($input, $suggestions);
        }
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // initialize a plugin-enabled Composer instance, either local or global
        $disablePlugins = $input->hasParameterOption('--no-plugins');
        $disableScripts = $input->hasParameterOption('--no-scripts');

        $application = parent::getApplication();
        if ($application instanceof Application && $application->getDisablePluginsByDefault()) {
            $disablePlugins = true;
        }
        if ($application instanceof Application && $application->getDisableScriptsByDefault()) {
            $disableScripts = true;
        }

        if ($this instanceof SelfUpdateCommand) {
            $disablePlugins = true;
            $disableScripts = true;
        }

        $composer = $this->tryComposer($disablePlugins, $disableScripts);
        $io = $this->getIO();

        if (null === $composer) {
            $composer = Factory::createGlobal($this->getIO(), $disablePlugins, $disableScripts);
        }
        if ($composer) {
            $preCommandRunEvent = new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, $this->getName());
            $composer->getEventDispatcher()->dispatch($preCommandRunEvent->getName(), $preCommandRunEvent);
        }

        if (true === $input->hasParameterOption(['--no-ansi']) && $input->hasOption('no-progress')) {
            $input->setOption('no-progress', true);
        }

        $envOptions = [
            'COMPOSER_NO_AUDIT' => ['no-audit'],
            'COMPOSER_NO_DEV' => ['no-dev', 'update-no-dev'],
            'COMPOSER_PREFER_STABLE' => ['prefer-stable'],
            'COMPOSER_PREFER_LOWEST' => ['prefer-lowest'],
            'COMPOSER_MINIMAL_CHANGES' => ['minimal-changes'],
            'COMPOSER_WITH_DEPENDENCIES' => ['with-dependencies'],
            'COMPOSER_WITH_ALL_DEPENDENCIES' => ['with-all-dependencies'],
        ];
        foreach ($envOptions as $envName => $optionNames) {
            foreach ($optionNames as $optionName) {
                if (true === $input->hasOption($optionName)) {
                    if (false === $input->getOption($optionName) && (bool) Platform::getEnv($envName)) {
                        $input->setOption($optionName, true);
                    }
                }
            }
        }

        if (true === $input->hasOption('ignore-platform-reqs')) {
            if (!$input->getOption('ignore-platform-reqs') && (bool) Platform::getEnv('COMPOSER_IGNORE_PLATFORM_REQS')) {
                $input->setOption('ignore-platform-reqs', true);

                $io->writeError('<warning>COMPOSER_IGNORE_PLATFORM_REQS is set. You may experience unexpected errors.</warning>');
            }
        }

        if (true === $input->hasOption('ignore-platform-req') && (!$input->hasOption('ignore-platform-reqs') || !$input->getOption('ignore-platform-reqs'))) {
            $ignorePlatformReqEnv = Platform::getEnv('COMPOSER_IGNORE_PLATFORM_REQ');
            if (0 === count($input->getOption('ignore-platform-req')) && is_string($ignorePlatformReqEnv) && '' !== $ignorePlatformReqEnv) {
                $input->setOption('ignore-platform-req', explode(',', $ignorePlatformReqEnv));

                $io->writeError('<warning>COMPOSER_IGNORE_PLATFORM_REQ is set to ignore '.$ignorePlatformReqEnv.'. You may experience unexpected errors.</warning>');
            }
        }

        parent::initialize($input, $output);
    }

    /**
     * Calls {@see Factory::create()} with the given arguments, taking into account flags and default states for disabling scripts and plugins
     *
     * @param  mixed    $config either a configuration array or a filename to read from, if null it will read from
     *                          the default filename
     * @return Composer
     */
    protected function createComposerInstance(InputInterface $input, IOInterface $io, $config = null, ?bool $disablePlugins = null, ?bool $disableScripts = null): Composer
    {
        $disablePlugins = $disablePlugins === true || $input->hasParameterOption('--no-plugins');
        $disableScripts = $disableScripts === true || $input->hasParameterOption('--no-scripts');

        $application = parent::getApplication();
        if ($application instanceof Application && $application->getDisablePluginsByDefault()) {
            $disablePlugins = true;
        }
        if ($application instanceof Application && $application->getDisableScriptsByDefault()) {
            $disableScripts = true;
        }

        return Factory::create($io, $config, $disablePlugins, $disableScripts);
    }

    /**
     * Returns preferSource and preferDist values based on the configuration.
     *
     * @return bool[] An array composed of the preferSource and preferDist values
     */
    protected function getPreferredInstallOptions(Config $config, InputInterface $input, bool $keepVcsRequiresPreferSource = false)
    {
        $preferSource = false;
        $preferDist = false;

        switch ($config->get('preferred-install')) {
            case 'source':
                $preferSource = true;
                break;
            case 'dist':
                $preferDist = true;
                break;
            case 'auto':
            default:
                // noop
                break;
        }

        if (!$input->hasOption('prefer-dist') || !$input->hasOption('prefer-source')) {
            return [$preferSource, $preferDist];
        }

        if ($input->hasOption('prefer-install') && is_string($input->getOption('prefer-install'))) {
            if ($input->getOption('prefer-source')) {
                throw new \InvalidArgumentException('--prefer-source can not be used together with --prefer-install');
            }
            if ($input->getOption('prefer-dist')) {
                throw new \InvalidArgumentException('--prefer-dist can not be used together with --prefer-install');
            }
            switch ($input->getOption('prefer-install')) {
                case 'dist':
                    $input->setOption('prefer-dist', true);
                    break;
                case 'source':
                    $input->setOption('prefer-source', true);
                    break;
                case 'auto':
                    $preferDist = false;
                    $preferSource = false;
                    break;
                default:
                    throw new \UnexpectedValueException('--prefer-install accepts one of "dist", "source" or "auto", got '.$input->getOption('prefer-install'));
            }
        }

        if ($input->getOption('prefer-source') || $input->getOption('prefer-dist') || ($keepVcsRequiresPreferSource && $input->hasOption('keep-vcs') && $input->getOption('keep-vcs'))) {
            $preferSource = $input->getOption('prefer-source') || ($keepVcsRequiresPreferSource && $input->hasOption('keep-vcs') && $input->getOption('keep-vcs'));
            $preferDist = $input->getOption('prefer-dist');
        }

        return [$preferSource, $preferDist];
    }

    protected function getPlatformRequirementFilter(InputInterface $input): PlatformRequirementFilterInterface
    {
        if (!$input->hasOption('ignore-platform-reqs') || !$input->hasOption('ignore-platform-req')) {
            throw new \LogicException('Calling getPlatformRequirementFilter from a command which does not define the --ignore-platform-req[s] flags is not permitted.');
        }

        if (true === $input->getOption('ignore-platform-reqs')) {
            return PlatformRequirementFilterFactory::ignoreAll();
        }

        $ignores = $input->getOption('ignore-platform-req');
        if (count($ignores) > 0) {
            return PlatformRequirementFilterFactory::fromBoolOrList($ignores);
        }

        return PlatformRequirementFilterFactory::ignoreNothing();
    }

    /**
     * @param array<string> $requirements
     *
     * @return array<string, string>
     */
    protected function formatRequirements(array $requirements)
    {
        $requires = [];
        $requirements = $this->normalizeRequirements($requirements);
        foreach ($requirements as $requirement) {
            if (!isset($requirement['version'])) {
                throw new \UnexpectedValueException('Option '.$requirement['name'] .' is missing a version constraint, use e.g. '.$requirement['name'].':^1.0');
            }
            $requires[$requirement['name']] = $requirement['version'];
        }

        return $requires;
    }

    /**
     * @param array<string> $requirements
     *
     * @return list<array{name: string, version?: string}>
     */
    protected function normalizeRequirements(array $requirements)
    {
        $parser = new VersionParser();

        return $parser->parseNameVersionPairs($requirements);
    }

    /**
     * @param array<TableSeparator|mixed[]> $table
     *
     * @return void
     */
    protected function renderTable(array $table, OutputInterface $output)
    {
        $renderer = new Table($output);
        $renderer->setStyle('compact');
        $renderer->setRows($table)->render();
    }

    /**
     * @return int
     */
    protected function getTerminalWidth()
    {
        $terminal = new Terminal();
        $width = $terminal->getWidth();

        if (Platform::isWindows()) {
            $width--;
        } else {
            $width = max(80, $width);
        }

        return $width;
    }

    /**
     * @internal
     * @param 'format'|'audit-format' $optName
     * @return Auditor::FORMAT_*
     */
    protected function getAuditFormat(InputInterface $input, string $optName = 'audit-format'): string
    {
        if (!$input->hasOption($optName)) {
            throw new \LogicException('This should not be called on a Command which has no '.$optName.' option defined.');
        }

        $val = $input->getOption($optName);
        if (!in_array($val, Auditor::FORMATS, true)) {
            throw new \InvalidArgumentException('--'.$optName.' must be one of '.implode(', ', Auditor::FORMATS).'.');
        }

        return $val;
    }
}
