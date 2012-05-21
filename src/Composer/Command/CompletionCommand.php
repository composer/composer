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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Wil Moore III <wil.moore@wilmoore.com>
 */
class CompletionCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('completion')
            ->setDescription('Command Completion for Composer')
            ->setHelp(<<<EOT
<info>Command Completion for Composer:</info>
<info></info>
<info>Add the following to `\$HOME/.bashrc` or your shell's equivalent configuration file:</info>
<info>complete -W "$(php `which composer.phar` completion)" composer</info>
<info>complete -W "$(php `which composer.phar` completion)" composer.phar</info>
<info>complete -W "$(php `which composer.phar` completion)" php composer.phar</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $default  = array('help', 'list');
        $defined  = array_keys($this->getApplication()->all());
        $commands = array_diff($defined, $default);

        sort($commands);
        $output->writeln(join(' ', $commands));
    }
}
