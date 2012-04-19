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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Composer\Compiler\ProjectCompiler;
use Composer\Command\Command;

/**
 * Install a package as new project into new directory.
 *
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class PackageCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('package')
            ->setDescription('Creates a PHAR package of your whole project')
            ->setDefinition(array(
            new InputArgument('name', InputArgument::REQUIRED, 'the phar name'),
            new InputArgument('stub', InputArgument::REQUIRED, 'the stub to use'),
            new InputArgument('version', InputArgument::OPTIONAL, 'version, will defaults vcs version'),
            new InputArgument('path', InputArgument::OPTIONAL, 'the base path', getcwd()),
            new InputOption('archive', null, InputOption::VALUE_REQUIRED, 'additionally create a zip or tar'),
        ))
            ->setHelp(<<<EOT
The <info>package</info> command creates a PHAR package of your project.
the <info>stub</info> parameter should point to a valid stub file, e.g.
<comment>
Phar::mapPhar('composer.phar');

require 'phar://composer.phar/bin/composer';

__HALT_COMPILER();

</comment>
how to use this command:

<info>php composer.phar package my.phar path/to/stub.php</info>

alternativly you can pass a <info>version</info> and create a <info>zip</info> or <info>tar</info> along the <info>phar</info>.

EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $compiler = new ProjectCompiler($input->getArgument('path'), $input->getArgument('stub'));

        $compiler
            ->setArchiveType($input->getOption('archive'))
            ->setIO($input->getOption('verbose') ? $this->getIO() : null)
            ->setVersion($input->getOption('version'))
            ->compile($input->getArgument('name'));
    }
}