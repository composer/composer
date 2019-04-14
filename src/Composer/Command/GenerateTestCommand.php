<?php

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

use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\ArrayRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTestCommand extends BaseCommand
{
    private $file;
    private $json;
    private $repos;
    private $composerDef;
    private $io;
    private $versionParser;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        // TODO probably hide this from list?
        $this
            ->setName('generate-test')
            ->setDescription('Helps debugging solver problems')
            ->setDefinition(array(
            ))
            ->setHelp(
                <<<EOT
EOT
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // always disable plugins
        $composer = $this->getComposer(true, true);
        $this->file = Factory::getComposerFile();

        if (!is_readable($this->file)) {
            $io->writeError('<error>'.$this->file.' is not readable.</error>');

            return 1;
        }
        if (!is_writable($this->file)) {
            $io->writeError('<error>'.$this->file.' is not writable.</error>');

            return 1;
        }

        $this->json = new JsonFile($this->file);
        $this->composerDef = $this->json->read();
        if (!isset($this->composerDef['repositories'])) {
            $this->composerDef['repositories'] = array();
        }

        $this->repos = $this->initializeRepos($composer);
        $this->io = $this->getIO();
        $this->versionParser = new \Composer\Semver\VersionParser();

        $rootPackage = $composer->getPackage();
        $inlinedMap = array();
        $this->inlinePackageNames(
            $inlinedMap,
            array_map(
                function ($link) {
                    return $link->getTarget();
                },
                $rootPackage->getRequires()
            )
        );

        $this->composerDef['repositories']['packagist.org'] = false;
        $this->json->write($this->composerDef);

        // now try to minimize the composer.json interactively
        if (!$this->verifyProblem()) {
            $this->io->writeError("Failed to generate a reproducing composer.json with all data inlined.");
            return 1;
        }

        $lastContents = $this->composerDef;
        $this->reduceRootToRequiredVersions($rootPackage);
        $this->json->write($this->composerDef);

        if (!$this->verifyProblem()) {
            $this->io->writeError("Failed to reduce composer.json requirements while keeping the problem reproducible. Continuing unreduced.");
            $this->composerDef = $lastContents;
            $this->json->write($this->composerDef);
        }

        $removedPackages = array();
        $requiredPackages = array();
        $requiredPackageVersions = array();
        $lastPackageRemoval = null;
        $lastPackageVersionRemoval = null;
        $lastContents = $this->composerDef;

        $jsonChanged = true;
        while ($jsonChanged) {
            $lastContents = $this->composerDef;
            $jsonChanged = false;

            // remove a package, see if issue is still reproducible
            foreach ($inlinedMap as $name => $repo) {
                if (!isset($requiredPackages[$name]) && !isset($removedPackages[$name])) {
                    $lastPackageRemoval = array(
                        'name' => $name,
                        'repo' => $repo,
                    );
                    unset($this->composerDef['repositories'][$name]);
                    $this->removeReferences($name);

                    $this->io->write('Attempting to remove package ' . $name . ' and all references to it');
                    $jsonChanged = true;
                    break;
                }
            }

            if (!$jsonChanged) {
                foreach ($inlinedMap as $name => $repo) {
                    if (isset($this->composerDef['repositories'][$name])) {
                        foreach ($this->composerDef['repositories'][$name]['package'] as $key => $version) {
                            if (!isset($requiredPackageVersions[$version['name'].':'.$version['version']])) {
                                $lastPackageVersionRemoval = $version;
                                unset($this->composerDef['repositories'][$name]['package'][$key]);
                                if (empty($this->composerDef['repositories'][$name]['package'])) {
                                    unset($this->composerDef['repositories'][$name]);
                                } else {
                                    // reorder for json encode
                                    $this->composerDef['repositories'][$name]['package'] = array_values($this->composerDef['repositories'][$name]['package']);
                                }

                                $this->io->write('Attempting to remove package version ' . $name . ':' . $version['version']);
                                $jsonChanged = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            $this->json->write($this->composerDef);

            $success = $this->verifyProblem();
            if (!$success) {
                $this->composerDef = $lastContents;

                if ($lastPackageRemoval) {
                    $requiredPackages[$lastPackageRemoval['name']] = $lastPackageRemoval['repo'];
                }
                if ($lastPackageVersionRemoval) {
                    $requiredPackageVersions[$lastPackageVersionRemoval['name'].':'.$lastPackageVersionRemoval['version']] = $lastPackageVersionRemoval;
                }
                $lastPackageRemoval = null;
                $lastPackageVersionRemoval = null;
            } else {
                if ($lastPackageRemoval) {
                    $removedPackages[$lastPackageRemoval['name']] = true;
                }
            }
        }

        $this->io->write("Current composer.json is the minimal possible file");

        return 0;
    }

    private function verifyProblem()
    {
        return $this->io->askConfirmation('Can you still reproduce the problem with the adjusted composer.json right now? (yes/no, default=yes)');
    }

    private function removeReferences($name)
    {
        foreach ($this->composerDef['repositories'] as $repoName => $repo) {
            if ($repo['type'] === 'package') {
                foreach ($repo['package'] as $key => $version) {
                    $this->removeKeyUnsetOnEmpty($this->composerDef['repositories'][$repoName]['package'][$key], 'require', $name);
                    $this->removeKeyUnsetOnEmpty($this->composerDef['repositories'][$repoName]['package'][$key], 'provide', $name);
                    $this->removeKeyUnsetOnEmpty($this->composerDef['repositories'][$repoName]['package'][$key], 'replace', $name);
                    $this->removeKeyUnsetOnEmpty($this->composerDef['repositories'][$repoName]['package'][$key], 'conflict', $name);
                }
            }
        }
    }

    private function removeKeyUnsetOnEmpty(&$data, $key1, $key2)
    {
        unset($data[$key1][$key2]);
        if (empty($data[$key1])) {
            unset($data[$key1]);
        }
    }

    private function reduceRootToRequiredVersions($rootPackage)
    {
        $requires = array();
        foreach ($rootPackage->getRequires() as $link) {
            if (!isset($requires[$link->getTarget()])) {
                $requires[$link->getTarget()] = array();
            }
            $requires[$link->getTarget()][] = $link->getConstraint();
        }

        $constraints = array();
        foreach ($requires as $name => $constraints) {
            $constraints[$name] = new MultiConstraint($constraints, false);
        }

        $reduced = array();
        $this->reduceToRequiredVersions($reduced, $constraints);

    }

    /**
     * @param Link[] $links
     */
    private function reduceToRequiredVersions(&$reduced, $requires)
    {
        $newRequires = array();

        foreach ($requires as $name => $constraint) {
            $this->io->write("Reducing versions for package $name");
            $reduced[$name] = $constraint;

            if (isset($this->composerDef['repositories'][$name]['package'])) {
                $reorder = false;
                foreach ($this->composerDef['repositories'][$name]['package'] as $key => $version) {
                    if (!$constraint->matches(new Constraint('==', $this->versionParser->normalize($version['version'])))) {
                        unset($this->composerDef['repositories'][$name]['package'][$key]);
                        $reorder = true;
                        if (empty($this->composerDef['repositories'][$name]['package'])) {
                            unset($this->composerDef['repositories'][$name]);
                            break;
                        }
                    }
                }
                if ($reorder && isset($this->composerDef['repositories'][$name]['package'])) {
                    $this->composerDef['repositories'][$name]['package'] = array_values($this->composerDef['repositories'][$name]['package']);
                }
            }
            if (isset($this->composerDef['repositories'][$name]['package'])) {
                foreach ($this->composerDef['repositories'][$name]['package'] as $key => $version) {
                    if (isset($version['require'])) {
                        foreach ($version['require'] as $newName => $newConstraint) {
                            if (!isset($reduced[$newName])) {
                                if (!isset($newRequires[$newName])) {
                                    $newRequires[$newName] = array();
                                }
                                $newRequires[$newName][] = $this->versionParser->parseConstraints($newConstraint);
                            }
                        }
                    }
                }
            }
        }

        foreach ($newRequires as $newName => $newConstraints) {
            $newRequires[$newName] = new MultiConstraint($newConstraints, false);
        }

        if (count($newRequires)) {
            $this->reduceToRequiredVersions($reduced, $newRequires);
        }
    }

    private function inlinePackageNames(&$inlinedMap, $names)
    {
        $newNames = array();
        foreach ($names as $name) {
            $inlinedMap[$name] = true;
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name)) {
                continue;
            }

            $this->io->write("Inlining $name");
            foreach ($this->repos as $repo) {
                $packages = $repo->findPackages($name);

                if ($packages) {
                    foreach ($packages as $versionData) {
                        $this->addPackageToJson($versionData);
                        foreach ($versionData->getRequires() as $require) {
                            $newNames[$require->getTarget()] = true;
                        }
                    }
                    break;
                }
            }
        }

        foreach ($newNames as $newName => $void) {
            if (isset($inlinedMap[$newName])) {
                unset($newNames[$newName]);
            }
        }

        if (count($newNames)) {
            $this->inlinePackageNames($inlinedMap, array_keys($newNames));
        }
    }

    private function addPackageToJson(PackageInterface $versionData)
    {
        if ($versionData instanceof AliasPackage) {
            return;
        }

        $data = array(
            'name' => $versionData->getPrettyName(),
            'version' => $versionData->getPrettyVersion(),
            'type' => $versionData->getType(),
        );

        if ($versionData->getExtra()) {
            $data['extra'] = $versionData->getExtra();
        }
        if ($ary = $this->linksToArray($versionData->getRequires())) {
            $data['require'] = $ary;
        }
        if ($ary = $this->linksToArray($versionData->getProvides())) {
            $data['provide'] = $ary;
        }
        if ($ary = $this->linksToArray($versionData->getReplaces())) {
            $data['replace'] = $ary;
        }
        if ($ary = $this->linksToArray($versionData->getConflicts())) {
            $data['conflict'] = $ary;
        }

        $this->addRepositoryToJson($data);
    }

    private function addRepositoryToJson(array $versionData) {
        if (!isset($this->composerDef['repositories'][$versionData['name']])) {
            $this->composerDef['repositories'][$versionData['name']] = array(
                'type' => 'package',
                'package' => array(),
            );
        }

        $this->composerDef['repositories'][$versionData['name']]['package'][] = $versionData;
    }

    /**
     * @param Link[] $links
     */
    private function linksToArray($links)
    {
        $data = array();
        foreach ($links as $link) {
            $data[$link->getTarget()] = $link->getPrettyConstraint();
        }

        return $data;
    }

    /**
     * @return RepositoryInterface[]
     */
    private function initializeRepos($composer)
    {
        return array_merge(
            array(new ArrayRepository(array($composer->getPackage()))), // root package
            array($composer->getRepositoryManager()->getLocalRepository()), // installed packages
            $composer->getRepositoryManager()->getRepositories() // remotes
        );
    }
}
