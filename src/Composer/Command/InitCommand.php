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
use Composer\Json\JsonValidationException;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Util\Filesystem;
use Composer\Util\Silencer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Helper\FormatterHelper;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InitCommand extends BaseCommand
{
    use CompletionTrait;
    use PackageDiscoveryTrait;

    /** @var array<string, string> */
    private $gitConfig;

    /**
     * @inheritDoc
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Creates a basic composer.json file in current directory')
            ->setDefinition([
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the package'),
                new InputOption('description', null, InputOption::VALUE_REQUIRED, 'Description of package'),
                new InputOption('author', null, InputOption::VALUE_REQUIRED, 'Author name of package'),
                new InputOption('type', null, InputOption::VALUE_REQUIRED, 'Type of package (e.g. library, project, metapackage, composer-plugin)'),
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Homepage of package'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"', null, $this->suggestAvailablePackageInclPlatform()),
                new InputOption('require-dev', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require for development with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"', null, $this->suggestAvailablePackageInclPlatform()),
                new InputOption('stability', 's', InputOption::VALUE_REQUIRED, 'Minimum stability (empty or one of: '.implode(', ', array_keys(BasePackage::STABILITIES)).')'),
                new InputOption('license', 'l', InputOption::VALUE_REQUIRED, 'License of package'),
                new InputOption('repository', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add custom repositories, either by URL or using JSON arrays'),
                new InputOption('autoload', 'a', InputOption::VALUE_REQUIRED, 'Add PSR-4 autoload mapping. Maps your package\'s namespace to the provided directory. (Expects a relative path, e.g. src/)'),
            ])
            ->setHelp(
                <<<EOT
The <info>init</info> command creates a basic composer.json file
in the current directory.

<info>php composer.phar init</info>

Read more at https://getcomposer.org/doc/03-cli.md#init
EOT
            )
        ;
    }

    /**
     * @throws \Seld\JsonLint\ParsingException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();

        $allowlist = ['name', 'description', 'author', 'type', 'homepage', 'require', 'require-dev', 'stability', 'license', 'autoload'];
        $options = array_filter(array_intersect_key($input->getOptions(), array_flip($allowlist)), function ($val) { return $val !== null && $val !== []; });

        if (isset($options['name']) && !Preg::isMatch('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $options['name'])) {
            throw new \InvalidArgumentException(
                'The package name '.$options['name'].' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
            );
        }

        if (isset($options['author'])) {
            $options['authors'] = $this->formatAuthors($options['author']);
            unset($options['author']);
        }

        $repositories = $input->getOption('repository');
        if (count($repositories) > 0) {
            $config = Factory::createConfig($io);
            foreach ($repositories as $repo) {
                $options['repositories'][] = RepositoryFactory::configFromString($io, $config, $repo, true);
            }
        }

        if (isset($options['stability'])) {
            $options['minimum-stability'] = $options['stability'];
            unset($options['stability']);
        }

        $options['require'] = isset($options['require']) ? $this->formatRequirements($options['require']) : new \stdClass;
        if ([] === $options['require']) {
            $options['require'] = new \stdClass;
        }

        if (isset($options['require-dev'])) {
            $options['require-dev'] = $this->formatRequirements($options['require-dev']);
            if ([] === $options['require-dev']) {
                $options['require-dev'] = new \stdClass;
            }
        }

        // --autoload - create autoload object
        $autoloadPath = null;
        if (isset($options['autoload'])) {
            $autoloadPath = $options['autoload'];
            $namespace = $this->namespaceFromPackageName((string) $input->getOption('name'));
            $options['autoload'] = (object) [
                'psr-4' => [
                    $namespace . '\\' => $autoloadPath,
                ],
            ];
        }

        $file = new JsonFile(Factory::getComposerFile());
        $json = JsonFile::encode($options);

        if ($input->isInteractive()) {
            $io->writeError(['', $json, '']);
            if (!$io->askConfirmation('Do you confirm generation [<comment>yes</comment>]? ')) {
                $io->writeError('<error>Command aborted</error>');

                return 1;
            }
        } else {
            if (json_encode($options) === '{"require":{}}') {
                throw new \RuntimeException('You have to run this command in interactive mode, or specify at least some data using --name, --require, etc.');
            }

            $io->writeError('Writing '.$file->getPath());
        }

        $file->write($options);
        try {
            $file->validateSchema(JsonFile::LAX_SCHEMA);
        } catch (JsonValidationException $e) {
            $io->writeError('<error>Schema validation error, aborting</error>');
            $errors = ' - ' . implode(PHP_EOL . ' - ', $e->getErrors());
            $io->writeError($e->getMessage() . ':' . PHP_EOL . $errors);
            Silencer::call('unlink', $file->getPath());

            return 1;
        }

        // --autoload - Create src folder
        if ($autoloadPath) {
            $filesystem = new Filesystem();
            $filesystem->ensureDirectoryExists($autoloadPath);

            // dump-autoload only for projects without added dependencies.
            if (!$this->hasDependencies($options)) {
                $this->runDumpAutoloadCommand($output);
            }
        }

        if ($input->isInteractive() && is_dir('.git')) {
            $ignoreFile = realpath('.gitignore');

            if (false === $ignoreFile) {
                $ignoreFile = realpath('.') . '/.gitignore';
            }

            if (!$this->hasVendorIgnore($ignoreFile)) {
                $question = 'Would you like the <info>vendor</info> directory added to your <info>.gitignore</info> [<comment>yes</comment>]? ';

                if ($io->askConfirmation($question)) {
                    $this->addVendorIgnore($ignoreFile);
                }
            }
        }

        $question = 'Would you like to install dependencies now [<comment>yes</comment>]? ';
        if ($input->isInteractive() && $this->hasDependencies($options) && $io->askConfirmation($question)) {
            $this->updateDependencies($output);
        }

        // --autoload - Show post-install configuration info
        if ($autoloadPath) {
            $namespace = $this->namespaceFromPackageName((string) $input->getOption('name'));

            $io->writeError('PSR-4 autoloading configured. Use "<comment>namespace '.$namespace.';</comment>" in '.$autoloadPath);
            $io->writeError('Include the Composer autoloader with: <comment>require \'vendor/autoload.php\';</comment>');
        }

        return 0;
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $git = $this->getGitConfig();
        $io = $this->getIO();
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelperSet()->get('formatter');

        // initialize repos if configured
        $repositories = $input->getOption('repository');
        if (count($repositories) > 0) {
            $config = Factory::createConfig($io);
            $io->loadConfiguration($config);
            $repoManager = RepositoryFactory::manager($io, $config);

            $repos = [new PlatformRepository];
            $createDefaultPackagistRepo = true;
            foreach ($repositories as $repo) {
                $repoConfig = RepositoryFactory::configFromString($io, $config, $repo, true);
                if (
                    (isset($repoConfig['packagist']) && $repoConfig === ['packagist' => false])
                    || (isset($repoConfig['packagist.org']) && $repoConfig === ['packagist.org' => false])
                ) {
                    $createDefaultPackagistRepo = false;
                    continue;
                }
                $repos[] = RepositoryFactory::createRepo($io, $config, $repoConfig, $repoManager);
            }

            if ($createDefaultPackagistRepo) {
                $repos[] = RepositoryFactory::createRepo($io, $config, [
                    'type' => 'composer',
                    'url' => 'https://repo.packagist.org',
                ], $repoManager);
            }

            $this->repos = new CompositeRepository($repos);
            unset($repos, $config, $repositories);
        }

        $io->writeError([
            '',
            $formatter->formatBlock('Welcome to the Composer config generator', 'bg=blue;fg=white', true),
            '',
        ]);

        // namespace
        $io->writeError([
            '',
            'This command will guide you through creating your composer.json config.',
            '',
        ]);

        $cwd = realpath(".");

        $name = $input->getOption('name');
        if (null === $name) {
            $name = basename($cwd);
            $name = Preg::replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $name = strtolower($name);
            if (!empty($_SERVER['COMPOSER_DEFAULT_VENDOR'])) {
                $name = $_SERVER['COMPOSER_DEFAULT_VENDOR'] . '/' . $name;
            } elseif (isset($git['github.user'])) {
                $name = $git['github.user'] . '/' . $name;
            } elseif (!empty($_SERVER['USERNAME'])) {
                $name = $_SERVER['USERNAME'] . '/' . $name;
            } elseif (!empty($_SERVER['USER'])) {
                $name = $_SERVER['USER'] . '/' . $name;
            } elseif (get_current_user()) {
                $name = get_current_user() . '/' . $name;
            } else {
                // package names must be in the format foo/bar
                $name .= '/' . $name;
            }
            $name = strtolower($name);
        }

        $name = $io->askAndValidate(
            'Package name (<vendor>/<name>) [<comment>'.$name.'</comment>]: ',
            static function ($value) use ($name) {
                if (null === $value) {
                    return $name;
                }

                if (!Preg::isMatch('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $value)) {
                    throw new \InvalidArgumentException(
                        'The package name '.$value.' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
                    );
                }

                return $value;
            },
            null,
            $name
        );
        $input->setOption('name', $name);

        $description = $input->getOption('description') ?: null;
        $description = $io->ask(
            'Description [<comment>'.$description.'</comment>]: ',
            $description
        );
        $input->setOption('description', $description);

        if (null === $author = $input->getOption('author')) {
            if (!empty($_SERVER['COMPOSER_DEFAULT_AUTHOR'])) {
                $author_name = $_SERVER['COMPOSER_DEFAULT_AUTHOR'];
            } elseif (isset($git['user.name'])) {
                $author_name = $git['user.name'];
            }

            if (!empty($_SERVER['COMPOSER_DEFAULT_EMAIL'])) {
                $author_email = $_SERVER['COMPOSER_DEFAULT_EMAIL'];
            } elseif (isset($git['user.email'])) {
                $author_email = $git['user.email'];
            }

            if (isset($author_name, $author_email)) {
                $author = sprintf('%s <%s>', $author_name, $author_email);
            }
        }

        $author = $io->askAndValidate(
            'Author ['.(is_string($author) ? '<comment>'.$author.'</comment>, ' : '') . 'n to skip]: ',
            function ($value) use ($author) {
                if ($value === 'n' || $value === 'no') {
                    return;
                }
                $value = $value ?: $author;
                $author = $this->parseAuthorString($value ?? '');

                if ($author['email'] === null) {
                    return $author['name'];
                }

                return sprintf('%s <%s>', $author['name'], $author['email']);
            },
            null,
            $author
        );
        $input->setOption('author', $author);

        $minimumStability = $input->getOption('stability') ?: null;
        $minimumStability = $io->askAndValidate(
            'Minimum Stability [<comment>'.$minimumStability.'</comment>]: ',
            static function ($value) use ($minimumStability) {
                if (null === $value) {
                    return $minimumStability;
                }

                if (!isset(BasePackage::STABILITIES[$value])) {
                    throw new \InvalidArgumentException(
                        'Invalid minimum stability "'.$value.'". Must be empty or one of: '.
                        implode(', ', array_keys(BasePackage::STABILITIES))
                    );
                }

                return $value;
            },
            null,
            $minimumStability
        );
        $input->setOption('stability', $minimumStability);

        $type = $input->getOption('type');
        $type = $io->ask(
            'Package Type (e.g. library, project, metapackage, composer-plugin) [<comment>'.$type.'</comment>]: ',
            $type
        );
        if ($type === '' || $type === false) {
            $type = null;
        }
        $input->setOption('type', $type);

        if (null === $license = $input->getOption('license')) {
            if (!empty($_SERVER['COMPOSER_DEFAULT_LICENSE'])) {
                $license = $_SERVER['COMPOSER_DEFAULT_LICENSE'];
            }
        }

        $license = $io->ask(
            'License [<comment>'.$license.'</comment>]: ',
            $license
        );
        $input->setOption('license', $license);

        $io->writeError(['', 'Define your dependencies.', '']);

        // prepare to resolve dependencies
        $repos = $this->getRepos();
        $preferredStability = $minimumStability ?: 'stable';
        $platformRepo = null;
        if ($repos instanceof CompositeRepository) {
            foreach ($repos->getRepositories() as $candidateRepo) {
                if ($candidateRepo instanceof PlatformRepository) {
                    $platformRepo = $candidateRepo;
                    break;
                }
            }
        }

        $question = 'Would you like to define your dependencies (require) interactively [<comment>yes</comment>]? ';
        $require = $input->getOption('require');
        $requirements = [];
        if (count($require) > 0 || $io->askConfirmation($question)) {
            $requirements = $this->determineRequirements($input, $output, $require, $platformRepo, $preferredStability);
        }
        $input->setOption('require', $requirements);

        $question = 'Would you like to define your dev dependencies (require-dev) interactively [<comment>yes</comment>]? ';
        $requireDev = $input->getOption('require-dev');
        $devRequirements = [];
        if (count($requireDev) > 0 || $io->askConfirmation($question)) {
            $devRequirements = $this->determineRequirements($input, $output, $requireDev, $platformRepo, $preferredStability);
        }
        $input->setOption('require-dev', $devRequirements);

        // --autoload - input and validation
        $autoload = $input->getOption('autoload') ?: 'src/';
        $namespace = $this->namespaceFromPackageName((string) $input->getOption('name'));
        $autoload = $io->askAndValidate(
            'Add PSR-4 autoload mapping? Maps namespace "'.$namespace.'" to the entered relative path. [<comment>'.$autoload.'</comment>, n to skip]: ',
            static function ($value) use ($autoload) {
                if (null === $value) {
                    return $autoload;
                }

                if ($value === 'n' || $value === 'no') {
                    return;
                }

                $value = $value ?: $autoload;

                if (!Preg::isMatch('{^[^/][A-Za-z0-9\-_/]+/$}', $value)) {
                    throw new \InvalidArgumentException(sprintf(
                        'The src folder name "%s" is invalid. Please add a relative path with tailing forward slash. [A-Za-z0-9_-/]+/',
                        $value
                    ));
                }

                return $value;
            },
            null,
            $autoload
        );
        $input->setOption('autoload', $autoload);
    }

    /**
     * @return array{name: string, email: string|null}
     */
    private function parseAuthorString(string $author): array
    {
        if (Preg::isMatch('/^(?P<name>[- .,\p{L}\p{N}\p{Mn}\'’"()]+)(?:\s+<(?P<email>.+?)>)?$/u', $author, $match)) {
            if (null !== $match['email'] && !$this->isValidEmail($match['email'])) {
                throw new \InvalidArgumentException('Invalid email "'.$match['email'].'"');
            }

            return [
                'name' => trim($match['name']),
                'email' => $match['email'],
            ];
        }

        throw new \InvalidArgumentException(
            'Invalid author string.  Must be in the formats: '.
            'Jane Doe or John Smith <john@example.com>'
        );
    }

    /**
     * @return array<int, array{name: string, email?: string}>
     */
    protected function formatAuthors(string $author): array
    {
        $author = $this->parseAuthorString($author);
        if (null === $author['email']) {
            unset($author['email']);
        }

        return [$author];
    }

    /**
     * Extract namespace from package's vendor name.
     *
     * new_projects.acme-extra/package-name becomes "NewProjectsAcmeExtra\PackageName"
     */
    public function namespaceFromPackageName(string $packageName): ?string
    {
        if (!$packageName || strpos($packageName, '/') === false) {
            return null;
        }

        $namespace = array_map(
            static function ($part): string {
                $part = Preg::replace('/[^a-z0-9]/i', ' ', $part);
                $part = ucwords($part);

                return str_replace(' ', '', $part);
            },
            explode('/', $packageName)
        );

        return implode('\\', $namespace);
    }

    /**
     * @return array<string, string>
     */
    protected function getGitConfig(): array
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');

        $cmd = new Process([$gitBin, 'config', '-l']);
        $cmd->run();

        if ($cmd->isSuccessful()) {
            $this->gitConfig = [];
            Preg::matchAllStrictGroups('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches);
            foreach ($matches[1] as $key => $match) {
                $this->gitConfig[$match] = $matches[2][$key];
            }

            return $this->gitConfig;
        }

        return $this->gitConfig = [];
    }

    /**
     * Checks the local .gitignore file for the Composer vendor directory.
     *
     * Tested patterns include:
     *  "/$vendor"
     *  "$vendor"
     *  "$vendor/"
     *  "/$vendor/"
     *  "/$vendor/*"
     *  "$vendor/*"
     */
    protected function hasVendorIgnore(string $ignoreFile, string $vendor = 'vendor'): bool
    {
        if (!file_exists($ignoreFile)) {
            return false;
        }

        $pattern = sprintf('{^/?%s(/\*?)?$}', preg_quote($vendor));

        $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (Preg::isMatch($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    protected function addVendorIgnore(string $ignoreFile, string $vendor = '/vendor/'): void
    {
        $contents = "";
        if (file_exists($ignoreFile)) {
            $contents = file_get_contents($ignoreFile);

            if (strpos($contents, "\n") !== 0) {
                $contents .= "\n";
            }
        }

        file_put_contents($ignoreFile, $contents . $vendor. "\n");
    }

    protected function isValidEmail(string $email): bool
    {
        // assume it's valid if we can't validate it
        if (!function_exists('filter_var')) {
            return true;
        }

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private function updateDependencies(OutputInterface $output): void
    {
        try {
            $updateCommand = $this->getApplication()->find('update');
            $this->getApplication()->resetComposer();
            $updateCommand->run(new ArrayInput([]), $output);
        } catch (\Exception $e) {
            $this->getIO()->writeError('Could not update dependencies. Run `composer update` to see more information.');
        }
    }

    private function runDumpAutoloadCommand(OutputInterface $output): void
    {
        try {
            $command = $this->getApplication()->find('dump-autoload');
            $this->getApplication()->resetComposer();
            $command->run(new ArrayInput([]), $output);
        } catch (\Exception $e) {
            $this->getIO()->writeError('Could not run dump-autoload.');
        }
    }

    /**
     * @param array<string, string|array<string>> $options
     */
    private function hasDependencies(array $options): bool
    {
        $requires = (array) $options['require'];
        $devRequires = isset($options['require-dev']) ? (array) $options['require-dev'] : [];

        return !empty($requires) || !empty($devRequires);
    }
}
