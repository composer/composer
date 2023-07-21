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
use Composer\DependencyResolver\DefaultPolicy;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Repository\RepositoryUtils;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Semver;
use Composer\Spdx\SpdxLicenses;
use Composer\Util\PackageInfo;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Composer\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Jérémy Romey <jeremyFreeAgent>
 * @author Mihai Plasoianu <mihai@plasoianu.de>
 *
 * @phpstan-import-type AutoloadRules from PackageInterface
 * @phpstan-type JsonStructure array<string, null|string|array<string|null>|AutoloadRules>
 */
class ShowCommand extends BaseCommand
{
    use CompletionTrait;

    /** @var VersionParser */
    protected $versionParser;
    /** @var string[] */
    protected $colors;

    /** @var ?RepositorySet */
    private $repositorySet;

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('show')
            ->setAliases(['info'])
            ->setDescription('Shows information about packages')
            ->setDefinition([
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to inspect. Or a name including a wildcard (*) to filter lists of packages instead.', null, $this->suggestPackageBasedOnMode()),
                new InputArgument('version', InputArgument::OPTIONAL, 'Version or version constraint to inspect'),
                new InputOption('all', null, InputOption::VALUE_NONE, 'List all packages'),
                new InputOption('locked', null, InputOption::VALUE_NONE, 'List all locked packages'),
                new InputOption('installed', 'i', InputOption::VALUE_NONE, 'List installed packages only (enabled by default, only present for BC).'),
                new InputOption('platform', 'p', InputOption::VALUE_NONE, 'List platform packages only'),
                new InputOption('available', 'a', InputOption::VALUE_NONE, 'List available packages only'),
                new InputOption('self', 's', InputOption::VALUE_NONE, 'Show the root package information'),
                new InputOption('name-only', 'N', InputOption::VALUE_NONE, 'List package names only'),
                new InputOption('path', 'P', InputOption::VALUE_NONE, 'Show package paths'),
                new InputOption('tree', 't', InputOption::VALUE_NONE, 'List the dependencies as a tree'),
                new InputOption('latest', 'l', InputOption::VALUE_NONE, 'Show the latest version'),
                new InputOption('outdated', 'o', InputOption::VALUE_NONE, 'Show the latest version but only for packages that are outdated'),
                new InputOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore specified package(s). Use it with the --outdated option if you don\'t want to be informed about new versions of some packages.', null, $this->suggestInstalledPackage(false)),
                new InputOption('major-only', 'M', InputOption::VALUE_NONE, 'Show only packages that have major SemVer-compatible updates. Use with the --latest or --outdated option.'),
                new InputOption('minor-only', 'm', InputOption::VALUE_NONE, 'Show only packages that have minor SemVer-compatible updates. Use with the --latest or --outdated option.'),
                new InputOption('patch-only', null, InputOption::VALUE_NONE, 'Show only packages that have patch SemVer-compatible updates. Use with the --latest or --outdated option.'),
                new InputOption('direct', 'D', InputOption::VALUE_NONE, 'Shows only packages that are directly required by the root package'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Return a non-zero exit code when there are outdated packages'),
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text', ['json', 'text']),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages). Use with the --outdated option'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages). Use with the --outdated option'),
            ])
            ->setHelp(
                <<<EOT
The show command displays detailed information about a package, or
lists all packages available.

Read more at https://getcomposer.org/doc/03-cli.md#show-info
EOT
            )
        ;
    }

    protected function suggestPackageBasedOnMode(): \Closure
    {
        return function (CompletionInput $input) {
            if ($input->getOption('available') || $input->getOption('all')) {
                return $this->suggestAvailablePackageInclPlatform()($input);
            }

            if ($input->getOption('platform')) {
                return $this->suggestPlatformPackage()($input);
            }

            return $this->suggestInstalledPackage(false)($input);
        };
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->versionParser = new VersionParser;
        if ($input->getOption('tree')) {
            $this->initStyles($output);
        }

        $composer = $this->tryComposer();
        $io = $this->getIO();

        if ($input->getOption('installed')) {
            $io->writeError('<warning>You are using the deprecated option "installed". Only installed packages are shown by default now. The --all option can be used to show all packages.</warning>');
        }

        if ($input->getOption('outdated')) {
            $input->setOption('latest', true);
        } elseif (count($input->getOption('ignore')) > 0) {
            $io->writeError('<warning>You are using the option "ignore" for action other than "outdated", it will be ignored.</warning>');
        }

        if ($input->getOption('direct') && ($input->getOption('all') || $input->getOption('available') || $input->getOption('platform'))) {
            $io->writeError('The --direct (-D) option is not usable in combination with --all, --platform (-p) or --available (-a)');

            return 1;
        }

        if ($input->getOption('tree') && ($input->getOption('all') || $input->getOption('available'))) {
            $io->writeError('The --tree (-t) option is not usable in combination with --all or --available (-a)');

            return 1;
        }

        if (count(array_filter([$input->getOption('patch-only'), $input->getOption('minor-only'), $input->getOption('major-only')])) > 1) {
            $io->writeError('Only one of --major-only, --minor-only or --patch-only can be used at once');

            return 1;
        }

        if ($input->getOption('tree') && $input->getOption('latest')) {
            $io->writeError('The --tree (-t) option is not usable in combination with --latest (-l)');

            return 1;
        }

        if ($input->getOption('tree') && $input->getOption('path')) {
            $io->writeError('The --tree (-t) option is not usable in combination with --path (-P)');

            return 1;
        }

        $format = $input->getOption('format');
        if (!in_array($format, ['text', 'json'])) {
            $io->writeError(sprintf('Unsupported format "%s". See help for supported formats.', $format));

            return 1;
        }

        $platformReqFilter = $this->getPlatformRequirementFilter($input);

        // init repos
        $platformOverrides = [];
        if ($composer) {
            $platformOverrides = $composer->getConfig()->get('platform');
        }
        $platformRepo = new PlatformRepository([], $platformOverrides);
        $lockedRepo = null;

        if ($input->getOption('self')) {
            $package = clone $this->requireComposer()->getPackage();
            if ($input->getOption('name-only')) {
                $io->write($package->getName());

                return 0;
            }
            if ($input->getArgument('package')) {
                throw new \InvalidArgumentException('You cannot use --self together with a package name');
            }
            $repos = $installedRepo = new InstalledRepository([new RootPackageRepository($package)]);
        } elseif ($input->getOption('platform')) {
            $repos = $installedRepo = new InstalledRepository([$platformRepo]);
        } elseif ($input->getOption('available')) {
            $installedRepo = new InstalledRepository([$platformRepo]);
            if ($composer) {
                $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
                $installedRepo->addRepository($composer->getRepositoryManager()->getLocalRepository());
            } else {
                $defaultRepos = RepositoryFactory::defaultReposWithDefaultManager($io);
                $repos = new CompositeRepository($defaultRepos);
                $io->writeError('No composer.json found in the current directory, showing available packages from ' . implode(', ', array_keys($defaultRepos)));
            }
        } elseif ($input->getOption('all') && $composer) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $locker = $composer->getLocker();
            if ($locker->isLocked()) {
                $lockedRepo = $locker->getLockedRepository(true);
                $installedRepo = new InstalledRepository([$lockedRepo, $localRepo, $platformRepo]);
            } else {
                $installedRepo = new InstalledRepository([$localRepo, $platformRepo]);
            }
            $repos = new CompositeRepository(array_merge([new FilterRepository($installedRepo, ['canonical' => false])], $composer->getRepositoryManager()->getRepositories()));
        } elseif ($input->getOption('all')) {
            $defaultRepos = RepositoryFactory::defaultReposWithDefaultManager($io);
            $io->writeError('No composer.json found in the current directory, showing available packages from ' . implode(', ', array_keys($defaultRepos)));
            $installedRepo = new InstalledRepository([$platformRepo]);
            $repos = new CompositeRepository(array_merge([$installedRepo], $defaultRepos));
        } elseif ($input->getOption('locked')) {
            if (!$composer || !$composer->getLocker()->isLocked()) {
                throw new \UnexpectedValueException('A valid composer.json and composer.lock files is required to run this command with --locked');
            }
            $locker = $composer->getLocker();
            $lockedRepo = $locker->getLockedRepository(!$input->getOption('no-dev'));
            $repos = $installedRepo = new InstalledRepository([$lockedRepo]);
        } else {
            // --installed / default case
            if (!$composer) {
                $composer = $this->requireComposer();
            }
            $rootPkg = $composer->getPackage();
            $repos = $installedRepo = new InstalledRepository([$composer->getRepositoryManager()->getLocalRepository()]);

            if ($input->getOption('no-dev')) {
                $packages = RepositoryUtils::filterRequiredPackages($installedRepo->getPackages(), $rootPkg);
                $repos = $installedRepo = new InstalledRepository([new InstalledArrayRepository(array_map(static function ($pkg): PackageInterface {
                    return clone $pkg;
                }, $packages))]);
            }

            if (!$installedRepo->getPackages() && ($rootPkg->getRequires() || $rootPkg->getDevRequires())) {
                $io->writeError('<warning>No dependencies installed. Try running composer install or update.</warning>');
            }
        }

        if ($composer) {
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'show', $input, $output);
            $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);
        }

        if ($input->getOption('latest') && null === $composer) {
            $io->writeError('No composer.json found in the current directory, disabling "latest" option');
            $input->setOption('latest', false);
        }

        $packageFilter = $input->getArgument('package');

        // show single package or single version
        if (isset($package)) {
            $versions = [$package->getPrettyVersion() => $package->getVersion()];
        } elseif (null !== $packageFilter && !str_contains($packageFilter, '*')) {
            [$package, $versions] = $this->getPackage($installedRepo, $repos, $packageFilter, $input->getArgument('version'));

            if (!isset($package)) {
                $options = $input->getOptions();
                $hint = '';
                if ($input->getOption('locked')) {
                    $hint .= ' in lock file';
                }
                if (isset($options['working-dir'])) {
                    $hint .= ' in ' . $options['working-dir'] . '/composer.json';
                }
                if (PlatformRepository::isPlatformPackage($packageFilter) && !$input->getOption('platform')) {
                    $hint .= ', try using --platform (-p) to show platform packages';
                }
                if (!$input->getOption('all')) {
                    $hint .= ', try using --available (-a) to show all available packages';
                }

                throw new \InvalidArgumentException('Package "' . $packageFilter . '" not found'.$hint.'.');
            }
        }

        if (isset($package)) {
            assert(isset($versions));

            $exitCode = 0;
            if ($input->getOption('tree')) {
                $arrayTree = $this->generatePackageTree($package, $installedRepo, $repos);

                if ('json' === $format) {
                    $io->write(JsonFile::encode(['installed' => [$arrayTree]]));
                } else {
                    $this->displayPackageTree([$arrayTree]);
                }

                return $exitCode;
            }

            $latestPackage = null;
            if ($input->getOption('latest')) {
                $latestPackage = $this->findLatestPackage($package, $composer, $platformRepo, $input->getOption('major-only'), $input->getOption('minor-only'), $input->getOption('patch-only'), $platformReqFilter);
            }
            if (
                $input->getOption('outdated')
                && $input->getOption('strict')
                && null !== $latestPackage
                && $latestPackage->getFullPrettyVersion() !== $package->getFullPrettyVersion()
                && (!$latestPackage instanceof CompletePackageInterface || !$latestPackage->isAbandoned())
            ) {
                $exitCode = 1;
            }
            if ($input->getOption('path')) {
                $io->write($package->getName(), false);
                $path = $composer->getInstallationManager()->getInstallPath($package);
                if (is_string($path)) {
                    $io->write(' ' . strtok(realpath($path), "\r\n"));
                } else {
                    $io->write(' null');
                }

                return $exitCode;
            }

            if ('json' === $format) {
                $this->printPackageInfoAsJson($package, $versions, $installedRepo, $latestPackage ?: null);
            } else {
                $this->printPackageInfo($package, $versions, $installedRepo, $latestPackage ?: null);
            }

            return $exitCode;
        }

        // show tree view if requested
        if ($input->getOption('tree')) {
            $rootRequires = $this->getRootRequires();
            $packages = $installedRepo->getPackages();
            usort($packages, static function (BasePackage $a, BasePackage $b): int {
                return strcmp((string) $a, (string) $b);
            });
            $arrayTree = [];
            foreach ($packages as $package) {
                if (in_array($package->getName(), $rootRequires, true)) {
                    $arrayTree[] = $this->generatePackageTree($package, $installedRepo, $repos);
                }
            }

            if ('json' === $format) {
                $io->write(JsonFile::encode(['installed' => $arrayTree]));
            } else {
                $this->displayPackageTree($arrayTree);
            }

            return 0;
        }

        // list packages
        /** @var array<string, array<string, string|CompletePackageInterface>> $packages */
        $packages = [];
        $packageFilterRegex = null;
        if (null !== $packageFilter) {
            $packageFilterRegex = '{^'.str_replace('\\*', '.*?', preg_quote($packageFilter)).'$}i';
        }

        $packageListFilter = null;
        if ($input->getOption('direct')) {
            $packageListFilter = $this->getRootRequires();
        }

        if ($input->getOption('path') && null === $composer) {
            $io->writeError('No composer.json found in the current directory, disabling "path" option');
            $input->setOption('path', false);
        }

        foreach (RepositoryUtils::flattenRepositories($repos) as $repo) {
            if ($repo === $platformRepo) {
                $type = 'platform';
            } elseif ($lockedRepo !== null && $repo === $lockedRepo) {
                $type = 'locked';
            } elseif ($repo === $installedRepo || in_array($repo, $installedRepo->getRepositories(), true)) {
                $type = 'installed';
            } else {
                $type = 'available';
            }
            if ($repo instanceof ComposerRepository) {
                foreach ($repo->getPackageNames($packageFilter) as $name) {
                    $packages[$type][$name] = $name;
                }
            } else {
                foreach ($repo->getPackages() as $package) {
                    if (!isset($packages[$type][$package->getName()])
                        || !is_object($packages[$type][$package->getName()])
                        || version_compare($packages[$type][$package->getName()]->getVersion(), $package->getVersion(), '<')
                    ) {
                        while ($package instanceof AliasPackage) {
                            $package = $package->getAliasOf();
                        }
                        if (!$packageFilterRegex || Preg::isMatch($packageFilterRegex, $package->getName())) {
                            if (null === $packageListFilter || in_array($package->getName(), $packageListFilter, true)) {
                                $packages[$type][$package->getName()] = $package;
                            }
                        }
                    }
                }
                if ($repo === $platformRepo) {
                    foreach ($platformRepo->getDisabledPackages() as $name => $package) {
                        $packages[$type][$name] = $package;
                    }
                }
            }
        }

        $showAllTypes = $input->getOption('all');
        $showLatest = $input->getOption('latest');
        $showMajorOnly = $input->getOption('major-only');
        $showMinorOnly = $input->getOption('minor-only');
        $showPatchOnly = $input->getOption('patch-only');
        $ignoredPackages = array_map('strtolower', $input->getOption('ignore'));
        $indent = $showAllTypes ? '  ' : '';
        /** @var PackageInterface[] $latestPackages */
        $latestPackages = [];
        $exitCode = 0;
        $viewData = [];
        $viewMetaData = [];

        $writeVersion = false;
        $writeDescription = false;

        foreach (['platform' => true, 'locked' => true, 'available' => false, 'installed' => true] as $type => $showVersion) {
            if (isset($packages[$type])) {
                ksort($packages[$type]);

                $nameLength = $versionLength = $latestLength = 0;

                if ($showLatest && $showVersion) {
                    foreach ($packages[$type] as $package) {
                        if (is_object($package)) {
                            $latestPackage = $this->findLatestPackage($package, $composer, $platformRepo, $showMajorOnly, $showMinorOnly, $showPatchOnly, $platformReqFilter);
                            if ($latestPackage === null) {
                                continue;
                            }

                            $latestPackages[$package->getPrettyName()] = $latestPackage;
                        }
                    }
                }

                $writePath = !$input->getOption('name-only') && $input->getOption('path');
                $writeVersion = !$input->getOption('name-only') && !$input->getOption('path') && $showVersion;
                $writeLatest = $writeVersion && $showLatest;
                $writeDescription = !$input->getOption('name-only') && !$input->getOption('path');

                $hasOutdatedPackages = false;

                $viewData[$type] = [];
                foreach ($packages[$type] as $package) {
                    $packageViewData = [];
                    if (is_object($package)) {
                        $latestPackage = null;
                        if ($showLatest && isset($latestPackages[$package->getPrettyName()])) {
                            $latestPackage = $latestPackages[$package->getPrettyName()];
                        }

                        // Determine if Composer is checking outdated dependencies and if current package should trigger non-default exit code
                        $packageIsUpToDate = $latestPackage && $latestPackage->getFullPrettyVersion() === $package->getFullPrettyVersion() && (!$latestPackage instanceof CompletePackageInterface || !$latestPackage->isAbandoned());
                        // When using --major-only, and no bigger version than current major is found then it is considered up to date
                        $packageIsUpToDate = $packageIsUpToDate || ($latestPackage === null && $showMajorOnly);
                        $packageIsIgnored = \in_array($package->getPrettyName(), $ignoredPackages, true);
                        if ($input->getOption('outdated') && ($packageIsUpToDate || $packageIsIgnored)) {
                            continue;
                        }

                        if ($input->getOption('outdated') || $input->getOption('strict')) {
                            $hasOutdatedPackages = true;
                        }

                        $packageViewData['name'] = $package->getPrettyName();
                        $packageViewData['direct-dependency'] = in_array($package->getName(), $this->getRootRequires(), true);
                        if ($format !== 'json' || true !== $input->getOption('name-only')) {
                            $packageViewData['homepage'] = $package instanceof CompletePackageInterface ? $package->getHomepage() : null;
                            $packageViewData['source'] = PackageInfo::getViewSourceUrl($package);
                        }
                        $nameLength = max($nameLength, strlen($package->getPrettyName()));
                        if ($writeVersion) {
                            $packageViewData['version'] = $package->getFullPrettyVersion();
                            $versionLength = max($versionLength, strlen($package->getFullPrettyVersion()));
                        }
                        if ($writeLatest && $latestPackage) {
                            $packageViewData['latest'] = $latestPackage->getFullPrettyVersion();
                            $packageViewData['latest-status'] = $this->getUpdateStatus($latestPackage, $package);
                            $latestLength = max($latestLength, strlen($packageViewData['latest']));
                        } elseif ($writeLatest) {
                            $packageViewData['latest'] = '[none matched]';
                            $packageViewData['latest-status'] = 'up-to-date';
                            $latestLength = max($latestLength, strlen($packageViewData['latest']));
                        }
                        if ($writeDescription && $package instanceof CompletePackageInterface) {
                            $packageViewData['description'] = $package->getDescription();
                        }
                        if ($writePath) {
                            $path = $composer->getInstallationManager()->getInstallPath($package);
                            if (is_string($path)) {
                                $packageViewData['path'] = strtok(realpath($path), "\r\n");
                            } else {
                                $packageViewData['path'] = null;
                            }
                        }

                        $packageIsAbandoned = false;
                        if ($latestPackage instanceof CompletePackageInterface && $latestPackage->isAbandoned()) {
                            $replacementPackageName = $latestPackage->getReplacementPackage();
                            $replacement = $replacementPackageName !== null
                                ? 'Use ' . $latestPackage->getReplacementPackage() . ' instead'
                                : 'No replacement was suggested';
                            $packageWarning = sprintf(
                                'Package %s is abandoned, you should avoid using it. %s.',
                                $package->getPrettyName(),
                                $replacement
                            );
                            $packageViewData['warning'] = $packageWarning;
                            $packageIsAbandoned = $replacementPackageName ?? true;
                        }

                        $packageViewData['abandoned'] = $packageIsAbandoned;
                    } else {
                        $packageViewData['name'] = $package;
                        $nameLength = max($nameLength, strlen($package));
                    }
                    $viewData[$type][] = $packageViewData;
                }
                $viewMetaData[$type] = [
                    'nameLength' => $nameLength,
                    'versionLength' => $versionLength,
                    'latestLength' => $latestLength,
                    'writeLatest' => $writeLatest,
                ];
                if ($input->getOption('strict') && $hasOutdatedPackages) {
                    $exitCode = 1;
                    break;
                }
            }
        }

        if ('json' === $format) {
            $io->write(JsonFile::encode($viewData));
        } else {
            if ($input->getOption('latest') && array_filter($viewData)) {
                if (!$io->isDecorated()) {
                    $io->writeError('Legend:');
                    $io->writeError('! patch or minor release available - update recommended');
                    $io->writeError('~ major release available - update possible');
                    if (!$input->getOption('outdated')) {
                        $io->writeError('= up to date version');
                    }
                } else {
                    $io->writeError('<info>Color legend:</info>');
                    $io->writeError('- <highlight>patch or minor</highlight> release available - update recommended');
                    $io->writeError('- <comment>major</comment> release available - update possible');
                    if (!$input->getOption('outdated')) {
                        $io->writeError('- <info>up to date</info> version');
                    }
                }
            }

            $width = $this->getTerminalWidth();

            foreach ($viewData as $type => $packages) {
                $nameLength = $viewMetaData[$type]['nameLength'];
                $versionLength = $viewMetaData[$type]['versionLength'];
                $latestLength = $viewMetaData[$type]['latestLength'];
                $writeLatest = $viewMetaData[$type]['writeLatest'];

                $versionFits = $nameLength + $versionLength + 3 <= $width;
                $latestFits = $nameLength + $versionLength + $latestLength + 3 <= $width;
                $descriptionFits = $nameLength + $versionLength + $latestLength + 24 <= $width;

                if ($latestFits && !$io->isDecorated()) {
                    $latestLength += 2;
                }

                if ($showAllTypes) {
                    if ('available' === $type) {
                        $io->write('<comment>' . $type . '</comment>:');
                    } else {
                        $io->write('<info>' . $type . '</info>:');
                    }
                }

                if ($writeLatest && !$input->getOption('direct')) {
                    $directDeps = [];
                    $transitiveDeps = [];
                    foreach ($packages as $pkg) {
                        if ($pkg['direct-dependency'] ?? false) {
                            $directDeps[] = $pkg;
                        } else {
                            $transitiveDeps[] = $pkg;
                        }
                    }

                    $io->writeError('');
                    $io->writeError('<info>Direct dependencies required in composer.json:</>');
                    if (\count($directDeps) > 0) {
                        $this->printPackages($io, $directDeps, $indent, $writeVersion && $versionFits, $latestFits, $writeDescription && $descriptionFits, $width, $versionLength, $nameLength, $latestLength);
                    } else {
                        $io->writeError('Everything up to date');
                    }
                    $io->writeError('');
                    $io->writeError('<info>Transitive dependencies not required in composer.json:</>');
                    if (\count($transitiveDeps) > 0) {
                        $this->printPackages($io, $transitiveDeps, $indent, $writeVersion && $versionFits, $latestFits, $writeDescription && $descriptionFits, $width, $versionLength, $nameLength, $latestLength);
                    } else {
                        $io->writeError('Everything up to date');
                    }
                } else {
                    if ($writeLatest && \count($packages) === 0) {
                        $io->writeError('All your direct dependencies are up to date');
                    } else {
                        $this->printPackages($io, $packages, $indent, $writeVersion && $versionFits, $writeLatest && $latestFits, $writeDescription && $descriptionFits, $width, $versionLength, $nameLength, $latestLength);
                    }
                }

                if ($showAllTypes) {
                    $io->write('');
                }
            }
        }

        return $exitCode;
    }

    /**
     * @param array<array{name: string, direct-dependency?: bool, version?: string, latest?: string, latest-status?: string, description?: string|null, path?: string|null, source?: string|null, homepage?: string|null, warning?: string, abandoned?: bool|string}> $packages
     */
    private function printPackages(IOInterface $io, array $packages, string $indent, bool $writeVersion, bool $writeLatest, bool $writeDescription, int $width, int $versionLength, int $nameLength, int $latestLength): void
    {
        $padName = $writeVersion || $writeLatest || $writeDescription;
        $padVersion = $writeLatest || $writeDescription;
        $padLatest = $writeDescription;
        foreach ($packages as $package) {
            $link = $package['source'] ?? $package['homepage'] ?? '';
            if ($link !== '') {
                $io->write($indent . '<href='.OutputFormatter::escape($link).'>'.$package['name'].'</>'. str_repeat(' ', ($padName ? $nameLength - strlen($package['name']) : 0)), false);
            } else {
                $io->write($indent . str_pad($package['name'], ($padName ? $nameLength : 0), ' '), false);
            }
            if (isset($package['version']) && $writeVersion) {
                $io->write(' ' . str_pad($package['version'], ($padVersion ? $versionLength : 0), ' '), false);
            }
            if (isset($package['latest']) && isset($package['latest-status']) && $writeLatest) {
                $latestVersion = $package['latest'];
                $updateStatus = $package['latest-status'];
                $style = $this->updateStatusToVersionStyle($updateStatus);
                if (!$io->isDecorated()) {
                    $latestVersion = str_replace(['up-to-date', 'semver-safe-update', 'update-possible'], ['=', '!', '~'], $updateStatus) . ' ' . $latestVersion;
                }
                $io->write(' <' . $style . '>' . str_pad($latestVersion, ($padLatest ? $latestLength : 0), ' ') . '</' . $style . '>', false);
            }
            if (isset($package['description']) && $writeDescription) {
                $description = strtok($package['description'], "\r\n");
                $remaining = $width - $nameLength - $versionLength - 4;
                if ($writeLatest) {
                    $remaining -= $latestLength;
                }
                if (strlen($description) > $remaining) {
                    $description = substr($description, 0, $remaining - 3) . '...';
                }
                $io->write(' ' . $description, false);
            }
            if (array_key_exists('path', $package)) {
                $io->write(' '.(is_string($package['path']) ? $package['path'] : 'null'), false);
            }
            $io->write('');
            if (isset($package['warning'])) {
                $io->write('<warning>' . $package['warning'] . '</warning>');
            }
        }
    }

    /**
     * @return string[]
     */
    protected function getRootRequires(): array
    {
        $composer = $this->tryComposer();
        if ($composer === null) {
            return [];
        }

        $rootPackage = $composer->getPackage();

        return array_map(
            'strtolower',
            array_keys(array_merge($rootPackage->getRequires(), $rootPackage->getDevRequires()))
        );
    }

    /**
     * @return array|string|string[]
     */
    protected function getVersionStyle(PackageInterface $latestPackage, PackageInterface $package)
    {
        return $this->updateStatusToVersionStyle($this->getUpdateStatus($latestPackage, $package));
    }

    /**
     * finds a package by name and version if provided
     *
     * @param  ConstraintInterface|string $version
     * @throws \InvalidArgumentException
     * @return array{CompletePackageInterface|null, array<string, string>}
     */
    protected function getPackage(InstalledRepository $installedRepo, RepositoryInterface $repos, string $name, $version = null): array
    {
        $name = strtolower($name);
        $constraint = is_string($version) ? $this->versionParser->parseConstraints($version) : $version;

        $policy = new DefaultPolicy();
        $repositorySet = new RepositorySet('dev');
        $repositorySet->allowInstalledRepositories();
        $repositorySet->addRepository($repos);

        $matchedPackage = null;
        $versions = [];
        if (PlatformRepository::isPlatformPackage($name)) {
            $pool = $repositorySet->createPoolWithAllPackages();
        } else {
            $pool = $repositorySet->createPoolForPackage($name);
        }
        $matches = $pool->whatProvides($name, $constraint);
        foreach ($matches as $index => $package) {
            // avoid showing the 9999999-dev alias if the default branch has no branch-alias set
            if ($package instanceof AliasPackage && $package->getVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
                $package = $package->getAliasOf();
            }

            // select an exact match if it is in the installed repo and no specific version was required
            if (null === $version && $installedRepo->hasPackage($package)) {
                $matchedPackage = $package;
            }

            $versions[$package->getPrettyVersion()] = $package->getVersion();
            $matches[$index] = $package->getId();
        }

        // select preferred package according to policy rules
        if (null === $matchedPackage && $matches && $preferred = $policy->selectPreferredPackages($pool, $matches)) {
            $matchedPackage = $pool->literalToPackage($preferred[0]);
        }

        if ($matchedPackage !== null && !$matchedPackage instanceof CompletePackageInterface) {
            throw new \LogicException('ShowCommand::getPackage can only work with CompletePackageInterface, but got '.get_class($matchedPackage));
        }

        return [$matchedPackage, $versions];
    }

    /**
     * Prints package info.
     *
     * @param array<string, string>    $versions
     */
    protected function printPackageInfo(CompletePackageInterface $package, array $versions, InstalledRepository $installedRepo, ?PackageInterface $latestPackage = null): void
    {
        $io = $this->getIO();

        $this->printMeta($package, $versions, $installedRepo, $latestPackage ?: null);
        $this->printLinks($package, Link::TYPE_REQUIRE);
        $this->printLinks($package, Link::TYPE_DEV_REQUIRE, 'requires (dev)');

        if ($package->getSuggests()) {
            $io->write("\n<info>suggests</info>");
            foreach ($package->getSuggests() as $suggested => $reason) {
                $io->write($suggested . ' <comment>' . $reason . '</comment>');
            }
        }

        $this->printLinks($package, Link::TYPE_PROVIDE);
        $this->printLinks($package, Link::TYPE_CONFLICT);
        $this->printLinks($package, Link::TYPE_REPLACE);
    }

    /**
     * Prints package metadata.
     *
     * @param array<string, string>    $versions
     */
    protected function printMeta(CompletePackageInterface $package, array $versions, InstalledRepository $installedRepo, ?PackageInterface $latestPackage = null): void
    {
        $io = $this->getIO();
        $io->write('<info>name</info>     : ' . $package->getPrettyName());
        $io->write('<info>descrip.</info> : ' . $package->getDescription());
        $io->write('<info>keywords</info> : ' . implode(', ', $package->getKeywords() ?: []));
        $this->printVersions($package, $versions, $installedRepo);
        if ($latestPackage) {
            $style = $this->getVersionStyle($latestPackage, $package);
            $io->write('<info>latest</info>   : <'.$style.'>' . $latestPackage->getPrettyVersion() . '</'.$style.'>');
        } else {
            $latestPackage = $package;
        }
        $io->write('<info>type</info>     : ' . $package->getType());
        $this->printLicenses($package);
        $io->write('<info>homepage</info> : ' . $package->getHomepage());
        $io->write('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getSourceType(), $package->getSourceUrl(), $package->getSourceReference()));
        $io->write('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getDistType(), $package->getDistUrl(), $package->getDistReference()));
        if (!PlatformRepository::isPlatformPackage($package->getName()) && $installedRepo->hasPackage($package)) {
            $path = $this->requireComposer()->getInstallationManager()->getInstallPath($package);
            if (is_string($path)) {
                $io->write('<info>path</info>     : ' . realpath($path));
            } else {
                $io->write('<info>path</info>     : null');
            }
        }
        $io->write('<info>names</info>    : ' . implode(', ', $package->getNames()));

        if ($latestPackage instanceof CompletePackageInterface && $latestPackage->isAbandoned()) {
            $replacement = ($latestPackage->getReplacementPackage() !== null)
                ? ' The author suggests using the ' . $latestPackage->getReplacementPackage(). ' package instead.'
                : null;

            $io->writeError(
                sprintf('<warning>Attention: This package is abandoned and no longer maintained.%s</warning>', $replacement)
            );
        }

        if ($package->getSupport()) {
            $io->write("\n<info>support</info>");
            foreach ($package->getSupport() as $type => $value) {
                $io->write('<comment>' . $type . '</comment> : '.$value);
            }
        }

        if (\count($package->getAutoload()) > 0) {
            $io->write("\n<info>autoload</info>");
            $autoloadConfig = $package->getAutoload();
            foreach ($autoloadConfig as $type => $autoloads) {
                $io->write('<comment>' . $type . '</comment>');

                if ($type === 'psr-0' || $type === 'psr-4') {
                    foreach ($autoloads as $name => $path) {
                        $io->write(($name ?: '*') . ' => ' . (is_array($path) ? implode(', ', $path) : ($path ?: '.')));
                    }
                } elseif ($type === 'classmap') {
                    $io->write(implode(', ', $autoloadConfig[$type]));
                }
            }
            if ($package->getIncludePaths()) {
                $io->write('<comment>include-path</comment>');
                $io->write(implode(', ', $package->getIncludePaths()));
            }
        }
    }

    /**
     * Prints all available versions of this package and highlights the installed one if any.
     *
     * @param array<string, string> $versions
     */
    protected function printVersions(CompletePackageInterface $package, array $versions, InstalledRepository $installedRepo): void
    {
        $versions = array_keys($versions);
        $versions = Semver::rsort($versions);

        // highlight installed version
        if ($installedPackages = $installedRepo->findPackages($package->getName())) {
            foreach ($installedPackages as $installedPackage) {
                $installedVersion = $installedPackage->getPrettyVersion();
                $key = array_search($installedVersion, $versions);
                if (false !== $key) {
                    $versions[$key] = '<info>* ' . $installedVersion . '</info>';
                }
            }
        }

        $versions = implode(', ', $versions);

        $this->getIO()->write('<info>versions</info> : ' . $versions);
    }

    /**
     * print link objects
     *
     * @param string                   $title
     */
    protected function printLinks(CompletePackageInterface $package, string $linkType, ?string $title = null): void
    {
        $title = $title ?: $linkType;
        $io = $this->getIO();
        if ($links = $package->{'get'.ucfirst($linkType)}()) {
            $io->write("\n<info>" . $title . "</info>");

            foreach ($links as $link) {
                $io->write($link->getTarget() . ' <comment>' . $link->getPrettyConstraint() . '</comment>');
            }
        }
    }

    /**
     * Prints the licenses of a package with metadata
     */
    protected function printLicenses(CompletePackageInterface $package): void
    {
        $spdxLicenses = new SpdxLicenses();

        $licenses = $package->getLicense();
        $io = $this->getIO();

        foreach ($licenses as $licenseId) {
            $license = $spdxLicenses->getLicenseByIdentifier($licenseId); // keys: 0 fullname, 1 osi, 2 url

            if (!$license) {
                $out = $licenseId;
            } else {
                // is license OSI approved?
                if ($license[1] === true) {
                    $out = sprintf('%s (%s) (OSI approved) %s', $license[0], $licenseId, $license[2]);
                } else {
                    $out = sprintf('%s (%s) %s', $license[0], $licenseId, $license[2]);
                }
            }

            $io->write('<info>license</info>  : ' . $out);
        }
    }

    /**
     * Prints package info in JSON format.
     *
     * @param array<string, string>    $versions
     */
    protected function printPackageInfoAsJson(CompletePackageInterface $package, array $versions, InstalledRepository $installedRepo, ?PackageInterface $latestPackage = null): void
    {
        $json = [
            'name' => $package->getPrettyName(),
            'description' => $package->getDescription(),
            'keywords' => $package->getKeywords() ?: [],
            'type' => $package->getType(),
            'homepage' => $package->getHomepage(),
            'names' => $package->getNames(),
        ];

        $json = $this->appendVersions($json, $versions);
        $json = $this->appendLicenses($json, $package);

        if ($latestPackage) {
            $json['latest'] = $latestPackage->getPrettyVersion();
        } else {
            $latestPackage = $package;
        }

        if (null !== $package->getSourceType()) {
            $json['source'] = [
                'type' => $package->getSourceType(),
                'url' => $package->getSourceUrl(),
                'reference' => $package->getSourceReference(),
            ];
        }

        if (null !== $package->getDistType()) {
            $json['dist'] = [
                'type' => $package->getDistType(),
                'url' => $package->getDistUrl(),
                'reference' => $package->getDistReference(),
            ];
        }

        if (!PlatformRepository::isPlatformPackage($package->getName()) && $installedRepo->hasPackage($package)) {
            $path = $this->requireComposer()->getInstallationManager()->getInstallPath($package);
            if (is_string($path)) {
                $path = realpath($path);
                if ($path !== false) {
                    $json['path'] = $path;
                }
            } else {
                $json['path'] = null;
            }
        }

        if ($latestPackage instanceof CompletePackageInterface && $latestPackage->isAbandoned()) {
            $json['replacement'] = $latestPackage->getReplacementPackage();
        }

        if ($package->getSuggests()) {
            $json['suggests'] = $package->getSuggests();
        }

        if ($package->getSupport()) {
            $json['support'] = $package->getSupport();
        }

        $json = $this->appendAutoload($json, $package);

        if ($package->getIncludePaths()) {
            $json['include_path'] = $package->getIncludePaths();
        }

        $json = $this->appendLinks($json, $package);

        $this->getIO()->write(JsonFile::encode($json));
    }

    /**
     * @param JsonStructure $json
     * @param array<string, string> $versions
     * @return JsonStructure
     */
    private function appendVersions(array $json, array $versions): array
    {
        uasort($versions, 'version_compare');
        $versions = array_keys(array_reverse($versions));
        $json['versions'] = $versions;

        return $json;
    }

    /**
     * @param JsonStructure $json
     * @return JsonStructure
     */
    private function appendLicenses(array $json, CompletePackageInterface $package): array
    {
        if ($licenses = $package->getLicense()) {
            $spdxLicenses = new SpdxLicenses();

            $json['licenses'] = array_map(static function ($licenseId) use ($spdxLicenses) {
                $license = $spdxLicenses->getLicenseByIdentifier($licenseId); // keys: 0 fullname, 1 osi, 2 url

                if (!$license) {
                    return $licenseId;
                }

                return [
                    'name' => $license[0],
                    'osi' => $licenseId,
                    'url' => $license[2],
                ];
            }, $licenses);
        }

        return $json;
    }

    /**
     * @param JsonStructure $json
     * @return JsonStructure
     */
    private function appendAutoload(array $json, CompletePackageInterface $package): array
    {
        if (\count($package->getAutoload()) > 0) {
            $autoload = [];

            foreach ($package->getAutoload() as $type => $autoloads) {
                if ($type === 'psr-0' || $type === 'psr-4') {
                    $psr = [];

                    foreach ($autoloads as $name => $path) {
                        if (!$path) {
                            $path = '.';
                        }

                        $psr[$name ?: '*'] = $path;
                    }

                    $autoload[$type] = $psr;
                } elseif ($type === 'classmap') {
                    $autoload['classmap'] = $autoloads;
                }
            }

            $json['autoload'] = $autoload;
        }

        return $json;
    }

    /**
     * @param JsonStructure $json
     * @return JsonStructure
     */
    private function appendLinks(array $json, CompletePackageInterface $package): array
    {
        foreach (Link::$TYPES as $linkType) {
            $json = $this->appendLink($json, $package, $linkType);
        }

        return $json;
    }

    /**
     * @param JsonStructure $json
     * @return JsonStructure
     */
    private function appendLink(array $json, CompletePackageInterface $package, string $linkType): array
    {
        $links = $package->{'get' . ucfirst($linkType)}();

        if ($links) {
            $json[$linkType] = [];

            foreach ($links as $link) {
                $json[$linkType][$link->getTarget()] = $link->getPrettyConstraint();
            }
        }

        return $json;
    }

    /**
     * Init styles for tree
     */
    protected function initStyles(OutputInterface $output): void
    {
        $this->colors = [
            'green',
            'yellow',
            'cyan',
            'magenta',
            'blue',
        ];

        foreach ($this->colors as $color) {
            $style = new OutputFormatterStyle($color);
            $output->getFormatter()->setStyle($color, $style);
        }
    }

    /**
     * Display the tree
     *
     * @param array<int, array<string, string|mixed[]>> $arrayTree
     */
    protected function displayPackageTree(array $arrayTree): void
    {
        $io = $this->getIO();
        foreach ($arrayTree as $package) {
            $io->write(sprintf('<info>%s</info>', $package['name']), false);
            $io->write(' ' . $package['version'], false);
            if (isset($package['description'])) {
                $io->write(' ' . strtok($package['description'], "\r\n"));
            } else {
                // output newline
                $io->write('');
            }

            if (isset($package['requires'])) {
                $requires = $package['requires'];
                $treeBar = '├';
                $j = 0;
                $total = count($requires);
                foreach ($requires as $require) {
                    $requireName = $require['name'];
                    $j++;
                    if ($j === $total) {
                        $treeBar = '└';
                    }
                    $level = 1;
                    $color = $this->colors[$level];
                    $info = sprintf(
                        '%s──<%s>%s</%s> %s',
                        $treeBar,
                        $color,
                        $requireName,
                        $color,
                        $require['version']
                    );
                    $this->writeTreeLine($info);

                    $treeBar = str_replace('└', ' ', $treeBar);
                    $packagesInTree = [$package['name'], $requireName];

                    $this->displayTree($require, $packagesInTree, $treeBar, $level + 1);
                }
            }
        }
    }

    /**
     * Generate the package tree
     *
     * @return array<string, array<int, array<string, mixed[]|string>>|string|null>
     */
    protected function generatePackageTree(
        PackageInterface $package,
        InstalledRepository $installedRepo,
        RepositoryInterface $remoteRepos
    ): array {
        $requires = $package->getRequires();
        ksort($requires);
        $children = [];
        foreach ($requires as $requireName => $require) {
            $packagesInTree = [$package->getName(), $requireName];

            $treeChildDesc = [
                'name' => $requireName,
                'version' => $require->getPrettyConstraint(),
            ];

            $deepChildren = $this->addTree($requireName, $require, $installedRepo, $remoteRepos, $packagesInTree);

            if ($deepChildren) {
                $treeChildDesc['requires'] = $deepChildren;
            }

            $children[] = $treeChildDesc;
        }
        $tree = [
            'name' => $package->getPrettyName(),
            'version' => $package->getPrettyVersion(),
            'description' => $package instanceof CompletePackageInterface ? $package->getDescription() : '',
        ];

        if ($children) {
            $tree['requires'] = $children;
        }

        return $tree;
    }

    /**
     * Display a package tree
     *
     * @param array<string, array<int, array<string, mixed[]|string>>|string|null>|string $package
     * @param array<int, string|mixed[]> $packagesInTree
     */
    protected function displayTree(
        $package,
        array $packagesInTree,
        string $previousTreeBar = '├',
        int $level = 1
    ): void {
        $previousTreeBar = str_replace('├', '│', $previousTreeBar);
        if (is_array($package) && isset($package['requires'])) {
            $requires = $package['requires'];
            $treeBar = $previousTreeBar . '  ├';
            $i = 0;
            $total = count($requires);
            foreach ($requires as $require) {
                $currentTree = $packagesInTree;
                $i++;
                if ($i === $total) {
                    $treeBar = $previousTreeBar . '  └';
                }
                $colorIdent = $level % count($this->colors);
                $color = $this->colors[$colorIdent];

                assert(is_string($require['name']));
                assert(is_string($require['version']));

                $circularWarn = in_array(
                    $require['name'],
                    $currentTree,
                    true
                ) ? '(circular dependency aborted here)' : '';
                $info = rtrim(sprintf(
                    '%s──<%s>%s</%s> %s %s',
                    $treeBar,
                    $color,
                    $require['name'],
                    $color,
                    $require['version'],
                    $circularWarn
                ));
                $this->writeTreeLine($info);

                $treeBar = str_replace('└', ' ', $treeBar);

                $currentTree[] = $require['name'];
                $this->displayTree($require, $currentTree, $treeBar, $level + 1);
            }
        }
    }

    /**
     * Display a package tree
     *
     * @param  string[] $packagesInTree
     * @return array<int, array<string, array<int, array<string, string>>|string>>
     */
    protected function addTree(
        string $name,
        Link $link,
        InstalledRepository $installedRepo,
        RepositoryInterface $remoteRepos,
        array $packagesInTree
    ): array {
        $children = [];
        [$package] = $this->getPackage(
            $installedRepo,
            $remoteRepos,
            $name,
            $link->getPrettyConstraint() === 'self.version' ? $link->getConstraint() : $link->getPrettyConstraint()
        );
        if (is_object($package)) {
            $requires = $package->getRequires();
            ksort($requires);
            foreach ($requires as $requireName => $require) {
                $currentTree = $packagesInTree;

                $treeChildDesc = [
                    'name' => $requireName,
                    'version' => $require->getPrettyConstraint(),
                ];

                if (!in_array($requireName, $currentTree, true)) {
                    $currentTree[] = $requireName;
                    $deepChildren = $this->addTree($requireName, $require, $installedRepo, $remoteRepos, $currentTree);
                    if ($deepChildren) {
                        $treeChildDesc['requires'] = $deepChildren;
                    }
                }

                $children[] = $treeChildDesc;
            }
        }

        return $children;
    }

    private function updateStatusToVersionStyle(string $updateStatus): string
    {
        // 'up-to-date' is printed green
        // 'semver-safe-update' is printed red
        // 'update-possible' is printed yellow
        return str_replace(['up-to-date', 'semver-safe-update', 'update-possible'], ['info', 'highlight', 'comment'], $updateStatus);
    }

    private function getUpdateStatus(PackageInterface $latestPackage, PackageInterface $package): string
    {
        if ($latestPackage->getFullPrettyVersion() === $package->getFullPrettyVersion()) {
            return 'up-to-date';
        }

        $constraint = $package->getVersion();
        if (0 !== strpos($constraint, 'dev-')) {
            $constraint = '^'.$constraint;
        }
        if ($latestPackage->getVersion() && Semver::satisfies($latestPackage->getVersion(), $constraint)) {
            // it needs an immediate semver-compliant upgrade
            return 'semver-safe-update';
        }

        // it needs an upgrade but has potential BC breaks so is not urgent
        return 'update-possible';
    }

    private function writeTreeLine(string $line): void
    {
        $io = $this->getIO();
        if (!$io->isDecorated()) {
            $line = str_replace(['└', '├', '──', '│'], ['`-', '|-', '-', '|'], $line);
        }

        $io->write($line);
    }

    /**
     * Given a package, this finds the latest package matching it
     */
    private function findLatestPackage(PackageInterface $package, Composer $composer, PlatformRepository $platformRepo, bool $majorOnly, bool $minorOnly, bool $patchOnly, PlatformRequirementFilterInterface $platformReqFilter): ?PackageInterface
    {
        // find the latest version allowed in this repo set
        $name = $package->getName();
        $versionSelector = new VersionSelector($this->getRepositorySet($composer), $platformRepo);
        $stability = $composer->getPackage()->getMinimumStability();
        $flags = $composer->getPackage()->getStabilityFlags();
        if (isset($flags[$name])) {
            $stability = array_search($flags[$name], BasePackage::$stabilities, true);
        }

        $bestStability = $stability;
        if ($composer->getPackage()->getPreferStable()) {
            $bestStability = $package->getStability();
        }

        $targetVersion = null;
        if (0 === strpos($package->getVersion(), 'dev-')) {
            $targetVersion = $package->getVersion();

            // dev-x branches are considered to be on the latest major version always, do not look up for a new commit as that is deemed a minor upgrade (albeit risky)
            if ($majorOnly) {
                return null;
            }
        }

        if ($targetVersion === null) {
            if ($majorOnly && Preg::isMatch('{^(?P<zero_major>(?:0\.)+)?(?P<first_meaningful>\d+)\.}', $package->getVersion(), $match)) {
                $targetVersion = '>='.$match['zero_major'].(((int) $match['first_meaningful']) + 1).',<9999999-dev';
            }

            if ($minorOnly) {
                $targetVersion = '^'.$package->getVersion();
            }

            if ($patchOnly) {
                $trimmedVersion = Preg::replace('{(\.0)+$}D', '', $package->getVersion());
                $partsNeeded = substr($trimmedVersion, 0, 1) === '0' ? 4 : 3;
                while (substr_count($trimmedVersion, '.') + 1 < $partsNeeded) {
                    $trimmedVersion .= '.0';
                }
                $targetVersion = '~'.$trimmedVersion;
            }
        }

        if ($this->getIO()->isVerbose()) {
            $showWarnings = true;
        } else {
            $showWarnings = static function (PackageInterface $candidate) use ($package): bool {
                if (str_starts_with($candidate->getVersion(), 'dev-') || str_starts_with($package->getVersion(), 'dev-')) {
                    return false;
                }
                return version_compare($candidate->getVersion(), $package->getVersion(), '<=');
            };
        }
        $candidate = $versionSelector->findBestCandidate($name, $targetVersion, $bestStability, $platformReqFilter, 0, $this->getIO(), $showWarnings);
        while ($candidate instanceof AliasPackage) {
            $candidate = $candidate->getAliasOf();
        }

        return $candidate !== false ? $candidate : null;
    }

    private function getRepositorySet(Composer $composer): RepositorySet
    {
        if (!$this->repositorySet) {
            $this->repositorySet = new RepositorySet($composer->getPackage()->getMinimumStability(), $composer->getPackage()->getStabilityFlags());
            $this->repositorySet->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));
        }

        return $this->repositorySet;
    }
}
