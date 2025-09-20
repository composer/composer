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

use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
class RepositoryCommand extends BaseCommand
{
    /** @var Config */
    private $config;

    /** @var JsonFile */
    private $configFile;

    /** @var JsonConfigSource */
    private $configSource;

    protected function configure(): void
    {
        $this
            ->setName('repo')
            ->setAliases(['repository'])
            ->setDescription('Manages repositories')
            ->setDefinition([
                new InputOption('global', 'g', InputOption::VALUE_NONE, 'Apply command to the global config file'),
                new InputOption('file', 'f', InputOption::VALUE_REQUIRED, 'If you want to choose a different composer.json or config.json'),
                new InputOption('append', null, InputOption::VALUE_NONE, 'When adding a repository, append it (lower priority) instead of prepending it'),
                new InputOption('before', null, InputOption::VALUE_REQUIRED, 'When adding a repository, insert it before the given repository name'),
                new InputOption('after', null, InputOption::VALUE_REQUIRED, 'When adding a repository, insert it after the given repository name'),
                new InputArgument('action', InputArgument::OPTIONAL, 'Action to perform: list, add, remove, set-url, get-url, enable, disable', 'list'),
                new InputArgument('name', InputArgument::OPTIONAL, 'Repository name (or special name packagist / packagist.org for enable/disable)'),
                new InputArgument('arg1', InputArgument::OPTIONAL, 'Type for add, or new URL for set-url, or JSON config for add'),
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

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        if ($input->getOption('global') && null !== $input->getOption('file')) {
            throw new \RuntimeException('--file and --global can not be combined');
        }

        $io = $this->getIO();
        $this->config = Factory::createConfig($io);

        $configFile = $this->getComposerConfigFile($input, $this->config);

        // Create global composer.json if invoked via `composer global repo`
        if (
            ($configFile === 'composer.json' || $configFile === './composer.json')
            && !file_exists($configFile)
            && realpath(Platform::getCwd()) === realpath($this->config->get('home'))
        ) {
            file_put_contents($configFile, "{\n}\n");
        }

        $this->configFile = new JsonFile($configFile, null, $io);
        $this->configSource = new JsonConfigSource($this->configFile);

        // Initialize the global file if it's not there
        if ($input->getOption('global') && !$this->configFile->exists()) {
            touch($this->configFile->getPath());
            $this->configFile->write(['config' => new \ArrayObject]);
            Silencer::call('chmod', $this->configFile->getPath(), 0600);
        }

        if (!$this->configFile->exists()) {
            throw new \RuntimeException(sprintf('File "%s" cannot be found in the current directory', $configFile));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $name = $input->getArgument('name');
        $arg1 = $input->getArgument('arg1');
        $arg2 = $input->getArgument('arg2');

        $data = $this->configFile->read();
        $repos = $data['repositories'] ?? [];

        switch ($action) {
            case 'list':
            case 'ls':
            case 'show':
                $this->listRepositories($repos, $output);
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
                return 0;

            case 'set-url':
                if ($name === null || $arg1 === null) {
                    throw new \RuntimeException('Usage: composer repo set-url <name> <new-url>');
                }

                $this->configSource->setRepositoryUrl($name, $arg1);
                return 0;

            case 'get-url':
                if ($name === null) {
                    throw new \RuntimeException('Usage: composer repo get-url <name>');
                }
                if (isset($repos[$name]) && is_array($repos[$name])) {
                    $url = $repos[$name]['url'] ?? null;
                    if (!is_string($url)) {
                        throw new \InvalidArgumentException('The '.$name.' repository does not have a URL');
                    }
                    $this->getIO()->write($url, true, IOInterface::QUIET);
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
                            $this->getIO()->write($url, true, IOInterface::QUIET);
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
    private function listRepositories(array $repos, OutputInterface $output): void
    {
        $io = $this->getIO();
        if ($repos === []) {
            $io->write('No repositories configured', true, IOInterface::QUIET);
            return;
        }

        foreach ($repos as $key => $repo) {
            if ($repo === false) {
                $io->write('['.(string) $key.'] <info>disabled</info>', true, IOInterface::QUIET);
                continue;
            }

            if (is_array($repo)) {
                if (1 === count($repo) && false === current($repo)) {
                    $io->write('['.(string) array_key_first($repo).'] <info>disabled</info>', true, IOInterface::QUIET);
                    continue;
                }

                $name = $repo['name'] ?? $key;
                $type = $repo['type'] ?? 'unknown';
                $url = $repo['url'] ?? JsonFile::encode($repo);
                $io->write('['.(string) $name.'] <info>'.$type.'</info> '.$url, true, IOInterface::QUIET);
            }
        }
    }

    /**
     * Get the local composer.json, global config.json, or the file passed by the user
     */
    private function getComposerConfigFile(InputInterface $input, Config $config): string
    {
        return $input->getOption('global')
            ? ($config->get('home') . '/config.json')
            : ($input->getOption('file') ?: Factory::getComposerFile())
            ;
    }
}
