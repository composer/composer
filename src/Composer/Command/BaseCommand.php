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

use Composer\Composer;
use Composer\Config;
use Composer\Console\Application;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Util\Platform;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Terminal;

/**
 * Base class for Composer commands
 *
 * @method Application getApplication()
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
     * @param  bool              $required
     * @param  bool|null         $disablePlugins
     * @param  bool|null         $disableScripts
     * @throws \RuntimeException
     * @return Composer|null
     */
    public function getComposer($required = true, $disablePlugins = null, $disableScripts = null)
    {
        if (null === $this->composer) {
            $application = $this->getApplication();
            if ($application instanceof Application) {
                /* @var $application    Application */
                $this->composer = $application->getComposer($required, $disablePlugins, $disableScripts);
            /** @phpstan-ignore-next-line */
            } elseif ($required) {
                throw new \RuntimeException(
                    'Could not create a Composer\Composer instance, you must inject '.
                    'one if this command is not used with a Composer\Console\Application instance'
                );
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
            $application = $this->getApplication();
            if ($application instanceof Application) {
                $this->io = $application->getIO();
            /** @phpstan-ignore-next-line */
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
     * @inheritDoc
     *
     * @return void
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // initialize a plugin-enabled Composer instance, either local or global
        $disablePlugins = $input->hasParameterOption('--no-plugins');
        $disableScripts = $input->hasParameterOption('--no-scripts');
        if ($this instanceof SelfUpdateCommand) {
            $disablePlugins = true;
            $disableScripts = true;
        }

        $composer = $this->getComposer(false, $disablePlugins, $disableScripts);
        if (null === $composer) {
            $composer = Factory::createGlobal($this->getIO(), $disablePlugins, $disableScripts);
        }
        if ($composer) {
            $preCommandRunEvent = new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, $this->getName());
            $composer->getEventDispatcher()->dispatch($preCommandRunEvent->getName(), $preCommandRunEvent);
        }

        if (true === $input->hasParameterOption(array('--no-ansi')) && $input->hasOption('no-progress')) {
            $input->setOption('no-progress', true);
        }

        if (true == $input->hasOption('no-dev')) {
            if (!$input->getOption('no-dev') && true == Platform::getEnv('COMPOSER_NO_DEV')) {
                $input->setOption('no-dev', true);
            }
        }
        if (true == $input->hasOption('update-no-dev')) {
            if (true !== $input->getOption('update-no-dev') && true == Platform::getEnv('COMPOSER_NO_DEV')) {
                $input->setOption('update-no-dev', true);
            }
        }

        parent::initialize($input, $output);
    }

    /**
     * Returns preferSource and preferDist values based on the configuration.
     *
     * @param bool           $keepVcsRequiresPreferSource
     *
     * @return bool[] An array composed of the preferSource and preferDist values
     */
    protected function getPreferredInstallOptions(Config $config, InputInterface $input, $keepVcsRequiresPreferSource = false)
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

        if ($input->hasOption('prefer-install') && $input->getOption('prefer-install')) {
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
            $preferDist = (bool) $input->getOption('prefer-dist');
        }

        return array($preferSource, $preferDist);
    }

    /**
     * @param array<string, string> $requirements
     *
     * @return array<string, string>
     */
    protected function formatRequirements(array $requirements)
    {
        $requires = array();
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
        $rendererStyle = $renderer->getStyle();
        if (method_exists($rendererStyle, 'setVerticalBorderChars')) {
            $rendererStyle->setVerticalBorderChars('');
        } else {
            // TODO remove in composer 2.2
            // @phpstan-ignore-next-line
            $rendererStyle->setVerticalBorderChar('');
        }
        $rendererStyle->setCellRowContentFormat('%s  ');
        $renderer->setRows($table)->render();
    }

    /**
     * @return int
     */
    protected function getTerminalWidth()
    {
        if (class_exists('Symfony\Component\Console\Terminal')) {
            $terminal = new Terminal();
            $width = $terminal->getWidth();
        } else {
            // For versions of Symfony console before 3.2
            // TODO remove in composer 2.2
            // @phpstan-ignore-next-line
            list($width) = $this->getApplication()->getTerminalDimensions();
        }
        if (null === $width) {
            // In case the width is not detected, we're probably running the command
            // outside of a real terminal, use space without a limit
            $width = PHP_INT_MAX;
        }
        if (Platform::isWindows()) {
            $width--;
        } else {
            $width = max(80, $width);
        }

        return $width;
    }
}
