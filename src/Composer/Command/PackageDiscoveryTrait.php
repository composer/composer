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

use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
trait PackageDiscoveryTrait
{
    /** @var ?CompositeRepository */
    private $repos;
    /** @var RepositorySet[] */
    private $repositorySets;

    protected function getRepos(): CompositeRepository
    {
        if (null === $this->repos) {
            $this->repos = new CompositeRepository(array_merge(
                [new PlatformRepository],
                RepositoryFactory::defaultReposWithDefaultManager($this->getIO())
            ));
        }

        return $this->repos;
    }

    /**
     * @param key-of<BasePackage::STABILITIES>|null $minimumStability
     */
    private function getRepositorySet(InputInterface $input, ?string $minimumStability = null): RepositorySet
    {
        $key = $minimumStability ?? 'default';

        if (!isset($this->repositorySets[$key])) {
            $this->repositorySets[$key] = $repositorySet = new RepositorySet($minimumStability ?? $this->getMinimumStability($input));
            $repositorySet->addRepository($this->getRepos());
        }

        return $this->repositorySets[$key];
    }

    /**
     * @return key-of<BasePackage::STABILITIES>
     */
    private function getMinimumStability(InputInterface $input): string
    {
        if ($input->hasOption('stability')) { // @phpstan-ignore-line as InitCommand does have this option but not all classes using this trait do
            return VersionParser::normalizeStability($input->getOption('stability') ?? 'stable');
        }

        // @phpstan-ignore-next-line as RequireCommand does not have the option above so this code is reachable there
        $file = Factory::getComposerFile();
        if (is_file($file) && Filesystem::isReadable($file) && is_array($composer = json_decode((string) file_get_contents($file), true))) {
            if (isset($composer['minimum-stability'])) {
                return VersionParser::normalizeStability($composer['minimum-stability']);
            }
        }

        return 'stable';
    }

    /**
     * @param array<string> $requires
     *
     * @return array<string>
     * @throws \Exception
     */
    final protected function determineRequirements(InputInterface $input, OutputInterface $output, array $requires = [], ?PlatformRepository $platformRepo = null, string $preferredStability = 'stable', bool $useBestVersionConstraint = true, bool $fixed = false): array
    {
        $composer = $this->tryComposer();
        if (count($requires) > 0) {
            $requires = $this->normalizeRequirements($requires);
            $result = [];
            $io = $this->getIO();

            foreach ($requires as $requirement) {
                if (isset($requirement['version']) && Preg::isMatch('{^\d+(\.\d+)?$}', $requirement['version'])) {
                    $io->writeError('<warning>The "'.$requirement['version'].'" constraint for "'.$requirement['name'].'" appears too strict and will likely not match what you want. See https://getcomposer.org/constraints</warning>');
                }

                //automatically add package repository if local
                $repos = [];
                if(null !== $composer){
                    $repos = $composer->getRepositoryManager()->getRepositories();
                }

                $repoURLs = [];

                foreach($repos as $repo){
                    if (!$repo instanceof ConfigurableRepositoryInterface) {
                        continue;
                    }
                    $repoConfig = $repo->getRepoConfig();
                    $repoURLs[] = str_replace("\\", '/', $repoConfig['url']);
                }

                $fs = new Filesystem();
                $isLocalPackage = $fs->isAbsolutePath($requirement['name'])
                || $requirement['name'][0] === '.';

                if($isLocalPackage){

                    $treatedPath = str_replace("\\", '/', $requirement['name']);

                    $jsonFileInstance = new JsonFile($treatedPath . '/composer.json');

                    if (!$jsonFileInstance->exists()) {
                        throw new \Exception($treatedPath . " was not found");
                    }

                    $jsonConfigFromPackage = $jsonFileInstance->read();
                    if (!isset($jsonConfigFromPackage['name'])) {
                        throw new \Exception("Package name inside composer.json at " . $treatedPath . " is not set");
                    }

                    $requirement['name'] = $jsonConfigFromPackage['name'];

                    if(!in_array($treatedPath, $repoURLs, true)){
                        $configSource = new JsonConfigSource(new JsonFile('composer.json'));

                        $configSource->addRepository($requirement['name'], [
                            'type' => 'path',
                            'url' => $treatedPath
                        ], true);
                    }
                }

                if (!isset($requirement['version'])) {
                    if($isLocalPackage){
                        $requirement['version'] = 'dev-main';
                    } else {
                        // determine the best version automatically
                        [$name, $version] = $this->findBestVersionAndNameForPackage($this->getIO(), $input, $requirement['name'], $platformRepo, $preferredStability, $fixed);

                        // replace package name from packagist.org
                        $requirement['name'] = $name;

                        if ($useBestVersionConstraint) {
                            $requirement['version'] = $version;
                            $io->writeError(sprintf(
                                'Using version <info>%s</info> for <info>%s</info>',
                                $requirement['version'],
                                $requirement['name']
                            ));
                        } else {
                            $requirement['version'] = 'guess';
                        }
                    }
                }

                $result[] = $requirement['name'] . ' ' . $requirement['version'];
            }

            return $result;
        }

        $versionParser = new VersionParser();

        // Collect existing packages
        $installedRepo = null;
        if (null !== $composer) {
            $installedRepo = $composer->getRepositoryManager()->getLocalRepository();
        }
        $existingPackages = [];
        if (null !== $installedRepo) {
            foreach ($installedRepo->getPackages() as $package) {
                $existingPackages[] = $package->getName();
            }
        }
        unset($composer, $installedRepo);

        $io = $this->getIO();
        while (null !== $package = $io->ask('Search for a package: ')) {
            $matches = $this->getRepos()->search($package);

            if (count($matches) > 0) {
                // Remove existing packages from search results.
                foreach ($matches as $position => $foundPackage) {
                    if (in_array($foundPackage['name'], $existingPackages, true)) {
                        unset($matches[$position]);
                    }
                }
                $matches = array_values($matches);

                $exactMatch = false;
                foreach ($matches as $match) {
                    if ($match['name'] === $package) {
                        $exactMatch = true;
                        break;
                    }
                }

                // no match, prompt which to pick
                if (!$exactMatch) {
                    $providers = $this->getRepos()->getProviders($package);
                    if (count($providers) > 0) {
                        array_unshift($matches, ['name' => $package, 'description' => '']);
                    }

                    $choices = [];
                    foreach ($matches as $position => $foundPackage) {
                        $abandoned = '';
                        if (isset($foundPackage['abandoned'])) {
                            if (is_string($foundPackage['abandoned'])) {
                                $replacement = sprintf('Use %s instead', $foundPackage['abandoned']);
                            } else {
                                $replacement = 'No replacement was suggested';
                            }
                            $abandoned = sprintf('<warning>Abandoned. %s.</warning>', $replacement);
                        }

                        $choices[] = sprintf(' <info>%5s</info> %s %s', "[$position]", $foundPackage['name'], $abandoned);
                    }

                    $io->writeError([
                        '',
                        sprintf('Found <info>%s</info> packages matching <info>%s</info>', count($matches), $package),
                        '',
                    ]);

                    $io->writeError($choices);
                    $io->writeError('');

                    $validator = static function (string $selection) use ($matches, $versionParser) {
                        if ('' === $selection) {
                            return false;
                        }

                        if (is_numeric($selection) && isset($matches[(int) $selection])) {
                            $package = $matches[(int) $selection];

                            return $package['name'];
                        }

                        if (Preg::isMatch('{^\s*(?P<name>[\S/]+)(?:\s+(?P<version>\S+))?\s*$}', $selection, $packageMatches)) {
                            if (isset($packageMatches['version'])) {
                                // parsing `acme/example ~2.3`

                                // validate version constraint
                                $versionParser->parseConstraints($packageMatches['version']);

                                return $packageMatches['name'].' '.$packageMatches['version'];
                            }

                            // parsing `acme/example`
                            return $packageMatches['name'];
                        }

                        throw new \Exception('Not a valid selection');
                    };

                    $package = $io->askAndValidate(
                        'Enter package # to add, or the complete package name if it is not listed: ',
                        $validator,
                        3,
                        ''
                    );
                }

                // no constraint yet, determine the best version automatically
                if (false !== $package && false === strpos($package, ' ')) {
                    $validator = static function (string $input) {
                        $input = trim($input);

                        return strlen($input) > 0 ? $input : false;
                    };

                    $constraint = $io->askAndValidate(
                        'Enter the version constraint to require (or leave blank to use the latest version): ',
                        $validator,
                        3,
                        ''
                    );

                    if (false === $constraint) {
                        [, $constraint] = $this->findBestVersionAndNameForPackage($this->getIO(), $input, $package, $platformRepo, $preferredStability);

                        $io->writeError(sprintf(
                            'Using version <info>%s</info> for <info>%s</info>',
                            $constraint,
                            $package
                        ));
                    }

                    $package .= ' '.$constraint;
                }

                if (false !== $package) {
                    $requires[] = $package;
                    $existingPackages[] = explode(' ', $package)[0];
                }
            }
        }

        return $requires;
    }

    /**
     * Given a package name, this determines the best version to use in the require key.
     *
     * This returns a version with the ~ operator prefixed when possible.
     *
     * @throws \InvalidArgumentException
     * @return array{string, string}     name version
     */
    private function findBestVersionAndNameForPackage(IOInterface $io, InputInterface $input, string $name, ?PlatformRepository $platformRepo = null, string $preferredStability = 'stable', bool $fixed = false): array
    {
        // handle ignore-platform-reqs flag if present
        if ($input->hasOption('ignore-platform-reqs') && $input->hasOption('ignore-platform-req')) {
            $platformRequirementFilter = $this->getPlatformRequirementFilter($input);
        } else {
            $platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();
        }

        // find the latest version allowed in this repo set
        $repoSet = $this->getRepositorySet($input);
        $versionSelector = new VersionSelector($repoSet, $platformRepo);
        $effectiveMinimumStability = $this->getMinimumStability($input);

        $package = $versionSelector->findBestCandidate($name, null, $preferredStability, $platformRequirementFilter, 0, $this->getIO());

        if (false === $package) {
            // platform packages can not be found in the pool in versions other than the local platform's has
            // so if platform reqs are ignored we just take the user's word for it
            if ($platformRequirementFilter->isIgnored($name)) {
                return [$name, '*'];
            }

            // Check if it is a virtual package provided by others
            $providers = $repoSet->getProviders($name);
            if (count($providers) > 0) {
                $constraint = '*';
                if ($input->isInteractive()) {
                    $constraint = $this->getIO()->askAndValidate('Package "<info>'.$name.'</info>" does not exist but is provided by '.count($providers).' packages. Which version constraint would you like to use? [<info>*</info>] ', static function ($value) {
                        $parser = new VersionParser();
                        $parser->parseConstraints($value);

                        return $value;
                    }, 3, '*');
                }

                return [$name, $constraint];
            }

            // Check whether the package requirements were the problem
            if (!($platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter) && false !== ($candidate = $versionSelector->findBestCandidate($name, null, $preferredStability, PlatformRequirementFilterFactory::ignoreAll()))) {
                throw new \InvalidArgumentException(sprintf(
                    'Package %s has requirements incompatible with your PHP version, PHP extensions and Composer version' . $this->getPlatformExceptionDetails($candidate, $platformRepo),
                    $name
                ));
            }
            // Check whether the minimum stability was the problem but the package exists
            if (false !== ($package = $versionSelector->findBestCandidate($name, null, $preferredStability, $platformRequirementFilter, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES))) {
                // we must first verify if a valid package would be found in a lower priority repository
                if (false !== ($allReposPackage = $versionSelector->findBestCandidate($name, null, $preferredStability, $platformRequirementFilter, RepositorySet::ALLOW_SHADOWED_REPOSITORIES))) {
                    throw new \InvalidArgumentException(
                        'Package '.$name.' exists in '.$allReposPackage->getRepository()->getRepoName().' and '.$package->getRepository()->getRepoName().' which has a higher repository priority. The packages from the higher priority repository do not match your minimum-stability and are therefore not installable. That repository is canonical so the lower priority repo\'s packages are not installable. See https://getcomposer.org/repoprio for details and assistance.'
                    );
                }

                throw new \InvalidArgumentException(sprintf(
                    'Could not find a version of package %s matching your minimum-stability (%s). Require it with an explicit version constraint allowing its desired stability.',
                    $name,
                    $effectiveMinimumStability
                ));
            }
            // Check whether the PHP version was the problem for all versions
            if (!$platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter && false !== ($candidate = $versionSelector->findBestCandidate($name, null, $preferredStability, PlatformRequirementFilterFactory::ignoreAll(), RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES))) {
                $additional = '';
                if (false === $versionSelector->findBestCandidate($name, null, $preferredStability, PlatformRequirementFilterFactory::ignoreAll())) {
                    $additional = PHP_EOL.PHP_EOL.'Additionally, the package was only found with a stability of "'.$candidate->getStability().'" while your minimum stability is "'.$effectiveMinimumStability.'".';
                }

                throw new \InvalidArgumentException(sprintf(
                    'Could not find package %s in any version matching your PHP version, PHP extensions and Composer version' . $this->getPlatformExceptionDetails($candidate, $platformRepo) . '%s',
                    $name,
                    $additional
                ));
            }

            // Check for similar names/typos
            $similar = $this->findSimilar($name);
            if (count($similar) > 0) {
                if (in_array($name, $similar, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        "Could not find package %s. It was however found via repository search, which indicates a consistency issue with the repository.",
                        $name
                    ));
                }

                if ($input->isInteractive()) {
                    $result = $io->select("<error>Could not find package $name.</error>\nPick one of these or leave empty to abort:", $similar, false, 1);
                    if ($result !== false) {
                        return $this->findBestVersionAndNameForPackage($io, $input, $similar[$result], $platformRepo, $preferredStability, $fixed);
                    }
                }

                throw new \InvalidArgumentException(sprintf(
                    "Could not find package %s.\n\nDid you mean " . (count($similar) > 1 ? 'one of these' : 'this') . "?\n    %s",
                    $name,
                    implode("\n    ", $similar)
                ));
            }

            throw new \InvalidArgumentException(sprintf(
                'Could not find a matching version of package %s. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability (%s).',
                $name,
                $effectiveMinimumStability
            ));
        }

        return [
            $package->getPrettyName(),
            $fixed ? $package->getPrettyVersion() : $versionSelector->findRecommendedRequireVersion($package),
        ];
    }

    /**
     * @return array<string>
     */
    private function findSimilar(string $package): array
    {
        try {
            if (null === $this->repos) {
                throw new \LogicException('findSimilar was called before $this->repos was initialized');
            }
            $results = $this->repos->search($package);
        } catch (\Throwable $e) {
            if ($e instanceof \LogicException) {
                throw $e;
            }

            // ignore search errors
            return [];
        }
        $similarPackages = [];

        $installedRepo = $this->requireComposer()->getRepositoryManager()->getLocalRepository();

        foreach ($results as $result) {
            if (null !== $installedRepo->findPackage($result['name'], '*')) {
                // Ignore installed package
                continue;
            }
            $similarPackages[$result['name']] = levenshtein($package, $result['name']);
        }
        asort($similarPackages);

        return array_keys(array_slice($similarPackages, 0, 5));
    }

    private function getPlatformExceptionDetails(PackageInterface $candidate, ?PlatformRepository $platformRepo = null): string
    {
        $details = [];
        if (null === $platformRepo) {
            return '';
        }

        foreach ($candidate->getRequires() as $link) {
            if (!PlatformRepository::isPlatformPackage($link->getTarget())) {
                continue;
            }
            $platformPkg = $platformRepo->findPackage($link->getTarget(), '*');
            if (null === $platformPkg) {
                if ($platformRepo->isPlatformPackageDisabled($link->getTarget())) {
                    $details[] = $candidate->getPrettyName().' '.$candidate->getPrettyVersion().' requires '.$link->getTarget().' '.$link->getPrettyConstraint().' but it is disabled by your platform config. Enable it again with "composer config platform.'.$link->getTarget().' --unset".';
                } else {
                    $details[] = $candidate->getPrettyName().' '.$candidate->getPrettyVersion().' requires '.$link->getTarget().' '.$link->getPrettyConstraint().' but it is not present.';
                }
                continue;
            }
            if (!$link->getConstraint()->matches(new Constraint('==', $platformPkg->getVersion()))) {
                $platformPkgVersion = $platformPkg->getPrettyVersion();
                $platformExtra = $platformPkg->getExtra();
                if (isset($platformExtra['config.platform']) && $platformPkg instanceof CompletePackageInterface) {
                    $platformPkgVersion .= ' ('.$platformPkg->getDescription().')';
                }
                $details[] = $candidate->getPrettyName().' '.$candidate->getPrettyVersion().' requires '.$link->getTarget().' '.$link->getPrettyConstraint().' which does not match your installed version '.$platformPkgVersion.'.';
            }
        }

        if (count($details) === 0) {
            return '';
        }

        return ':'.PHP_EOL.'  - ' . implode(PHP_EOL.'  - ', $details);
    }
}
