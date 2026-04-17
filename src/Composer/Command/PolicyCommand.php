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

use Composer\Factory;
use Composer\FilterList\Source\SourceValidator;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Policy\PolicyConfig;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manage policy lists and their sources.
 *
 * Examples:
 *  - composer policy add-source my-list url https://example.org/list.json
 *  - composer policy add-source my-list '{"type":"url","url":"https://example.org/list.json"}'
 */
class PolicyCommand extends BaseConfigCommand
{
    protected function configure(): void
    {
        $this
            ->setName('policy')
            ->setDescription('Manages policy lists and their sources')
            ->setDefinition([
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Apply command to the global config file'),
                new InputOption('file', 'f', InputOption::VALUE_REQUIRED, 'If you want to choose a different composer.json or config.json'),
                new InputArgument('action', InputArgument::REQUIRED, 'Action to perform: add-source', null, ['add-source']),
                new InputArgument('name', InputArgument::OPTIONAL, 'Policy list name', null, $this->suggestListNames()),
                new InputArgument('arg1', InputArgument::OPTIONAL, 'Source type (e.g. "url") for add-source, or source URL for remove-source', null, $this->suggestArg1()),
                new InputArgument('arg2', InputArgument::OPTIONAL, 'URL for add-source (if not using JSON)'),
            ])
            ->setHelp(
                <<<EOT
This command lets you manage policy lists and their sources in composer.json.

Examples:
  composer policy add-source my-list url https://example.org/list.json
  composer policy add-source my-list '{"type":"url","url":"https://example.org/list.json"}'

Adding a source to a list that does not exist will create the list.

Built-in lists (advisories, malware, abandoned) do not accept sources and
are rejected by add-source / remove-source. Use `composer config policy.<list>.<field>`
to adjust their settings.

Use --global/-g to alter the global config.json instead.
Use --file to alter a specific file.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $listName = $input->getArgument('name');
        $arg1 = $input->getArgument('arg1');
        $arg2 = $input->getArgument('arg2');

        switch ($action) {
            case 'add-source':
                if ($listName === null) {
                    throw new \RuntimeException('You must pass a list name. Example: composer policy add-source my-list url https://example.org');
                }
                $this->assertCustomListName((string) $listName);
                if ($arg1 === null) {
                    throw new \RuntimeException('You must pass the source type and a url, or a JSON string.');
                }

                if (is_string($arg1) && Preg::isMatch('{^\s*\{}', $arg1)) {
                    $sourceConfig = JsonFile::parseJson($arg1);
                    if (!is_array($sourceConfig)) {
                        throw new \RuntimeException('Source JSON must be an object.');
                    }
                } else {
                    if ($arg2 === null) {
                        throw new \RuntimeException('You must pass the source type and a url. Example: composer policy add-source my-list url https://example.org');
                    }
                    $sourceConfig = ['type' => (string) $arg1, 'url' => (string) $arg2];
                }

                $this->validateSourceConfig($listName, $sourceConfig);

                $data = $this->configFile->read();
                $currentSources = $data['config']['policy'][$listName]['sources'] ?? [];
                if (!is_array($currentSources)) {
                    $currentSources = [];
                }

                foreach ($currentSources as $existing) {
                    if (is_array($existing) && ($existing['url'] ?? null) === $sourceConfig['url']) {
                        $this->getIO()->write('<info>Source '.$sourceConfig['url'].' already present in list '.$listName.'</info>');

                        return 0;
                    }
                }

                $currentSources[] = $sourceConfig;
                $this->configSource->addConfigSetting('policy.'.$listName.'.sources', $currentSources);

                return 0;

            default:
                throw new \InvalidArgumentException('Unknown action "'.$action.'". Use list, add-source, or remove-source.');
        }
    }

    private function assertCustomListName(string $name): void
    {
        if (in_array($name, PolicyConfig::BUILTIN_LIST_NAMES, true)) {
            throw new \RuntimeException('Built-in list "'.$name.'" does not support sources. Use `composer config policy.'.$name.'.<field>` to configure it.');
        }

        foreach (PolicyConfig::FUTURE_RESERVED_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                throw new \RuntimeException('"'.$name.'" starts with reserved prefix "'.$prefix.'".');
            }
        }

        if (in_array($name, PolicyConfig::FUTURE_RESERVED_NAMES, true)) {
            throw new \RuntimeException('"'.$name.'" is reserved for future use.');
        }

        if ($name === '' || strpos($name, '.') !== false) {
            throw new \RuntimeException('Invalid list name "'.$name.'".');
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function validateSourceConfig(string $listName, array $source): void
    {
        $sourceValidator = new SourceValidator();
        $sourceValidator->validate($listName, $source);
    }

    private function suggestListNames(): \Closure
    {
        return function (CompletionInput $input): array {
            $action = $input->getArgument('action');
            if (!in_array($action, ['add-source', 'remove-source'], true)) {
                return [];
            }

            $config = Factory::createConfig();
            $configFile = new JsonFile($this->getComposerConfigFile($input, $config));
            if (!$configFile->exists()) {
                return [];
            }

            $data = $configFile->read();
            $policy = $data['config']['policy'] ?? [];
            if (!is_array($policy)) {
                return [];
            }

            $names = [];
            foreach ($policy as $listName => $_) {
                if (in_array($listName, PolicyConfig::BUILTIN_LIST_NAMES, true) || in_array($listName, PolicyConfig::NON_LIST_KEYS, true)) {
                    continue;
                }
                $names[] = (string) $listName;
            }
            sort($names);

            return $names;
        };
    }

    private function suggestArg1(): \Closure
    {
        return static function (CompletionInput $input): array {
            if ($input->getArgument('action') === 'add-source') {
                return ['url'];
            }

            return [];
        };
    }
}
