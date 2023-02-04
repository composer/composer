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

namespace Composer\Package;

use Composer\Json\JsonFile;
use Composer\Installer\InstallationManager;
use Composer\Pcre\Preg;
use Composer\Repository\InstalledRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Util\ProcessExecutor;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;
use Composer\Util\Git as GitUtil;
use Composer\IO\IOInterface;
use Seld\JsonLint\ParsingException;

/**
 * Reads/writes project lockfile (composer.lock).
 *
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Locker
{
    /** @var JsonFile */
    private $lockFile;
    /** @var InstallationManager */
    private $installationManager;
    /** @var string */
    private $hash;
    /** @var string */
    private $contentHash;
    /** @var ArrayLoader */
    private $loader;
    /** @var ArrayDumper */
    private $dumper;
    /** @var ProcessExecutor */
    private $process;
    /** @var mixed[]|null */
    private $lockDataCache = null;
    /** @var bool */
    private $virtualFileWritten = false;

    /**
     * Initializes packages locker.
     *
     * @param JsonFile            $lockFile             lockfile loader
     * @param InstallationManager $installationManager  installation manager instance
     * @param string              $composerFileContents The contents of the composer file
     */
    public function __construct(IOInterface $io, JsonFile $lockFile, InstallationManager $installationManager, string $composerFileContents, ?ProcessExecutor $process = null)
    {
        $this->lockFile = $lockFile;
        $this->installationManager = $installationManager;
        $this->hash = md5($composerFileContents);
        $this->contentHash = self::getContentHash($composerFileContents);
        $this->loader = new ArrayLoader(null, true);
        $this->dumper = new ArrayDumper();
        $this->process = $process ?? new ProcessExecutor($io);
    }

    /**
     * Returns the md5 hash of the sorted content of the composer file.
     *
     * @param string $composerFileContents The contents of the composer file.
     */
    public static function getContentHash(string $composerFileContents): string
    {
        $content = JsonFile::parseJson($composerFileContents, 'composer.json', JsonFile::ALLOW_COMMENTS);

        $relevantKeys = [
            'name',
            'version',
            'require',
            'require-dev',
            'conflict',
            'replace',
            'provide',
            'minimum-stability',
            'prefer-stable',
            'repositories',
            'extra',
        ];

        $relevantContent = [];

        foreach (array_intersect($relevantKeys, array_keys($content)) as $key) {
            $relevantContent[$key] = $content[$key];
        }
        if (isset($content['config']['platform'])) {
            $relevantContent['config']['platform'] = $content['config']['platform'];
        }

        ksort($relevantContent);

        return md5(JsonFile::encode($relevantContent, 0));
    }

    /**
     * Checks whether locker has been locked (lockfile found).
     */
    public function isLocked(): bool
    {
        if (!$this->virtualFileWritten && !$this->lockFile->exists()) {
            return false;
        }

        $data = $this->getLockData();

        return isset($data['packages']);
    }

    /**
     * Checks whether the lock file is still up to date with the current hash
     */
    public function isFresh(): bool
    {
        $lock = $this->lockFile->read();

        if (!empty($lock['content-hash'])) {
            // There is a content hash key, use that instead of the file hash
            return $this->contentHash === $lock['content-hash'];
        }

        // BC support for old lock files without content-hash
        if (!empty($lock['hash'])) {
            return $this->hash === $lock['hash'];
        }

        // should not be reached unless the lock file is corrupted, so assume it's out of date
        return false;
    }

    /**
     * Searches and returns an array of locked packages, retrieved from registered repositories.
     *
     * @param  bool                                     $withDevReqs true to retrieve the locked dev packages
     * @throws \RuntimeException
     */
    public function getLockedRepository(bool $withDevReqs = false): LockArrayRepository
    {
        $lockData = $this->getLockData();
        $packages = new LockArrayRepository();

        $lockedPackages = $lockData['packages'];
        if ($withDevReqs) {
            if (isset($lockData['packages-dev'])) {
                $lockedPackages = array_merge($lockedPackages, $lockData['packages-dev']);
            } else {
                throw new \RuntimeException('The lock file does not contain require-dev information, run install with the --no-dev option or delete it and run composer update to generate a new lock file.');
            }
        }

        if (empty($lockedPackages)) {
            return $packages;
        }

        if (isset($lockedPackages[0]['name'])) {
            $packageByName = [];
            foreach ($lockedPackages as $info) {
                $package = $this->loader->load($info);
                $packages->addPackage($package);
                $packageByName[$package->getName()] = $package;

                if ($package instanceof AliasPackage) {
                    $packageByName[$package->getAliasOf()->getName()] = $package->getAliasOf();
                }
            }

            if (isset($lockData['aliases'])) {
                foreach ($lockData['aliases'] as $alias) {
                    if (isset($packageByName[$alias['package']])) {
                        $aliasPkg = new CompleteAliasPackage($packageByName[$alias['package']], $alias['alias_normalized'], $alias['alias']);
                        $aliasPkg->setRootPackageAlias(true);
                        $packages->addPackage($aliasPkg);
                    }
                }
            }

            return $packages;
        }

        throw new \RuntimeException('Your composer.lock is invalid. Run "composer update" to generate a new one.');
    }

    /**
     * @return string[] Names of dependencies installed through require-dev
     */
    public function getDevPackageNames(): array
    {
        $names = [];
        $lockData = $this->getLockData();
        if (isset($lockData['packages-dev'])) {
            foreach ($lockData['packages-dev'] as $package) {
                $names[] = strtolower($package['name']);
            }
        }

        return $names;
    }

    /**
     * Returns the platform requirements stored in the lock file
     *
     * @param  bool                     $withDevReqs if true, the platform requirements from the require-dev block are also returned
     * @return \Composer\Package\Link[]
     */
    public function getPlatformRequirements(bool $withDevReqs = false): array
    {
        $lockData = $this->getLockData();
        $requirements = [];

        if (!empty($lockData['platform'])) {
            $requirements = $this->loader->parseLinks(
                '__root__',
                '1.0.0',
                Link::TYPE_REQUIRE,
                $lockData['platform'] ?? []
            );
        }

        if ($withDevReqs && !empty($lockData['platform-dev'])) {
            $devRequirements = $this->loader->parseLinks(
                '__root__',
                '1.0.0',
                Link::TYPE_REQUIRE,
                $lockData['platform-dev'] ?? []
            );

            $requirements = array_merge($requirements, $devRequirements);
        }

        return $requirements;
    }

    public function getMinimumStability(): string
    {
        $lockData = $this->getLockData();

        return $lockData['minimum-stability'] ?? 'stable';
    }

    /**
     * @return array<string, string>
     */
    public function getStabilityFlags(): array
    {
        $lockData = $this->getLockData();

        return $lockData['stability-flags'] ?? [];
    }

    public function getPreferStable(): ?bool
    {
        $lockData = $this->getLockData();

        // return null if not set to allow caller logic to choose the
        // right behavior since old lock files have no prefer-stable
        return $lockData['prefer-stable'] ?? null;
    }

    public function getPreferLowest(): ?bool
    {
        $lockData = $this->getLockData();

        // return null if not set to allow caller logic to choose the
        // right behavior since old lock files have no prefer-lowest
        return $lockData['prefer-lowest'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getPlatformOverrides(): array
    {
        $lockData = $this->getLockData();

        return $lockData['platform-overrides'] ?? [];
    }

    /**
     * @return string[][]
     *
     * @phpstan-return list<array{package: string, version: string, alias: string, alias_normalized: string}>
     */
    public function getAliases(): array
    {
        $lockData = $this->getLockData();

        return $lockData['aliases'] ?? [];
    }

    /**
     * @return string
     */
    public function getPluginApi()
    {
        $lockData = $this->getLockData();

        return $lockData['plugin-api-version'] ?? '1.1.0';
    }

    /**
     * @return array<string, mixed>
     */
    public function getLockData(): array
    {
        if (null !== $this->lockDataCache) {
            return $this->lockDataCache;
        }

        if (!$this->lockFile->exists()) {
            throw new \LogicException('No lockfile found. Unable to read locked packages');
        }

        return $this->lockDataCache = $this->lockFile->read();
    }

    /**
     * Locks provided data into lockfile.
     *
     * @param PackageInterface[]          $packages          array of packages
     * @param PackageInterface[]|null     $devPackages       array of dev packages or null if installed without --dev
     * @param array<string, string>       $platformReqs      array of package name => constraint for required platform packages
     * @param array<string, string>       $platformDevReqs   array of package name => constraint for dev-required platform packages
     * @param string[][]                  $aliases           array of aliases
     * @param array<string, int>          $stabilityFlags
     * @param array<string, string|false> $platformOverrides
     * @param bool                        $write             Whether to actually write data to disk, useful in tests and for --dry-run
     *
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     */
    public function setLockData(array $packages, ?array $devPackages, array $platformReqs, array $platformDevReqs, array $aliases, string $minimumStability, array $stabilityFlags, bool $preferStable, bool $preferLowest, array $platformOverrides, bool $write = true): bool
    {
        // keep old default branch names normalized to DEFAULT_BRANCH_ALIAS for BC as that is how Composer 1 outputs the lock file
        // when loading the lock file the version is anyway ignored in Composer 2, so it has no adverse effect
        $aliases = array_map(static function ($alias): array {
            if (in_array($alias['version'], ['dev-master', 'dev-trunk', 'dev-default'], true)) {
                $alias['version'] = VersionParser::DEFAULT_BRANCH_ALIAS;
            }

            return $alias;
        }, $aliases);

        $lock = [
            '_readme' => ['This file locks the dependencies of your project to a known state',
                               'Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                               'This file is @gener'.'ated automatically', ],
            'content-hash' => $this->contentHash,
            'packages' => null,
            'packages-dev' => null,
            'aliases' => $aliases,
            'minimum-stability' => $minimumStability,
            'stability-flags' => $stabilityFlags,
            'prefer-stable' => $preferStable,
            'prefer-lowest' => $preferLowest,
        ];

        $lock['packages'] = $this->lockPackages($packages);
        if (null !== $devPackages) {
            $lock['packages-dev'] = $this->lockPackages($devPackages);
        }

        $lock['platform'] = $platformReqs;
        $lock['platform-dev'] = $platformDevReqs;
        if (\count($platformOverrides) > 0) {
            $lock['platform-overrides'] = $platformOverrides;
        }
        $lock['plugin-api-version'] = PluginInterface::PLUGIN_API_VERSION;

        try {
            $isLocked = $this->isLocked();
        } catch (ParsingException $e) {
            $isLocked = false;
        }
        if (!$isLocked || $lock !== $this->getLockData()) {
            if ($write) {
                $this->lockFile->write($lock);
                $this->lockDataCache = null;
                $this->virtualFileWritten = false;
            } else {
                $this->virtualFileWritten = true;
                $this->lockDataCache = JsonFile::parseJson(JsonFile::encode($lock));
            }

            return true;
        }

        return false;
    }

    /**
     * @param PackageInterface[] $packages
     *
     * @return mixed[][]
     *
     * @phpstan-return list<array<string, mixed>>
     */
    private function lockPackages(array $packages): array
    {
        $locked = [];

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $name = $package->getPrettyName();
            $version = $package->getPrettyVersion();

            if (!$name || !$version) {
                throw new \LogicException(sprintf(
                    'Package "%s" has no version or name and can not be locked',
                    $package
                ));
            }

            $spec = $this->dumper->dump($package);
            unset($spec['version_normalized']);

            // always move time to the end of the package definition
            $time = $spec['time'] ?? null;
            unset($spec['time']);
            if ($package->isDev() && $package->getInstallationSource() === 'source') {
                // use the exact commit time of the current reference if it's a dev package
                $time = $this->getPackageTime($package) ?: $time;
            }
            if (null !== $time) {
                $spec['time'] = $time;
            }

            unset($spec['installation-source']);

            $locked[] = $spec;
        }

        usort($locked, static function ($a, $b) {
            $comparison = strcmp($a['name'], $b['name']);

            if (0 !== $comparison) {
                return $comparison;
            }

            // If it is the same package, compare the versions to make the order deterministic
            return strcmp($a['version'], $b['version']);
        });

        return $locked;
    }

    /**
     * Returns the packages's datetime for its source reference.
     *
     * @param  PackageInterface $package The package to scan.
     * @return string|null      The formatted datetime or null if none was found.
     */
    private function getPackageTime(PackageInterface $package): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $path = realpath($this->installationManager->getInstallPath($package));
        $sourceType = $package->getSourceType();
        $datetime = null;

        if ($path && in_array($sourceType, ['git', 'hg'])) {
            $sourceRef = $package->getSourceReference() ?: $package->getDistReference();
            switch ($sourceType) {
                case 'git':
                    GitUtil::cleanEnv();

                    if (0 === $this->process->execute('git log -n1 --pretty=%ct '.ProcessExecutor::escape($sourceRef).GitUtil::getNoShowSignatureFlag($this->process), $output, $path) && Preg::isMatch('{^\s*\d+\s*$}', $output)) {
                        $datetime = new \DateTime('@'.trim($output), new \DateTimeZone('UTC'));
                    }
                    break;

                case 'hg':
                    if (0 === $this->process->execute('hg log --template "{date|hgdate}" -r '.ProcessExecutor::escape($sourceRef), $output, $path) && Preg::isMatch('{^\s*(\d+)\s*}', $output, $match)) {
                        $datetime = new \DateTime('@'.$match[1], new \DateTimeZone('UTC'));
                    }
                    break;
            }
        }

        return $datetime ? $datetime->format(DATE_RFC3339) : null;
    }

    /**
     * @return array<string>
     */
    public function getMissingRequirementInfo(RootPackageInterface $package, bool $includeDev): array
    {
        $missingRequirementInfo = [];
        $missingRequirements = false;
        $sets = [['repo' => $this->getLockedRepository(false), 'method' => 'getRequires', 'description' => 'Required']];
        if ($includeDev === true) {
            $sets[] = ['repo' => $this->getLockedRepository(true), 'method' => 'getDevRequires', 'description' => 'Required (in require-dev)'];
        }

        foreach ($sets as $set) {
            $installedRepo = new InstalledRepository([$set['repo']]);

            foreach (call_user_func([$package, $set['method']]) as $link) {
                if (PlatformRepository::isPlatformPackage($link->getTarget())) {
                    continue;
                }
                if ($link->getPrettyConstraint() === 'self.version') {
                    continue;
                }
                if ($installedRepo->findPackagesWithReplacersAndProviders($link->getTarget(), $link->getConstraint()) === []) {
                    $results = $installedRepo->findPackagesWithReplacersAndProviders($link->getTarget());
                    if ($results !== []) {
                        $provider = reset($results);
                        $missingRequirementInfo[] = '- ' . $set['description'].' package "' . $link->getTarget() . '" is in the lock file as "'.$provider->getPrettyVersion().'" but that does not satisfy your constraint "'.$link->getPrettyConstraint().'".';
                    } else {
                        $missingRequirementInfo[] = '- ' . $set['description'].' package "' . $link->getTarget() . '" is not present in the lock file.';
                    }
                    $missingRequirements = true;
                }
            }
        }

        if ($missingRequirements) {
            $missingRequirementInfo[] = 'This usually happens when composer files are incorrectly merged or the composer.json file is manually edited.';
            $missingRequirementInfo[] = 'Read more about correctly resolving merge conflicts https://getcomposer.org/doc/articles/resolving-merge-conflicts.md';
            $missingRequirementInfo[] = 'and prefer using the "require" command over editing the composer.json file directly https://getcomposer.org/doc/03-cli.md#require-r';
        }

        return $missingRequirementInfo;
    }
}
