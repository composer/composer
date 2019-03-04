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
class ProhibitsCommand extends BaseDependencyCommand
{
    /**
     * Configure command metadata.
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('prohibits')
            ->setAliases(array('why-not'))
            ->setDescription('Shows which packages prevent the given package from being installed.')
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
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return parent::doExecute($input, $output, true);
    }
}
