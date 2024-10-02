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

use Composer\Composer;
use Composer\Repository\RepositorySet;
use Composer\Repository\RepositoryUtils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepository;
use Composer\Advisory\Auditor;
use Composer\Console\Input\InputOption;

class AuditCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('audit')
            ->setDescription('Checks for security vulnerability advisories for installed packages')
            ->setDefinition([
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables auditing of require-dev packages.'),
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format. Must be "table", "plain", "json", or "summary".', Auditor::FORMAT_TABLE, Auditor::FORMATS),
                new InputOption('locked', null, InputOption::VALUE_NONE, 'Audit based on the lock file instead of the installed packages.'),
                new InputOption('abandoned', null, InputOption::VALUE_REQUIRED, 'Behavior on abandoned packages. Must be "ignore", "report", or "fail".', null, Auditor::ABANDONEDS),
                new InputOption('ignore-severity', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Ignore advisories of a certain severity level.', [], ['low', 'medium', 'high', 'critical']),
            ])
            ->setHelp(
                <<<EOT
The <info>audit</info> command checks for security vulnerability advisories for installed packages.

If you do not want to include dev dependencies in the audit you can omit them with --no-dev

Read more at https://getcomposer.org/doc/03-cli.md#audit
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $packages = $this->getPackages($composer, $input);

        if (count($packages) === 0) {
            $this->getIO()->writeError('No packages - skipping audit.');

            return 0;
        }

        $auditor = new Auditor();
        $repoSet = new RepositorySet();
        foreach ($composer->getRepositoryManager()->getRepositories() as $repo) {
            $repoSet->addRepository($repo);
        }

        $auditConfig = $composer->getConfig()->get('audit');

        $abandoned = $input->getOption('abandoned');
        if ($abandoned !== null && !in_array($abandoned, Auditor::ABANDONEDS, true)) {
            throw new \InvalidArgumentException('--audit must be one of '.implode(', ', Auditor::ABANDONEDS).'.');
        }

        $abandoned = $abandoned ?? $auditConfig['abandoned'] ?? Auditor::ABANDONED_FAIL;

        $ignoreSeverities = $input->getOption('ignore-severity') ?? [];

        return min(255, $auditor->audit(
            $this->getIO(),
            $repoSet,
            $packages,
            $this->getAuditFormat($input, 'format'),
            false,
            $auditConfig['ignore'] ?? [],
            $abandoned,
            $ignoreSeverities
        ));

    }

    /**
     * @return PackageInterface[]
     */
    private function getPackages(Composer $composer, InputInterface $input): array
    {
        if ($input->getOption('locked')) {
            if (!$composer->getLocker()->isLocked()) {
                throw new \UnexpectedValueException('Valid composer.json and composer.lock files are required to run this command with --locked');
            }
            $locker = $composer->getLocker();

            return $locker->getLockedRepository(!$input->getOption('no-dev'))->getPackages();
        }

        $rootPkg = $composer->getPackage();
        $installedRepo = new InstalledRepository([$composer->getRepositoryManager()->getLocalRepository()]);

        if ($input->getOption('no-dev')) {
            return RepositoryUtils::filterRequiredPackages($installedRepo->getPackages(), $rootPkg);
        }

        return $installedRepo->getPackages();
    }
}
