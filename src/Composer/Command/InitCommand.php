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

use Composer\DependencyResolver\Pool;
use Composer\Json\JsonFile;
use Composer\Factory;
use Composer\Repository\RepositoryFactory;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InitCommand extends BaseCommand
{
    /** @var CompositeRepository */
    protected $repos;

    /** @var array */
    private $gitConfig;

    /** @var Pool */
    private $pool;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Creates a basic composer.json file in current directory.')
            ->setDefinition(array(
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the package'),
                new InputOption('description', null, InputOption::VALUE_REQUIRED, 'Description of package'),
                new InputOption('author', null, InputOption::VALUE_REQUIRED, 'Author name of package'),
                // new InputOption('version', null, InputOption::VALUE_NONE, 'Version of package'),
                new InputOption('type', null, InputOption::VALUE_OPTIONAL, 'Type of package (e.g. library, project, metapackage, composer-plugin)'),
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Homepage of package'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('require-dev', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require for development with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('stability', 's', InputOption::VALUE_REQUIRED, 'Minimum stability (empty or one of: '.implode(', ', array_keys(BasePackage::$stabilities)).')'),
                new InputOption('license', 'l', InputOption::VALUE_REQUIRED, 'License of package'),
                new InputOption('repository', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add custom repositories, either by URL or using JSON arrays'),
            ))
            ->setHelp(<<<EOT
The <info>init</info> command creates a basic composer.json file
in the current directory.

<info>php composer.phar init</info>

EOT
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();

        $whitelist = array('name', 'description', 'author', 'type', 'homepage', 'require', 'require-dev', 'stability', 'license');
        $options = array_filter(array_intersect_key($input->getOptions(), array_flip($whitelist)));

        if (isset($options['author'])) {
            $options['authors'] = $this->formatAuthors($options['author']);
            unset($options['author']);
        }

        $repositories = $input->getOption('repository');
        if ($repositories) {
            $config = Factory::createConfig($io);
            foreach ($repositories as $repo) {
                $options['repositories'][] = RepositoryFactory::configFromString($io, $config, $repo);
            }
        }

        if (isset($options['stability'])) {
            $options['minimum-stability'] = $options['stability'];
            unset($options['stability']);
        }

        $options['require'] = isset($options['require']) ? $this->formatRequirements($options['require']) : new \stdClass;
        if (array() === $options['require']) {
            $options['require'] = new \stdClass;
        }

        if (isset($options['require-dev'])) {
            $options['require-dev'] = $this->formatRequirements($options['require-dev']);
            if (array() === $options['require-dev']) {
                $options['require-dev'] = new \stdClass;
            }
        }

        $file = new JsonFile(Factory::getComposerFile());
        $json = $file->encode($options);

        if ($input->isInteractive()) {
            $io->writeError(array('', $json, ''));
            if (!$io->askConfirmation('Do you confirm generation [<comment>yes</comment>]? ', true)) {
                $io->writeError('<error>Command aborted</error>');

                return 1;
            }
        }

        $file->write($options);

        if ($input->isInteractive() && is_dir('.git')) {
            $ignoreFile = realpath('.gitignore');

            if (false === $ignoreFile) {
                $ignoreFile = realpath('.') . '/.gitignore';
            }

            if (!$this->hasVendorIgnore($ignoreFile)) {
                $question = 'Would you like the <info>vendor</info> directory added to your <info>.gitignore</info> [<comment>yes</comment>]? ';

                if ($io->askConfirmation($question, true)) {
                    $this->addVendorIgnore($ignoreFile);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $git = $this->getGitConfig();
        $io = $this->getIO();
        $formatter = $this->getHelperSet()->get('formatter');

        // initialize repos if configured
        $repositories = $input->getOption('repository');
        if ($repositories) {
            $config = Factory::createConfig($io);
            $repos = array(new PlatformRepository);
            foreach ($repositories as $repo) {
                $repos[] = RepositoryFactory::fromString($io, $config, $repo);
            }
            $repos[] = RepositoryFactory::createRepo($io, $config, array(
                'type' => 'composer',
                'url' => 'https://packagist.org',
            ));

            $this->repos = new CompositeRepository($repos);
            unset($repos, $config, $repositories);
        }

        $io->writeError(array(
            '',
            $formatter->formatBlock('Welcome to the Composer config generator', 'bg=blue;fg=white', true),
            '',
        ));

        // namespace
        $io->writeError(array(
            '',
            'This command will guide you through creating your composer.json config.',
            '',
        ));

        $cwd = realpath(".");

        if (!$name = $input->getOption('name')) {
            $name = basename($cwd);
            $name = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $name = strtolower($name);
            if (isset($git['github.user'])) {
                $name = $git['github.user'] . '/' . $name;
            } elseif (!empty($_SERVER['USERNAME'])) {
                $name = $_SERVER['USERNAME'] . '/' . $name;
            } elseif (get_current_user()) {
                $name = get_current_user() . '/' . $name;
            } else {
                // package names must be in the format foo/bar
                $name = $name . '/' . $name;
            }
            $name = strtolower($name);
        } else {
            if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}', $name)) {
                throw new \InvalidArgumentException(
                    'The package name '.$name.' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
                );
            }
        }

        $name = $io->askAndValidate(
            'Package name (<vendor>/<name>) [<comment>'.$name.'</comment>]: ',
            function ($value) use ($name) {
                if (null === $value) {
                    return $name;
                }

                if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}', $value)) {
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

        $description = $input->getOption('description') ?: false;
        $description = $io->ask(
            'Description [<comment>'.$description.'</comment>]: ',
            $description
        );
        $input->setOption('description', $description);

        if (null === $author = $input->getOption('author')) {
            if (isset($git['user.name']) && isset($git['user.email'])) {
                $author = sprintf('%s <%s>', $git['user.name'], $git['user.email']);
            }
        }

        $self = $this;
        $author = $io->askAndValidate(
            'Author [<comment>'.$author.'</comment>, n to skip]: ',
            function ($value) use ($self, $author) {
                if ($value === 'n' || $value === 'no') {
                    return;
                }
                $value = $value ?: $author;
                $author = $self->parseAuthorString($value);

                return sprintf('%s <%s>', $author['name'], $author['email']);
            },
            null,
            $author
        );
        $input->setOption('author', $author);

        $minimumStability = $input->getOption('stability') ?: null;
        $minimumStability = $io->askAndValidate(
            'Minimum Stability [<comment>'.$minimumStability.'</comment>]: ',
            function ($value) use ($self, $minimumStability) {
                if (null === $value) {
                    return $minimumStability;
                }

                if (!isset(BasePackage::$stabilities[$value])) {
                    throw new \InvalidArgumentException(
                        'Invalid minimum stability "'.$value.'". Must be empty or one of: '.
                        implode(', ', array_keys(BasePackage::$stabilities))
                    );
                }

                return $value;
            },
            null,
            $minimumStability
        );
        $input->setOption('stability', $minimumStability);

        $type = $input->getOption('type') ?: false;
        $type = $io->ask(
            'Package Type (e.g. library, project, metapackage, composer-plugin) [<comment>'.$type.'</comment>]: ',
            $type
        );
        $input->setOption('type', $type);

        $license = $input->getOption('license') ?: false;
        $license = $io->ask(
            'License [<comment>'.$license.'</comment>]: ',
            $license
        );
        $input->setOption('license', $license);

        $io->writeError(array('', 'Define your dependencies.', ''));

        $question = 'Would you like to define your dependencies (require) interactively [<comment>yes</comment>]? ';
        $requirements = array();
        if ($io->askConfirmation($question, true)) {
            $requirements = $this->determineRequirements($input, $output, $input->getOption('require'));
        }
        $input->setOption('require', $requirements);

        $question = 'Would you like to define your dev dependencies (require-dev) interactively [<comment>yes</comment>]? ';
        $devRequirements = array();
        if ($io->askConfirmation($question, true)) {
            $devRequirements = $this->determineRequirements($input, $output, $input->getOption('require-dev'));
        }
        $input->setOption('require-dev', $devRequirements);
    }

    /**
     * @private
     * @param  string $author
     * @return array
     */
    public function parseAuthorString($author)
    {
        if (preg_match('/^(?P<name>[- .,\p{L}\p{N}\'’"()]+) <(?P<email>.+?)>$/u', $author, $match)) {
            if ($this->isValidEmail($match['email'])) {
                return array(
                    'name' => trim($match['name']),
                    'email' => $match['email'],
                );
            }
        }

        throw new \InvalidArgumentException(
            'Invalid author string.  Must be in the format: '.
            'John Smith <john@example.com>'
        );
    }

    protected function findPackages($name)
    {
        return $this->getRepos()->search($name);
    }

    protected function getRepos()
    {
        if (!$this->repos) {
            $this->repos = new CompositeRepository(array_merge(
                array(new PlatformRepository),
                RepositoryFactory::defaultRepos($this->getIO())
            ));
        }

        return $this->repos;
    }

    protected function determineRequirements(InputInterface $input, OutputInterface $output, $requires = array(), $phpVersion = null, $preferredStability = 'stable')
    {
        if ($requires) {
            $requires = $this->normalizeRequirements($requires);
            $result = array();
            $io = $this->getIO();

            foreach ($requires as $requirement) {
                if (!isset($requirement['version'])) {
                    // determine the best version automatically
                    $version = $this->findBestVersionForPackage($input, $requirement['name'], $phpVersion, $preferredStability);
                    $requirement['version'] = $version;

                    $io->writeError(sprintf(
                        'Using version <info>%s</info> for <info>%s</info>',
                        $requirement['version'],
                        $requirement['name']
                    ));
                }

                $result[] = $requirement['name'] . ' ' . $requirement['version'];
            }

            return $result;
        }

        $versionParser = new VersionParser();
        $io = $this->getIO();
        while (null !== $package = $io->ask('Search for a package: ')) {
            $matches = $this->findPackages($package);

            if (count($matches)) {
                $exactMatch = null;
                $choices = array();
                foreach ($matches as $position => $foundPackage) {
                    $choices[] = sprintf(' <info>%5s</info> %s', "[$position]", $foundPackage['name']);
                    if ($foundPackage['name'] === $package) {
                        $exactMatch = true;
                        break;
                    }
                }

                // no match, prompt which to pick
                if (!$exactMatch) {
                    $io->writeError(array(
                        '',
                        sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                        '',
                    ));

                    $io->writeError($choices);
                    $io->writeError('');

                    $validator = function ($selection) use ($matches, $versionParser) {
                        if ('' === $selection) {
                            return false;
                        }

                        if (is_numeric($selection) && isset($matches[(int) $selection])) {
                            $package = $matches[(int) $selection];

                            return $package['name'];
                        }

                        if (preg_match('{^\s*(?P<name>[\S/]+)(?:\s+(?P<version>\S+))?\s*$}', $selection, $packageMatches)) {
                            if (isset($packageMatches['version'])) {
                                // parsing `acme/example ~2.3`

                                // validate version constraint
                                $versionParser->parseConstraints($packageMatches['version']);

                                return $packageMatches['name'].' '.$packageMatches['version'];
                            }

                            // parsing `acme/example`
                            return $packageMatches['name'];
                        }

                        throw new \Exception('Not a valid selection');
                    };

                    $package = $io->askAndValidate(
                        'Enter package # to add, or the complete package name if it is not listed: ',
                        $validator,
                        3,
                        false
                    );
                }

                // no constraint yet, determine the best version automatically
                if (false !== $package && false === strpos($package, ' ')) {
                    $validator = function ($input) {
                        $input = trim($input);

                        return $input ?: false;
                    };

                    $constraint = $io->askAndValidate(
                        'Enter the version constraint to require (or leave blank to use the latest version): ',
                        $validator,
                        3,
                        false
                    );

                    if (false === $constraint) {
                        $constraint = $this->findBestVersionForPackage($input, $package, $phpVersion, $preferredStability);

                        $io->writeError(sprintf(
                            'Using version <info>%s</info> for <info>%s</info>',
                            $constraint,
                            $package
                        ));
                    }

                    $package .= ' '.$constraint;
                }

                if (false !== $package) {
                    $requires[] = $package;
                }
            }
        }

        return $requires;
    }

    protected function formatAuthors($author)
    {
        return array($this->parseAuthorString($author));
    }

    protected function formatRequirements(array $requirements)
    {
        $requires = array();
        $requirements = $this->normalizeRequirements($requirements);
        foreach ($requirements as $requirement) {
            $requires[$requirement['name']] = $requirement['version'];
        }

        return $requires;
    }

    protected function getGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');

        $cmd = new Process(sprintf('%s config -l', ProcessExecutor::escape($gitBin)));
        $cmd->run();

        if ($cmd->isSuccessful()) {
            $this->gitConfig = array();
            preg_match_all('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $this->gitConfig[$match[1]] = $match[2];
            }

            return $this->gitConfig;
        }

        return $this->gitConfig = array();
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
     *
     * @param string $ignoreFile
     * @param string $vendor
     *
     * @return bool
     */
    protected function hasVendorIgnore($ignoreFile, $vendor = 'vendor')
    {
        if (!file_exists($ignoreFile)) {
            return false;
        }

        $pattern = sprintf('{^/?%s(/\*?)?$}', preg_quote($vendor));

        $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeRequirements(array $requirements)
    {
        $parser = new VersionParser();

        return $parser->parseNameVersionPairs($requirements);
    }

    protected function addVendorIgnore($ignoreFile, $vendor = '/vendor/')
    {
        $contents = "";
        if (file_exists($ignoreFile)) {
            $contents = file_get_contents($ignoreFile);

            if ("\n" !== substr($contents, 0, -1)) {
                $contents .= "\n";
            }
        }

        file_put_contents($ignoreFile, $contents . $vendor. "\n");
    }

    protected function isValidEmail($email)
    {
        // assume it's valid if we can't validate it
        if (!function_exists('filter_var')) {
            return true;
        }

        // php <5.3.3 has a very broken email validator, so bypass checks
        if (PHP_VERSION_ID < 50303) {
            return true;
        }

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private function getPool(InputInterface $input)
    {
        if (!$this->pool) {
            $this->pool = new Pool($this->getMinimumStability($input));
            $this->pool->addRepository($this->getRepos());
        }

        return $this->pool;
    }

    private function getMinimumStability(InputInterface $input)
    {
        if ($input->hasOption('stability')) {
            return $input->getOption('stability') ?: 'stable';
        }

        $file = Factory::getComposerFile();
        if (is_file($file) && is_readable($file) && is_array($composer = json_decode(file_get_contents($file), true))) {
            if (!empty($composer['minimum-stability'])) {
                return $composer['minimum-stability'];
            }
        }

        return 'stable';
    }

    /**
     * Given a package name, this determines the best version to use in the require key.
     *
     * This returns a version with the ~ operator prefixed when possible.
     *
     * @param  InputInterface            $input
     * @param  string                    $name
     * @param  string                    $phpVersion
     * @param  string                    $preferredStability
     * @throws \InvalidArgumentException
     * @return string
     */
    private function findBestVersionForPackage(InputInterface $input, $name, $phpVersion, $preferredStability = 'stable')
    {
        // find the latest version allowed in this pool
        $versionSelector = new VersionSelector($this->getPool($input));
        $package = $versionSelector->findBestCandidate($name, null, $phpVersion, $preferredStability);

        if (!$package) {
            // Check whether the PHP version was the problem
            if ($phpVersion && $versionSelector->findBestCandidate($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'Could not find package %s at any version matching your PHP version %s', $name, $phpVersion
                ));
            }
            throw new \InvalidArgumentException(sprintf(
                'Could not find package %s at any version for your minimum-stability (%s). Check the package spelling or your minimum-stability',
                $name,
                $this->getMinimumStability($input)
            ));
        }

        return $versionSelector->findRecommendedRequireVersion($package);
    }
}
