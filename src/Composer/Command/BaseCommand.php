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
use Composer\Plugin\PluginEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

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
     * @param  bool              $required
     * @param  bool|null         $disablePlugins
     * @throws \RuntimeException
     * @return Composer
     */
    public function getComposer($required = true, $disablePlugins = null)
    {
        if (null === $this->composer) {
            $application = $this->getApplication();
            if ($application instanceof Application) {
                /* @var $application    Application */
                $this->composer = $application->getComposer($required, $disablePlugins);
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
     * @param Composer $composer
     */
    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * Removes the cached composer instance
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
                /* @var $application    Application */
                $this->io = $application->getIO();
            } else {
                $this->io = new NullIO();
            }
        }

        return $this->io;
    }

    /**
     * @param IOInterface $io
     */
    public function setIO(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // initialize a plugin-enabled Composer instance, either local or global
        $disablePlugins = $input->hasParameterOption('--no-plugins');
        $composer = $this->getComposer(false, $disablePlugins);
        if (null === $composer) {
            $composer = Factory::createGlobal($this->getIO(), $disablePlugins);
        }
        if ($composer) {
            $preCommandRunEvent = new PreCommandRunEvent(PluginEvents::PRE_COMMAND_RUN, $input, $this->getName());
            $composer->getEventDispatcher()->dispatch($preCommandRunEvent->getName(), $preCommandRunEvent);
        }

        if (true === $input->hasParameterOption(array('--no-ansi')) && $input->hasOption('no-progress')) {
            $input->setOption('no-progress', true);
        }

        parent::initialize($input, $output);
    }

    /**
     * Returns preferSource and preferDist values based on the configuration.
     *
     * @param Config         $config
     * @param InputInterface $input
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

        if ($input->getOption('prefer-source') || $input->getOption('prefer-dist') || ($keepVcsRequiresPreferSource && $input->hasOption('keep-vcs') && $input->getOption('keep-vcs'))) {
            $preferSource = $input->getOption('prefer-source') || ($keepVcsRequiresPreferSource && $input->hasOption('keep-vcs') && $input->getOption('keep-vcs'));
            $preferDist = $input->getOption('prefer-dist');
        }

        return array($preferSource, $preferDist);
    }
}
