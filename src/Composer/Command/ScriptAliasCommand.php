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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ScriptAliasCommand extends Command
{
    private $script;

    public function __construct($script)
    {
        $this->script = $script;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName($this->script)
            ->setDescription('Run the '.$this->script.' script as defined in composer.json.')
            ->setDefinition(array(
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Sets the dev mode.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables the dev mode.'),
                new InputArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, ''),
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
        $composer = $this->getComposer();

        // add the bin dir to the PATH to make local binaries of deps usable in scripts
        $binDir = $composer->getConfig()->get('bin-dir');
        if (is_dir($binDir)) {
            $_SERVER['PATH'] = realpath($binDir).PATH_SEPARATOR.getenv('PATH');
            putenv('PATH='.$_SERVER['PATH']);
        }

        $args = $input->getArguments();

        return $composer->getEventDispatcher()->dispatchScript($this->script, $input->getOption('dev') || !$input->getOption('no-dev'), $args['args']);
    }
}
