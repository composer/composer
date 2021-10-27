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

/**
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class ProhibitsCommand extends BaseDependencyCommand
{
    /**
     * Configure command metadata.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('prohibits')
            ->setAliases(array('why-not'))
            ->setDescription('Shows which packages prevent the given package from being installed.')
            ->setDefinition(array(
                new InputArgument(self::ARGUMENT_PACKAGE, InputArgument::REQUIRED, 'Package to inspect'),
                new InputArgument(self::ARGUMENT_CONSTRAINT, InputArgument::REQUIRED, 'Version constraint, which version you expected to be installed'),
                new InputOption(self::OPTION_RECURSIVE, 'r', InputOption::VALUE_NONE, 'Recursively resolves up to the root package'),
                new InputOption(self::OPTION_TREE, 't', InputOption::VALUE_NONE, 'Prints the results as a nested tree'),
            ))
            ->setHelp(
                <<<EOT
Displays detailed information about why a package cannot be installed.

<info>php composer.phar prohibits composer/composer</info>

Read more at https://getcomposer.org/doc/03-cli.md#prohibits-why-not-
EOT
            )
        ;
    }

    /**
     * Execute the function.
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return parent::doExecute($input, $output, true);
    }
}
