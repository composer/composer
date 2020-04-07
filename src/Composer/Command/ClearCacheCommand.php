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

use Composer\Cache;
use Composer\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author David Neilsen <petah.p@gmail.com>
 */
class ClearCacheCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('clear-cache')
            ->setAliases(array('clearcache', 'cc'))
            ->setDescription('Clears composer\'s internal package cache.')
            ->setHelp(
                <<<EOT
The <info>clear-cache</info> deletes all cached packages from composer's
cache directory.

Read more at https://getcomposer.org/doc/03-cli.md#clear-cache-clearcache-cc
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = Factory::createConfig();
        $io = $this->getIO();

        $cachePaths = array(
            'cache-vcs-dir' => $config->get('cache-vcs-dir'),
            'cache-repo-dir' => $config->get('cache-repo-dir'),
            'cache-files-dir' => $config->get('cache-files-dir'),
            'cache-dir' => $config->get('cache-dir'),
        );

        foreach ($cachePaths as $key => $cachePath) {
            $cachePath = realpath($cachePath);
            if (!$cachePath) {
                $io->writeError("<info>Cache directory does not exist ($key): $cachePath</info>");

                continue;
            }
            $cache = new Cache($io, $cachePath);
            if (!$cache->isEnabled()) {
                $io->writeError("<info>Cache is not enabled ($key): $cachePath</info>");

                continue;
            }

            $io->writeError("<info>Clearing cache ($key): $cachePath</info>");
            $cache->clear();
        }

        $io->writeError('<info>All caches cleared.</info>');

        return 0;
    }
}
