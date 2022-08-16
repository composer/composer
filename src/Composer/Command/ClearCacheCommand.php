<?php declare(strict_types=1);

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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author David Neilsen <petah.p@gmail.com>
 */
class ClearCacheCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('clear-cache')
            ->setAliases(array('clearcache', 'cc'))
            ->setDescription('Clears composer\'s internal package cache')
            ->setDefinition(array(
                new InputOption('gc', null, InputOption::VALUE_NONE, 'Only run garbage collection, not a full cache clear'),
            ))
            ->setHelp(
                <<<EOT
The <info>clear-cache</info> deletes all cached packages from composer's
cache directory.

Read more at https://getcomposer.org/doc/03-cli.md#clear-cache-clearcache-cc
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
            // only individual dirs get garbage collected
            if ($key === 'cache-dir' && $input->getOption('gc')) {
                continue;
            }

            $cachePath = realpath($cachePath);
            if (!$cachePath) {
                $io->writeError("<info>Cache directory does not exist ($key): $cachePath</info>");

                continue;
            }
            $cache = new Cache($io, $cachePath);
            $cache->setReadOnly($config->get('cache-read-only'));
            if (!$cache->isEnabled()) {
                $io->writeError("<info>Cache is not enabled ($key): $cachePath</info>");

                continue;
            }

            if ($input->getOption('gc')) {
                $io->writeError("<info>Garbage-collecting cache ($key): $cachePath</info>");
                if ($key === 'cache-files-dir') {
                    $cache->gc($config->get('cache-files-ttl'), $config->get('cache-files-maxsize'));
                } elseif ($key === 'cache-repo-dir') {
                    $cache->gc($config->get('cache-ttl'), 1024*1024*1024 /* 1GB, this should almost never clear anything that is not outdated */);
                } elseif ($key === 'cache-vcs-dir') {
                    $cache->gcVcsCache($config->get('cache-ttl'));
                }
            } else {
                $io->writeError("<info>Clearing cache ($key): $cachePath</info>");
                $cache->clear();
            }
        }

        if ($input->getOption('gc')) {
            $io->writeError('<info>All caches garbage-collected.</info>');
        } else {
            $io->writeError('<info>All caches cleared.</info>');
        }

        return 0;
    }
}
