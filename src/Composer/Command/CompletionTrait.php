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
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RootPackageRepository;
use Symfony\Component\Console\Completion\CompletionInput;

/**
 * Adds completion to arguments and options.
 *
 * @internal
 */
trait CompletionTrait
{
    /**
     * @see BaseCommand::requireComposer()
     */
    abstract public function requireComposer(bool $disablePlugins = null, bool $disableScripts = null): Composer;

    /**
     * Suggestion values for "prefer-install" option
     *
     * @return string[]
     */
    private function suggestPreferInstall(): array
    {
        return ['dist', 'source', 'auto'];
    }

    /**
     * Suggest package names from root requirements.
     */
    private function suggestRootRequirement(): \Closure
    {
        return function (CompletionInput $input): array {
            $composer = $this->requireComposer();

            return array_merge(array_keys($composer->getPackage()->getRequires()), array_keys($composer->getPackage()->getDevRequires()));
        };
    }

    /**
     * Suggest package names from installed.
     */
    private function suggestInstalledPackage(bool $includePlatformPackages = false): \Closure
    {
        return function (CompletionInput $input) use ($includePlatformPackages): array {
            $composer = $this->requireComposer();
            $installedRepos = [new RootPackageRepository(clone $composer->getPackage())];

            $locker = $composer->getLocker();
            if ($locker->isLocked()) {
                $installedRepos[] = $locker->getLockedRepository(true);
            } else {
                $installedRepos[] = $composer->getRepositoryManager()->getLocalRepository();
            }

            $platformHint = [];
            if ($includePlatformPackages) {
                if ($locker->isLocked()) {
                    $platformRepo = new PlatformRepository(array(), $locker->getPlatformOverrides());
                } else {
                    $platformRepo = new PlatformRepository(array(), $composer->getConfig()->get('platform'));
                }
                if ($input->getCompletionValue() === '') {
                    // to reduce noise, when no text is yet entered we list only two entries for ext- and lib- prefixes
                    $hintsToFind = ['ext-' => 0, 'lib-' => 0, 'php' => 99, 'composer' => 99];
                    foreach ($platformRepo->getPackages() as $pkg) {
                        foreach ($hintsToFind as $hintPrefix => $hintCount) {
                            if (str_starts_with($pkg->getName(), $hintPrefix)) {
                                if ($hintCount === 0 || $hintCount >= 99) {
                                    $platformHint[] = $pkg->getName();
                                    $hintsToFind[$hintPrefix]++;
                                } elseif ($hintCount === 1) {
                                    unset($hintsToFind[$hintPrefix]);
                                    $platformHint[] = substr($pkg->getName(), 0, max(strlen($pkg->getName()) - 3, strlen($hintPrefix) + 1)).'...';
                                }
                                continue 2;
                            }
                        }
                    }
                } else {
                    $installedRepos[] = $platformRepo;
                }
            }

            $installedRepo = new InstalledRepository($installedRepos);

            return array_merge(
                array_map(function (PackageInterface $package) {
                    return $package->getName();
                }, $installedRepo->getPackages()),
                $platformHint
            );
        };
    }

    /**
     * Suggest package names available on all configured repositories.
     */
    private function suggestAvailablePackage(int $max = 99): \Closure
    {
        return function (CompletionInput $input) use ($max): array {
            if ($max < 1) {
                return [];
            }

            $composer = $this->requireComposer();
            $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());

            $results = [];
            $showVendors = false;
            if (!str_contains($input->getCompletionValue(), '/')) {
                $results = $repos->search('^' . preg_quote($input->getCompletionValue()), RepositoryInterface::SEARCH_VENDOR);
                $showVendors = true;
            }

            // if we get a single vendor, we expand it into its contents already
            if (\count($results) <= 1) {
                $results = $repos->search('^'.preg_quote($input->getCompletionValue()), RepositoryInterface::SEARCH_NAME);
                $showVendors = false;
            }

            $results = array_column($results, 'name');

            if ($showVendors) {
                $results = array_map(function (string $name): string {
                    return $name.'/';
                }, $results);

                // sort shorter results first to avoid auto-expanding the completion to a longer string than needed
                usort($results, function (string $a, string $b) {
                    $lenA = \strlen($a);
                    $lenB = \strlen($b);
                    if ($lenA === $lenB) {
                        return $a <=> $b;
                    }

                    return $lenA - $lenB;
                });

                $pinned = [];

                // ensure if the input is an exact match that it is always in the result set
                $completionInput = $input->getCompletionValue().'/';
                if (false !== ($exactIndex = array_search($completionInput, $results, true))) {
                    $pinned[] = $completionInput;
                    array_splice($results, $exactIndex, 1);
                }

                return array_merge($pinned, array_slice($results, 0, $max - \count($pinned)));
            }

            return array_slice($results, 0, $max);
        };
    }

    /**
     * Suggest package names available on all configured repositories or
     * platform packages from the ones available on the currently-running PHP
     */
    private function suggestAvailablePackageInclPlatform(): \Closure
    {
        return function (CompletionInput $input): array {
            if (Preg::isMatch('{^(ext|lib|php)(-|$)|^com}', $input->getCompletionValue())) {
                $matches = $this->suggestPlatformPackage()($input);
            } else {
                $matches = [];
            }

            return array_merge($matches, $this->suggestAvailablePackage(99 - \count($matches))($input));
        };
    }

    /**
     * Suggest platform packages from the ones available on the currently-running PHP
     */
    private function suggestPlatformPackage(): \Closure
    {
        return function (CompletionInput $input): array {
            $repos = new PlatformRepository([], $this->requireComposer()->getConfig()->get('platform'));

            $pattern = BasePackage::packageNameToRegexp($input->getCompletionValue().'*');
            return array_filter(array_map(function (PackageInterface $package) {
                return $package->getName();
            }, $repos->getPackages()), function (string $name) use ($pattern): bool {
                return Preg::isMatch($pattern, $name);
            });
        };
    }
}
