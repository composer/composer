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

use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Fabien Potencier <fabien.potencier@gmail.com>
 */
class RunScriptCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('run-script')
            ->setDescription('Run the scripts defined in composer.json.')
            ->setDefinition(array(
                new InputArgument('script', InputArgument::REQUIRED, 'Script name to run.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Sets the dev mode.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables the dev mode.'),
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
        $script = $input->getArgument('script');
        if (!in_array($script, array(
            ScriptEvents::PRE_INSTALL_CMD,
            ScriptEvents::POST_INSTALL_CMD,
            ScriptEvents::PRE_UPDATE_CMD,
            ScriptEvents::POST_UPDATE_CMD,
            ScriptEvents::PRE_STATUS_CMD,
            ScriptEvents::POST_STATUS_CMD,
        ))) {
            if (defined('Composer\Script\ScriptEvents::'.str_replace('-', '_', strtoupper($script)))) {
                throw new \InvalidArgumentException(sprintf('Script "%s" cannot be run with this command', $script));
            }

            throw new \InvalidArgumentException(sprintf('Script "%s" does not exist', $script));
        }

        $this->getComposer()->getEventDispatcher()->dispatchCommandEvent($script, $input->getOption('dev') || !$input->getOption('no-dev'));
    }
}
