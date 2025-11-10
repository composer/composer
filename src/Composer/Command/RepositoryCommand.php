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
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manage repositories
 *
 * Examples:
 *  - composer repo list
 *  - composer repo add foo vcs https://github.com/acme/foo
 *  - composer repo remove foo
 *  - composer repo set-url foo https://git.example.org/acme/foo
 *  - composer repo get-url foo
 *  - composer repo disable packagist
 *  - composer repo enable packagist
 */
class RepositoryCommand extends BaseConfigCommand
{
    protected function configure(): void
    {
        $this
            ->setName('repository')
            ->setAliases(['repo'])
            ->setDescription('Manages repositories')
            ->setDefinition([
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Apply command to the global config file'),
                new InputOption('file', 'f', InputOption::VALUE_REQUIRED, 'If you want to choose a different composer.json or config.json'),
                new InputOption('append', null, InputOption::VALUE_NONE, 'When adding a repository, append it (lower priority) instead of prepending it'),
                new InputOption('before', null, InputOption::VALUE_REQUIRED, 'When adding a repository, insert it before the given repository name', null, $this->suggestRepoNames()),
                new InputOption('after', null, InputOption::VALUE_REQUIRED, 'When adding a repository, insert it after the given repository name', null, $this->suggestRepoNames()),
                new InputArgument('action', InputArgument::OPTIONAL, 'Action to perform: list, add, remove, set-url, get-url, enable, disable', 'list', ['list', 'add', 'remove', 'set-url', 'get-url', 'enable', 'disable']),
                new InputArgument('name', InputArgument::OPTIONAL, 'Repository name (or special name packagist / packagist.org for enable/disable)', null, $this->suggestRepoNames()),
                new InputArgument('arg1', InputArgument::OPTIONAL, 'Type for add, or new URL for set-url, or JSON config for add', null, $this->suggestTypeForAdd()),
                new InputArgument('arg2', InputArgument::OPTIONAL, 'URL for add (if not using JSON)'),
            ])
            ->setHelp(<<<EOT
This command lets you manage repositories in your composer.json.

Examples:
  composer repo list
  composer repo add foo vcs https://github.com/acme/foo
  composer repo add bar '{"type":"composer","url":"https://repo.example.org"}'
  composer repo add baz vcs https://example.org --before foo
  composer repo add qux vcs https://example.org --after bar
  composer repo remove foo
  composer repo set-url foo https://git.example.org/acme/foo
  composer repo get-url foo
  composer repo disable packagist
  composer repo enable packagist

Use --global/-g to alter the global config.json instead.
Use --file to alter a specific file.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $name = $input->getArgument('name');
        $arg1 = $input->getArgument('arg1');
        $arg2 = $input->getArgument('arg2');

        $this->config->merge($this->configFile->read(), $this->configFile->getPath());
        $repos = $this->config->getRepositories();

        switch ($action) {
            case 'list':
            case 'ls':
            case 'show':
                $this->listRepositories($repos);

                return 0;

            case 'add':
                if ($name === null) {
                    throw new \RuntimeException('You must pass a repository name. Example: composer repo add foo vcs https://example.org');
                }
                if ($arg1 === null) {
                    throw new \RuntimeException('You must pass the type and a url, or a JSON string.');
                }
                if (is_string($arg1) && Preg::isMatch('{^\s*\{}', $arg1)) {
                    // JSON config
                    $repoConfig = JsonFile::parseJson($arg1);
                } else {
                    if ($arg2 === null) {
                        throw new \RuntimeException('You must pass the type and a url. Example: composer repo add foo vcs https://example.org');
                    }
                    $repoConfig = ['type' => (string) $arg1, 'url' => (string) $arg2];
                }

                // ordering options
                $before = $input->getOption('before');
                $after = $input->getOption('after');
                if ($before !== null && $after !== null) {
                    throw new \RuntimeException('You can not combine --before and --after');
                }

                if ($before !== null || $after !== null) {
                    if ($repoConfig === false) {
                        throw new \RuntimeException('Cannot use --before/--after with boolean repository values');
                    }

                    $this->configSource->insertRepository((string) $name, $repoConfig, $before ?? $after, $after !== null ? 1 : 0);

                    return 0;
                }

                $this->configSource->addRepository((string) $name, $repoConfig, (bool) $input->getOption('append'));

                return 0;

            case 'remove':
            case 'rm':
            case 'delete':
                if ($name === null) {
                    throw new \RuntimeException('You must pass the repository name to remove.');
                }
                $this->configSource->removeRepository((string) $name);
                if (in_array($name, ['packagist', 'packagist.org'], true)) {
                    $this->configSource->addRepository('packagist.org', false);
                }

                return 0;

            case 'set-url':
            case 'seturl':
                if ($name === null || $arg1 === null) {
                    throw new \RuntimeException('Usage: composer repo set-url <name> <new-url>');
                }

                $this->configSource->setRepositoryUrl($name, $arg1);

                return 0;

            case 'get-url':
            case 'geturl':
                if ($name === null) {
                    throw new \RuntimeException('Usage: composer repo get-url <name>');
                }
                if (isset($repos[$name]) && is_array($repos[$name])) {
                    $url = $repos[$name]['url'] ?? null;
                    if (!is_string($url)) {
                        throw new \InvalidArgumentException('The '.$name.' repository does not have a URL');
                    }
                    $this->getIO()->write($url);

                    return 0;
                }
                // try named-list: find entry with matching name
                if (is_array($repos)) {
                    foreach ($repos as $val) {
                        if (is_array($val) && isset($val['name']) && $val['name'] === $name) {
                            $url = $val['url'] ?? null;
                            if (!is_string($url)) {
                                throw new \InvalidArgumentException('The '.$name.' repository does not have a URL');
                            }
                            $this->getIO()->write($url);

                            return 0;
                        }
                    }
                }

                throw new \InvalidArgumentException('There is no '.$name.' repository defined');

            case 'disable':
                if ($name === null) {
                    throw new \RuntimeException('Usage: composer repo disable packagist');
                }
                if (in_array($name, ['packagist', 'packagist.org'], true)) {
                    // special handling mirrors ConfigCommand behavior
                    $this->configSource->addRepository('packagist.org', false, (bool) $input->getOption('append'));

                    return 0;
                }
                throw new \RuntimeException('Only packagist can be enabled/disabled using this command. Use add/remove for other repositories.');

            case 'enable':
                if ($name === null) {
                    throw new \RuntimeException('Usage: composer repo enable packagist');
                }
                if (in_array($name, ['packagist', 'packagist.org'], true)) {
                    // Remove a false flag by setting packagist.org to true via removing the key
                    // Here we re-add the default by removing overrides
                    $this->configSource->removeRepository('packagist.org');

                    return 0;
                }
                throw new \RuntimeException('Only packagist can be enabled/disabled using this command.');

            default:
                throw new \InvalidArgumentException('Unknown action "'.$action.'". Use list, add, remove, set-url, get-url, enable, disable');
        }
    }

    /**
     * @param array<int|string, mixed> $repos
     */
    private function listRepositories(array $repos): void
    {
        $io = $this->getIO();

        $packagistPresent = false;
        foreach ($repos as $key => $repo) {
            if (isset($repo['type'], $repo['url']) && $repo['type'] === 'composer' && str_ends_with((string) parse_url($repo['url'], PHP_URL_HOST), 'packagist.org')) {
                $packagistPresent = true;
                break;
            }
        }
        if (!$packagistPresent) {
            $repos[] = ['packagist.org' => false];
        }

        if ($repos === []) {
            $io->write('No repositories configured');

            return;
        }

        foreach ($repos as $key => $repo) {
            if ($repo === false) {
                $io->write('['.$key.'] <info>disabled</info>');
                continue;
            }

            if (is_array($repo)) {
                if (1 === \count($repo) && false === current($repo)) {
                    $io->write('['.array_key_first($repo).'] <info>disabled</info>');
                    continue;
                }

                $name = $repo['name'] ?? $key;
                $type = $repo['type'] ?? 'unknown';
                $url = $repo['url'] ?? JsonFile::encode($repo);
                $io->write('['.$name.'] <info>'.$type.'</info> '.$url);
            }
        }
    }

    private function suggestTypeForAdd(): \Closure
    {
        return static function (CompletionInput $input): array {
            if ($input->getArgument('action') === 'add') {
                return ['composer', 'vcs', 'artifact', 'path'];
            }

            return [];
        };
    }

    private function suggestRepoNames(): \Closure
    {
        return function (CompletionInput $input): array {
            if (in_array($input->getArgument('action'), ['enable', 'disable'], true)) {
                return ['packagist.org'];
            }

            if (!in_array($input->getArgument('action'), ['remove', 'set-url', 'get-url'], true)) {
                return [];
            }

            $config = Factory::createConfig();
            $configFile = new JsonFile($this->getComposerConfigFile($input, $config));

            $data = $configFile->read();
            $repos = [];

            foreach (($data['repositories'] ?? []) as $repo) {
                if (isset($repo['name'])) {
                    $repos[] = $repo['name'];
                }
            }

            sort($repos);

            return $repos;
        };
    }
}
