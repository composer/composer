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

/**
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class DependsCommand extends BaseDependencyCommand
{
    /**
     * Configure command metadata.
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('depends')
            ->setAliases(array('why'))
            ->setDescription('Shows which packages cause the given package to be installed')
            ->setHelp(<<<EOT
Displays detailed information about where a package is referenced.

<info>php composer.phar depends composer/composer</info>

EOT
            )
        ;
    }

    /**
     * Execute the function.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return parent::doExecute($input, $output, false);
    }
}
