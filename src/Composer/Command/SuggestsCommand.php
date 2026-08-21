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
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SuggestsCommand extends BaseCommand
{
    use CompletionTrait;

    protected function configure(): void
    {
        $this
            ->setName('suggests')
            ->setDescription('Shows package suggestions')
            ->setDefinition([
                new InputOption('by-package', null, InputOption::VALUE_NONE, 'Groups output by suggesting package (default)'),
                new InputOption('by-suggestion', null, InputOption::VALUE_NONE, 'Groups output by suggested package'),
                new InputOption('all', 'a', InputOption::VALUE_NONE, 'Show suggestions from all dependencies, including transitive ones'),
                new InputOption('list', null, InputOption::VALUE_NONE, 'Show only list of suggested package names'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Exclude suggestions from require-dev packages'),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that you want to list suggestions from.', null, $this->suggestInstalledPackage()),
            ])
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

        $installedRepos = [
            new RootPackageRepository(clone $composer->getPackage()),
        ];

        $locker = $composer->getLocker();
        if ($locker->isLocked()) {
            $installedRepos[] = new PlatformRepository([], $locker->getPlatformOverrides());
            $installedRepos[] = $locker->getLockedRepository(!$input->getOption('no-dev'));
        } else {
            $installedRepos[] = new PlatformRepository([], $composer->getConfig()->get('platform'));
            $installedRepos[] = $composer->getRepositoryManager()->getLocalRepository();
        }

        $installedRepo = new InstalledRepository($installedRepos);
        $filter = $input->getArgument('packages');
        $normalizedFilter = array_map('strtolower', $filter);
        $validationRepo = $installedRepo;
        if ($locker->isLocked() && $input->getOption('no-dev')) {
            $validationRepo = new InstalledRepository([$locker->getLockedRepository(true)]);
        }

        $missingPackages = [];
        $excludedPackages = [];
        foreach ($normalizedFilter as $index => $package) {
            if ($installedRepo->findPackages($package) !== []) {
                continue;
            }

            if ($validationRepo->findPackages($package) !== []) {
                $excludedPackages[] = $filter[$index];
            } else {
                $missingPackages[] = $filter[$index];
            }
        }

        $errors = [];
        if ($missingPackages !== []) {
            $errors[] = count($missingPackages) === 1
                ? 'Package "'.$missingPackages[0].'" is not installed.'
                : 'Packages "'.implode('", "', $missingPackages).'" are not installed.';
        }
        if ($excludedPackages !== []) {
            $errors[] = count($excludedPackages) === 1
                ? 'Package "'.$excludedPackages[0].'" is excluded by --no-dev.'
                : 'Packages "'.implode('", "', $excludedPackages).'" are excluded by --no-dev.';
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $reporter = new SuggestedPackagesReporter($this->getIO());
        $packages = $installedRepo->getPackages();
        $packages[] = $composer->getPackage();
        foreach ($packages as $package) {
            if ($normalizedFilter !== [] && !in_array($package->getName(), $normalizedFilter, true)) {
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

        $reporter->output($mode, $installedRepo, $normalizedFilter === [] && !$input->getOption('all') ? $composer->getPackage() : null);

        return 0;
    }
}
