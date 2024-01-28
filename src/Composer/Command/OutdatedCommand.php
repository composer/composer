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

use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class OutdatedCommand extends BaseCommand
{
    use CompletionTrait;

    protected function configure(): void
    {
        $this
            ->setName('outdated')
            ->setDescription('Shows a list of installed packages that have updates available, including their latest version')
            ->setDefinition([
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to inspect. Or a name including a wildcard (*) to filter lists of packages instead.', null, $this->suggestInstalledPackage(false)),
                new InputOption('outdated', 'o', InputOption::VALUE_NONE, 'Show only packages that are outdated (this is the default, but present here for compat with `show`'),
                new InputOption('all', 'a', InputOption::VALUE_NONE, 'Show all installed packages with their latest versions'),
                new InputOption('locked', null, InputOption::VALUE_NONE, 'Shows updates for packages from the lock file, regardless of what is currently in vendor dir'),
                new InputOption('direct', 'D', InputOption::VALUE_NONE, 'Shows only packages that are directly required by the root package'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Return a non-zero exit code when there are outdated packages'),
                new InputOption('major-only', 'M', InputOption::VALUE_NONE, 'Show only packages that have major SemVer-compatible updates.'),
                new InputOption('minor-only', 'm', InputOption::VALUE_NONE, 'Show only packages that have minor SemVer-compatible updates.'),
                new InputOption('patch-only', 'p', InputOption::VALUE_NONE, 'Show only packages that have patch SemVer-compatible updates.'),
                new InputOption('sort-by-age', 'A', InputOption::VALUE_NONE, 'Displays the installed version\'s age, and sorts packages oldest first.'),
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text', ['json', 'text']),
                new InputOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore specified package(s). Use it if you don\'t want to be informed about new versions of some packages.', null, $this->suggestInstalledPackage(false)),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages). Use with the --outdated option'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages). Use with the --outdated option'),
            ])
            ->setHelp(
                <<<EOT
The outdated command is just a proxy for `composer show -l`

The color coding (or signage if you have ANSI colors disabled) for dependency versions is as such:

- <info>green</info> (=): Dependency is in the latest version and is up to date.
- <comment>yellow</comment> (~): Dependency has a new version available that includes backwards
  compatibility breaks according to semver, so upgrade when you can but it
  may involve work.
- <highlight>red</highlight> (!): Dependency has a new version that is semver-compatible and you should upgrade it.

Read more at https://getcomposer.org/doc/03-cli.md#outdated
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $args = [
            'command' => 'show',
            '--latest' => true,
        ];
        if (!$input->getOption('all')) {
            $args['--outdated'] = true;
        }
        if ($input->getOption('direct')) {
            $args['--direct'] = true;
        }
        if (null !== $input->getArgument('package')) {
            $args['package'] = $input->getArgument('package');
        }
        if ($input->getOption('strict')) {
            $args['--strict'] = true;
        }
        if ($input->getOption('major-only')) {
            $args['--major-only'] = true;
        }
        if ($input->getOption('minor-only')) {
            $args['--minor-only'] = true;
        }
        if ($input->getOption('patch-only')) {
            $args['--patch-only'] = true;
        }
        if ($input->getOption('locked')) {
            $args['--locked'] = true;
        }
        if ($input->getOption('no-dev')) {
            $args['--no-dev'] = true;
        }
        if ($input->getOption('sort-by-age')) {
            $args['--sort-by-age'] = true;
        }
        $args['--ignore-platform-req'] = $input->getOption('ignore-platform-req');
        if ($input->getOption('ignore-platform-reqs')) {
            $args['--ignore-platform-reqs'] = true;
        }
        $args['--format'] = $input->getOption('format');
        $args['--ignore'] = $input->getOption('ignore');

        $input = new ArrayInput($args);

        $composer = $this->tryComposer();

        if ($composer !== null) {
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'outdated', $input, $output);
            $eventDispatcher = $composer->getEventDispatcher();
            $eventDispatcher->dispatch($commandEvent->getName(), $commandEvent);
            $eventDispatcher->dispatchScript(ScriptEvents::PRE_OUTDATED_CMD);
        }

        $returnCode = $this->getApplication()->run($input, $output);

        if (0 === $returnCode && $composer !== null) {
            $composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_OUTDATED_CMD);
        }

        return $returnCode;
    }

    /**
     * @inheritDoc
     */
    public function isProxyCommand(): bool
    {
        return true;
    }
}
