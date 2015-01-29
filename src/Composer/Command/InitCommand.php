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
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Package\Version\VersionParser;
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
class InitCommand extends Command
{
    protected $repos;

    private $gitConfig;
    private $pool;

    public function parseAuthorString($author)
    {
        if (preg_match('/^(?P<name>[- \.,\p{L}\'’]+) <(?P<email>.+?)>$/u', $author, $match)) {
            if ($this->isValidEmail($match['email'])) {
                return array(
                    'name'  => trim($match['name']),
                    'email' => $match['email']
                );
            }
        }

        throw new \InvalidArgumentException(
            'Invalid author string.  Must be in the format: '.
            'John Smith <john@example.com>'
        );
    }

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
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Homepage of package'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('require-dev', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Package to require for development with a version constraint, e.g. foo/bar:1.0.0 or foo/bar=1.0.0 or "foo/bar 1.0.0"'),
                new InputOption('stability', 's', InputOption::VALUE_REQUIRED, 'Minimum stability (empty or one of: '.implode(', ', array_keys(BasePackage::$stabilities)).')'),
                new InputOption('license', 'l', InputOption::VALUE_REQUIRED, 'License of package'),
            ))
            ->setHelp(<<<EOT
The <info>init</info> command creates a basic composer.json file
in the current directory.

<info>php composer.phar init</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');

        $whitelist = array('name', 'description', 'author', 'homepage', 'require', 'require-dev', 'stability', 'license');

        $options = array_filter(array_intersect_key($input->getOptions(), array_flip($whitelist)));

        if (isset($options['author'])) {
            $options['authors'] = $this->formatAuthors($options['author']);
            unset($options['author']);
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

        $file = new JsonFile('composer.json');

        $json = $file->encode($options);

        if ($input->isInteractive()) {
            $output->writeln(array(
                '',
                $json,
                ''
            ));
            if (!$dialog->askConfirmation($output, $dialog->getQuestion('Do you confirm generation', 'yes', '?'), true)) {
                $output->writeln('<error>Command aborted</error>');

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
                $question = 'Would you like the <info>vendor</info> directory added to your <info>.gitignore</info> [<comment>yes</comment>]?';

                if ($dialog->askConfirmation($output, $question, true)) {
                    $this->addVendorIgnore($ignoreFile);
                }
            }
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $git = $this->getGitConfig();

        $dialog = $this->getHelperSet()->get('dialog');
        $formatter = $this->getHelperSet()->get('formatter');
        $output->writeln(array(
            '',
            $formatter->formatBlock('Welcome to the Composer config generator', 'bg=blue;fg=white', true),
            ''
        ));

        // namespace
        $output->writeln(array(
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
        } else {
            if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}', $name)) {
                throw new \InvalidArgumentException(
                    'The package name '.$name.' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
                );
            }
        }

        $name = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Package name (<vendor>/<name>)', $name),
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
            }
        );
        $input->setOption('name', $name);

        $description = $input->getOption('description') ?: false;
        $description = $dialog->ask(
            $output,
            $dialog->getQuestion('Description', $description),
            $description
        );
        $input->setOption('description', $description);

        if (null === $author = $input->getOption('author')) {
            if (isset($git['user.name']) && isset($git['user.email'])) {
                $author = sprintf('%s <%s>', $git['user.name'], $git['user.email']);
            }
        }

        $self = $this;
        $author = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Author', $author),
            function ($value) use ($self, $author) {
                $value = $value ?: $author;
                $author = $self->parseAuthorString($value);

                return sprintf('%s <%s>', $author['name'], $author['email']);
            }
        );
        $input->setOption('author', $author);

        $minimumStability = $input->getOption('stability') ?: '';
        $minimumStability = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Minimum Stability', $minimumStability),
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
            }
        );
        $input->setOption('stability', $minimumStability);

        $license = $input->getOption('license') ?: false;
        $license = $dialog->ask(
            $output,
            $dialog->getQuestion('License', $license),
            $license
        );
        $input->setOption('license', $license);

        $output->writeln(array(
            '',
            'Define your dependencies.',
            ''
        ));

        $requirements = array();
        if ($dialog->askConfirmation($output, $dialog->getQuestion('Would you like to define your dependencies (require) interactively', 'yes', '?'), true)) {
            $requirements = $this->determineRequirements($input, $output, $input->getOption('require'));
        }
        $input->setOption('require', $requirements);
        $devRequirements = array();
        if ($dialog->askConfirmation($output, $dialog->getQuestion('Would you like to define your dev dependencies (require-dev) interactively', 'yes', '?'), true)) {
            $devRequirements = $this->determineRequirements($input, $output, $input->getOption('require-dev'));
        }
        $input->setOption('require-dev', $devRequirements);
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
                Factory::createDefaultRepositories($this->getIO())
            ));
        }

        return $this->repos;
    }

    protected function determineRequirements(InputInterface $input, OutputInterface $output, $requires = array())
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $prompt = $dialog->getQuestion('Search for a package', false, ':');

        if ($requires) {
            $requires = $this->normalizeRequirements($requires);
            $result = array();

            foreach ($requires as $requirement) {
                if (!isset($requirement['version'])) {
                    // determine the best version automatically
                    $version = $this->findBestVersionForPackage($input, $requirement['name']);
                    $requirement['version'] = $version;

                    $output->writeln(sprintf(
                        'Using version <info>%s</info> for <info>%s</info>',
                        $requirement['version'],
                        $requirement['name']
                    ));
                }

                $result[] = $requirement['name'] . ' ' . $requirement['version'];
            }

            return $result;
        }

        while (null !== $package = $dialog->ask($output, $prompt)) {
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
                    $output->writeln(array(
                        '',
                        sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                        ''
                    ));

                    $output->writeln($choices);
                    $output->writeln('');

                    $validator = function ($selection) use ($matches) {
                        if ('' === $selection) {
                            return false;
                        }

                        if (!is_numeric($selection) && preg_match('{^\s*(\S+)\s+(\S.*)\s*$}', $selection, $matches)) {
                            return $matches[1].' '.$matches[2];
                        }

                        if (!isset($matches[(int) $selection])) {
                            throw new \Exception('Not a valid selection');
                        }

                        $package = $matches[(int) $selection];

                        return $package['name'];
                    };

                    $package = $dialog->askAndValidate($output, $dialog->getQuestion('Enter package # to add, or the complete package name if it is not listed', false, ':'), $validator, 3);
                }

                // no constraint yet, determine the best version automatically
                if (false !== $package && false === strpos($package, ' ')) {
                    $validator = function ($input) {
                        $input = trim($input);

                        return $input ?: false;
                    };

                    $constraint = $dialog->askAndValidate(
                        $output,
                        $dialog->getQuestion('Enter the version constraint to require (or leave blank to use the latest version)', false, ':'),
                        $validator,
                        3)
                    ;
                    if (false === $constraint) {
                        $constraint = $this->findBestVersionForPackage($input, $package);

                        $output->writeln(sprintf(
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
        if (version_compare(PHP_VERSION, '5.3.3', '<')) {
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
     * @return string
     * @throws \InvalidArgumentException
     */
    private function findBestVersionForPackage(InputInterface $input, $name)
    {
        // find the latest version allowed in this pool
        $versionSelector = new VersionSelector($this->getPool($input));
        $package = $versionSelector->findBestCandidate($name);

        if (!$package) {
            throw new \InvalidArgumentException(sprintf(
                'Could not find package %s at any version for your minimum-stability (%s). Check the package spelling or your minimum-stability',
                $name,
                $this->getMinimumStability($input)
            ));
        }

        return $versionSelector->findRecommendedRequireVersion($package);
    }
}
