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
use Composer\Downloader\TransportException;
use Composer\FilterList\FilterListProvider\FilterListProviderSet;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Repository\RepositorySet;
use Composer\Repository\RepositoryUtils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepository;
use Composer\Advisory\Auditor;
use Composer\Console\Input\InputOption;
use Composer\Semver\Constraint\Constraint;

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
                new InputOption('abandoned', null, InputOption::VALUE_REQUIRED, 'Behavior on abandoned packages. Must be "ignore", "report", or "fail".', null, ListPolicyConfig::AUDITS),
                new InputOption('ignore-severity', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Ignore advisories of a certain severity level.', [], ['low', 'medium', 'high', 'critical']),
                new InputOption('ignore-unreachable', null, InputOption::VALUE_NONE, 'Ignore repositories that are unreachable or return a non-200 status code.'),
            ])
            ->setHelp(
                <<<EOT
The <info>audit</info> command checks for security vulnerability advisories for installed packages.

If you do not want to include dev dependencies in the audit you can omit them with --no-dev

If you want to ignore repositories that are unreachable or return a non-200 status code, use --ignore-unreachable

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
            if ($composer->getPackage()->getRequires() !== []
                || (!$input->getOption('no-dev') && $composer->getPackage()->getDevRequires() !== [])) {
                $this->getIO()->writeError('No installed packages found. Please run "composer install" before running "audit" or pass "--locked" to audit the lock file.');
 
                return Auditor::STATUS_FAILED;
            }

            $this->getIO()->writeError('No packages - skipping audit.');

            return Auditor::STATUS_OK;
        }

        $auditor = new Auditor();
        $repoSet = new RepositorySet();
        foreach ($composer->getRepositoryManager()->getRepositories() as $repo) {
            $repoSet->addRepository($repo);
        }

        $abandoned = $input->getOption('abandoned');
        if ($abandoned !== null && !in_array($abandoned, ListPolicyConfig::AUDITS, true)) {
            throw new \InvalidArgumentException('--abandoned must be one of '.implode(', ', ListPolicyConfig::AUDITS).'.');
        }

        $policyConfig = $this->createPolicyConfig($composer->getConfig(), $input);
        if ($abandoned !== null) {
            $policyConfig = $policyConfig->withAudit($abandoned);
        }

        $ignoreSeverities = $input->getOption('ignore-severity');
        if (count($ignoreSeverities) > 0) {
            $policyConfig = $policyConfig->withIgnoreSeverity(array_values($ignoreSeverities));
        }
        if ($input->getOption('ignore-unreachable')) {
            $policyConfig = $policyConfig->withIgnoreUnreachable('audit');
        }

        if ($policyConfig->abandoned->audit !== ListPolicyConfig::AUDIT_IGNORE) {
            $this->refreshAbandonedState($composer, $packages, $policyConfig->ignoreUnreachable->audit);
        }

        $filterListProviderSet = $policyConfig->enabled ? FilterListProviderSet::create($policyConfig, $composer->getRepositoryManager()->getRepositories(), $composer->getLoop()->getHttpDownloader()) : null;

        return min(255, $auditor->audit(
            $this->getIO(),
            $repoSet,
            $policyConfig,
            $packages,
            $this->getAuditFormat($input, 'format'),
            false,
            $filterListProviderSet
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

    /**
     * The lock file and installed.json keep the abandoned state a package had when it was last updated,
     * so it is refreshed from the repositories in case the package was abandoned or unabandoned since
     *
     * @param PackageInterface[] $packages
     */
    private function refreshAbandonedState(Composer $composer, array $packages, bool $ignoreUnreachable): void
    {
        $packagesByName = [];
        $packageNameMap = [];
        $stabilityFlags = [];
        foreach ($packages as $package) {
            if (!$package instanceof CompletePackageInterface || $package instanceof AliasPackage) {
                continue;
            }

            $packagesByName[$package->getName()] = $package;
            $packageNameMap[$package->getName()] = new Constraint('==', $package->getVersion());
            // per package stability so stable versions do not need to load the ~dev metadata
            $stabilityFlags[$package->getName()] = BasePackage::STABILITIES[$package->getStability()];
        }

        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            if ($packageNameMap === []) {
                break;
            }

            try {
                $result = $repository->loadPackages($packageNameMap, [], $stabilityFlags);
            } catch (TransportException $e) {
                if (!$ignoreUnreachable) {
                    throw $e;
                }

                continue;
            }

            foreach ($result['packages'] as $repoPackage) {
                if ($repoPackage instanceof CompletePackageInterface && !$repoPackage instanceof AliasPackage) {
                    $packagesByName[$repoPackage->getName()]->setAbandoned($repoPackage->isAbandoned() ? ($repoPackage->getReplacementPackage() ?? true) : false);
                }
            }

            foreach ($result['namesFound'] as $name) {
                // like RepositorySet::findPackages, do not query lower priority repositories for names already found
                unset($packageNameMap[$name]);
            }
        }
    }
}
