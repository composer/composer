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

/**
 * @author David Neilsen <petah.p@gmail.com>
 */
class DeployCommand extends InstallCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('deploy')
            ->setDescription('Installs the project dependencies from the composer.lock (with out dev dependencies) file if present, or falls back on the composer.json.')
            ->setHelp(<<<EOT
Runs this <info>install</info> command which reads the composer.lock file from
the current directory, processes it.

<info>composer deploy</info> is identical to <info>composer install --no-dev</info>.

EOT
        );
    }

}
