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

use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class SearchCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('search')
            ->setDescription('Searches for packages.')
            ->setDefinition(array(
                new InputOption('only-name', 'N', InputOption::VALUE_NONE, 'Search only in package names'),
                new InputOption('only-vendor', 'O', InputOption::VALUE_NONE, 'Search only for vendor / organization names, returns only "vendor" as result'),
                new InputOption('type', 't', InputOption::VALUE_REQUIRED, 'Search for a specific package type'),
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text', ['json', 'text']),
                new InputArgument('tokens', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'tokens to search for'),
            ))
            ->setHelp(
                <<<EOT
The search command searches for packages by its name
<info>php composer.phar search symfony composer</info>

Read more at https://getcomposer.org/doc/03-cli.md#search
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // init repos
        $platformRepo = new PlatformRepository;
        $io = $this->getIO();

        $format = $input->getOption('format');
        if (!in_array($format, array('text', 'json'))) {
            $io->writeError(sprintf('Unsupported format "%s". See help for supported formats.', $format));

            return 1;
        }

        if (!($composer = $this->tryComposer())) {
            $composer = Factory::create($this->getIO(), array(), $input->hasParameterOption('--no-plugins'));
        }
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
        $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'search', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $mode = RepositoryInterface::SEARCH_FULLTEXT;
        if ($input->getOption('only-name') === true) {
            if ($input->getOption('only-vendor') === true) {
                throw new \InvalidArgumentException('--only-name and --only-vendor cannot be used together');
            }
            $mode = RepositoryInterface::SEARCH_NAME;
        } elseif ($input->getOption('only-vendor') === true) {
            $mode = RepositoryInterface::SEARCH_VENDOR;
        }

        $type = $input->getOption('type');

        $query = implode(' ', $input->getArgument('tokens'));
        if ($mode !== RepositoryInterface::SEARCH_FULLTEXT) {
            $query = preg_quote($query);
        }

        $results = $repos->search($query, $mode, $type);

        if ($results && $format === 'text') {
            $width = $this->getTerminalWidth();

            $nameLength = 0;
            foreach ($results as $result) {
                $nameLength = max(strlen($result['name']), $nameLength);
            }
            $nameLength += 1;
            foreach ($results as $result) {
                $description = $result['description'] ?? '';
                $warning = !empty($result['abandoned']) ? '<warning>! Abandoned !</warning> ' : '';
                $remaining = $width - $nameLength - strlen($warning) - 2;
                if (strlen($description) > $remaining) {
                    $description = substr($description, 0, $remaining - 3) . '...';
                }

                $link = $result['url'] ?? null;
                if ($link !== null) {
                    $io->write('<href='.OutputFormatter::escape($link).'>'.$result['name'].'</>'. str_repeat(' ', $nameLength - strlen($result['name'])) . $warning . $description);
                } else {
                    $io->write(str_pad($result['name'], $nameLength, ' ') . $warning . $description);
                }
            }
        } elseif ($format === 'json') {
            $io->write(JsonFile::encode($results));
        }

        return 0;
    }
}
