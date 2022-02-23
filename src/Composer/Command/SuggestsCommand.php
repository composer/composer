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

use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Repository\InstalledRepository;
use Composer\Installer\SuggestedPackagesReporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SuggestsCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('suggests')
            ->setDescription('Shows package suggestions.')
            ->setDefinition(array(
                new InputOption('by-package', null, InputOption::VALUE_NONE, 'Groups output by suggesting package (default)'),
                new InputOption('by-suggestion', null, InputOption::VALUE_NONE, 'Groups output by suggested package'),
                new InputOption('all', 'a', InputOption::VALUE_NONE, 'Show suggestions from all dependencies, including transitive ones'),
                new InputOption('list', null, InputOption::VALUE_NONE, 'Show only list of suggested package names'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Exclude suggestions from require-dev packages'),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that you want to list suggestions from.'),
            ))
            ->setHelp(
                <<<EOT

The <info>%command.name%</info> command shows a sorted list of suggested packages.

Read more at https://getcomposer.org/doc/03-cli.md#suggests
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();

        $installedRepos = array(
            new RootPackageRepository(clone $composer->getPackage()),
        );

        $locker = $composer->getLocker();
        if ($locker->isLocked()) {
            $installedRepos[] = new PlatformRepository(array(), $locker->getPlatformOverrides());
            $installedRepos[] = $locker->getLockedRepository(!$input->getOption('no-dev'));
        } else {
            $installedRepos[] = new PlatformRepository(array(), $composer->getConfig()->get('platform') ?: array());
            $installedRepos[] = $composer->getRepositoryManager()->getLocalRepository();
        }

        $installedRepo = new InstalledRepository($installedRepos);
        $reporter = new SuggestedPackagesReporter($this->getIO());

        $filter = $input->getArgument('packages');
        $packages = $installedRepo->getPackages();
        $packages[] = $composer->getPackage();
        foreach ($packages as $package) {
            if (!empty($filter) && !in_array($package->getName(), $filter)) {
                continue;
            }

            $reporter->addSuggestionsFromPackage($package);
        }

        // Determine output mode, default is by-package
        $mode = SuggestedPackagesReporter::MODE_BY_PACKAGE;

        // if by-suggestion is given we override the default
        if ($input->getOption('by-suggestion')) {
            $mode = SuggestedPackagesReporter::MODE_BY_SUGGESTION;
        }
        // unless by-package is also present then we enable both
        if ($input->getOption('by-package')) {
            $mode |= SuggestedPackagesReporter::MODE_BY_PACKAGE;
        }
        // list is exclusive and overrides everything else
        if ($input->getOption('list')) {
            $mode = SuggestedPackagesReporter::MODE_LIST;
        }

        $reporter->output($mode, $installedRepo, empty($filter) && !$input->getOption('all') ? $composer->getPackage() : null);

        return 0;
    }
}
