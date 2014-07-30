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
class ClearCacheCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('clear-cache')
            ->setAliases(array('clearcache'))
            ->setDescription('Clears composer\'s internal package cache.')
            ->setHelp(<<<EOT
The <info>clear-cache</info> deletes all cached packages from composer's
cache directory.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = Factory::createConfig();
        $io = $this->getIO();

        $cachePaths = array(
            $config->get('cache-dir'),
            $config->get('cache-files-dir'),
            $config->get('cache-repo-dir'),
            $config->get('cache-vcs-dir'),
        );

        foreach ($cachePaths as $cachePath) {
            $cachePath = realpath($cachePath);
            if (!$cachePath) {
                $io->write('<info>Cache directory does not exist.</info>');
                return;
            }
            $cache = new Cache($io, $cachePath);
            if (!$cache->isEnabled()) {
                $io->write('<info>Cache is not enabled.</info>');
                return;
            }

            $io->write('<info>Clearing cache in: '.$cachePath.'</info>');
            $cache->gc(0, 0);
        }

        $io->write('<info>Cache cleared.</info>');
    }
}
