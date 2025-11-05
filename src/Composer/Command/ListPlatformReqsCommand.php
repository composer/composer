<?php declare(strict_types=1);

namespace Composer\Command;

use Composer\Package\Link;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Repository\InstalledRepository;
use Composer\Json\JsonFile;

class ListPlatformReqsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('list-platform-reqs')
            ->setDescription('List all platform requirements (PHP and extensions) for installed packages')
            ->setDefinition([
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables listing of require-dev packages requirements.'),
                new InputOption('lock', null, InputOption::VALUE_NONE, 'Lists requirements only from the lock file, not from installed packages.'),
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text', ['json', 'text']),
            ])
            ->setHelp(
                <<<EOT
Lists all platform requirements (PHP and extensions) for the installed packages, without checking if they are satisfied.

<info>php composer.phar list-platform-reqs</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();

        $requires = [];
        $removePackages = [];
        if ($input->getOption('lock')) {
            $installedRepo = $composer->getLocker()->getLockedRepository(! $input->getOption('no-dev'));
        } else {
            $installedRepo = $composer->getRepositoryManager()->getLocalRepository();
            if (! $installedRepo->getPackages()) {
                $installedRepo = $composer->getLocker()->getLockedRepository(! $input->getOption('no-dev'));
            } else {
                if ($input->getOption('no-dev')) {
                    $removePackages = $installedRepo->getDevPackageNames();
                }
            }
        }
        if (! $input->getOption('no-dev')) {
            foreach ($composer->getPackage()->getDevRequires() as $require => $link) {
                $requires[$require] = [$link];
            }
        }

        $installedRepo = new InstalledRepository([$installedRepo, new RootPackageRepository(clone $composer->getPackage())]);
        foreach ($installedRepo->getPackages() as $package) {
            if (in_array($package->getName(), $removePackages, true)) {
                continue;
            }
            foreach ($package->getRequires() as $require => $link) {
                $requires[$require][] = $link;
            }
        }

        ksort($requires);

        $platformReqs = [];
        foreach ($requires as $require => $links) {
            if (PlatformRepository::isPlatformPackage($require)) {
                /** @var Link $link */
                foreach ($links as $link) {
                    $platformReqs[$require][] = $link->getPrettyConstraint();
                }
            }
        }

        $rows = [];
        foreach ($platformReqs as $platformPackage => $constraints) {
            $rows[] = [
                'name' => $platformPackage,
                'constraints' => array_values(array_unique($constraints)),
            ];
        }

        $format = $input->getOption('format');
        if ('json' === $format) {
            $this->getIO()->write(JsonFile::encode($rows));
        } else {
            foreach ($rows as $row) {
                $output->writeln($row['name'] . ': ' . implode('|', $row['constraints']));
            }
        }

        return 0;
    }
}

