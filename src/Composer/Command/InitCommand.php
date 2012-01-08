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

use Composer\Json\JsonFile;
use Composer\Command\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 */
class InitCommand extends Command
{
    private $gitConfig;

    public function parseAuthorString($author)
    {
        if (preg_match('/^(?P<name>[- \.,a-z0-9]+) <(?P<email>.+?)>$/i', $author, $match)) {
            if ($match['email'] === filter_var($match['email'], FILTER_VALIDATE_EMAIL)) {
                return array(
                    'name'  => trim($match['name']),
                    'email' => $match['email']
                );
            }
        }

        throw new \InvalidArgumentException(
            'Invalid author string.  Must be in the format:'.
            ' John Smith <john@example.com>'
        );
    }

    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Creates a basic composer.json file in current directory.')
            ->setDefinition(array(
                new InputOption('name', null, InputOption::VALUE_NONE, 'Name of the package'),
                new InputOption('description', null, InputOption::VALUE_NONE, 'Description of package'),
                new InputOption('author', null, InputOption::VALUE_NONE, 'Author name of package'),
                // new InputOption('version', null, InputOption::VALUE_NONE, 'Version of package'),
                new InputOption('homepage', null, InputOption::VALUE_NONE, 'Homepage of package'),
                new InputOption('require', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'An array required packages'),
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
        $dialog = $this->getDialogHelper();

        $whitelist = array('name', 'description', 'author', 'require');

        $options = array_filter(array_intersect_key($input->getOptions(), array_flip($whitelist)));

        if (isset($options['author'])) {
            $options['authors'] = $this->formatAuthors($options['author']);
            unset($options['author']);
        }

        $options['require'] = isset($options['require']) ?
            $this->formatRequirements($options['require']) :
            new \stdClass;

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

        if ($input->isInteractive()) {
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

        $dialog = $this->getDialogHelper();
        $dialog->writeSection($output, 'Welcome to the Composer config generator');

        // namespace
        $output->writeln(array(
            '',
            'This command will guide you through creating your composer.json config.',
            '',
        ));

        $cwd = realpath(".");

        if (false === $name = $input->getOption('name')) {
            $name = basename($cwd);
            if (isset($git['github.user'])) {
                $name = $git['github.user'] . '/' . $name;
            }
        }

        $name = $dialog->ask(
            $output,
            $dialog->getQuestion('Package name', $name),
            $name
        );
        $input->setOption('name', $name);

        $description = $input->getOption('description') ?: false;
        $description = $dialog->ask(
            $output,
            $dialog->getQuestion('Description', $description)
        );
        $input->setOption('description', $description);

        if (false === $author = $input->getOption('author')) {
            if (isset($git['user.name']) && isset($git['user.email'])) {
                $author = sprintf('%s <%s>', $git['user.name'], $git['user.email']);
            }
        }

        $self = $this;
        $author = $dialog->askAndValidate(
            $output,
            $dialog->getQuestion('Author', $author),
            function ($value) use ($self, $author) {
                if (null === $value) {
                    return $author;
                }

                $author = $self->parseAuthorString($value);

                return sprintf('%s <%s>', $author['name'], $author['email']);
            }
        );
        $input->setOption('author', $author);

        $output->writeln(array(
            '',
            'Define your dependencies.',
            ''
        ));

        $requirements = array();
        if ($dialog->askConfirmation($output, $dialog->getQuestion('Would you like to define your dependencies interactively', 'yes', '?'), true)) {
            $requirements = $this->determineRequirements($input, $output);
        }
        $input->setOption('require', $requirements);
    }

    protected function getDialogHelper()
    {
        $dialog = $this->getHelperSet()->get('dialog');
        if (!$dialog || get_class($dialog) !== 'Composer\Command\Helper\DialogHelper') {
            $this->getHelperSet()->set($dialog = new DialogHelper());
        }

        return $dialog;
    }

    protected function findPackages($name)
    {
        $composer = $this->getComposer();

        $packages = array();

        // create local repo, this contains all packages that are installed in the local project
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();

        $token = strtolower($name);
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                if (false === ($pos = strpos($package->getName(), $token))) {
                    continue;
                }

                $packages[] = $package;
            }
        }

        return $packages;
    }

    protected function determineRequirements(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();
        $prompt = $dialog->getQuestion('Search for a package', false, ':');

        $requires = $input->getOption('require') ?: array();

        while (null !== $package = $dialog->ask($output, $prompt)) {
            $matches = $this->findPackages($package);

            if (count($matches)) {
                $output->writeln(array(
                    '',
                    sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                    ''
                ));

                foreach ($matches as $position => $package) {
                    $output->writeln(sprintf(' <info>%5s</info> %s <comment>%s</comment>', "[$position]", $package->getPrettyName(), $package->getPrettyVersion()));
                }

                $output->writeln('');

                $validator = function ($selection) use ($matches) {
                    if ('' === $selection) {
                        return false;
                    }

                    if (!isset($matches[(int) $selection])) {
                        throw new \Exception('Not a valid selection');
                    }

                    return $matches[(int) $selection];
                };

                $package = $dialog->askAndValidate($output, $dialog->getQuestion('Enter package # to add', false, ':'), $validator, 3);

                if (false !== $package) {
                    $requires[] = sprintf('%s %s', $package->getName(), $package->getPrettyVersion());
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
        foreach ($requirements as $requirement) {
            list($packageName, $packageVersion) = explode(" ", $requirement);

            $requires[$packageName] = $packageVersion;
        }

        return empty($requires) ? new \stdClass : $requires;
    }

    protected function getGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');

        $cmd = new Process(sprintf('%s config -l', $gitBin));
        $cmd->run();

        if ($cmd->isSuccessful()) {
            return $this->gitConfig = parse_ini_string($cmd->getOutput(), false, INI_SCANNER_RAW);
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
     * @return Boolean
     */
    protected function hasVendorIgnore($ignoreFile, $vendor = 'vendor')
    {
        if (!file_exists($ignoreFile)) {
            return false;
        }

        $pattern = sprintf(
            '~^/?%s(/|/\*)?$~',
            preg_quote($vendor, '~')
        );

        $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    protected function addVendorIgnore($ignoreFile, $vendor = 'vendor')
    {
        $contents = "";
        if (file_exists($ignoreFile)) {
            $contents = file_get_contents($ignoreFile);

            if ("\n" !== substr($contents, 0, -1)) {
                $contents .= "\n";
            }
        }

        file_put_contents($ignoreFile, $contents . $vendor);
    }
}