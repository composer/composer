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

namespace Composer;

use Composer\Repository\RepositoryFactory;
use Composer\Package;
use Composer\Installer\LibraryInstaller;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class ConfigurableComposer extends Composer
{
    private $configFile;
    private $lockFile;
    private $isLocked = false;
    private $lockedPackages = array();
    private $repositoryFactory;

    public function __construct($configFile = 'composer.json', $lockFile = 'composer.lock',
                                RepositoryFactory $repositoryFactory)
    {
        $this->configFile        = $configFile;
        $this->lockFile          = $lockFile;
        $this->repositoryFactory = $repositoryFactory;

        $this->setRepository('Platform', $repositoryFactory->create('platform'));

        if (!file_exists($configFile)) {
            throw new \UnexpectedValueException('Can not find composer config file');
        }

        $config = $this->loadJsonConfig($configFile);

        if (isset($config['path'])) {
            $this->setInstaller('library', new LibraryInstaller($config['path']));
        } else {
            $this->setInstaller('library', new LibraryInstaller());
        }

        if (isset($config['repositories'])) {
            $repositories = $this->loadRepositoriesFromConfig($config['repositories'])
            foreach ($repositories as $name => $repository) {
                $this->setRepository($name, $repository);
            }
        }

        if (isset($config['require'])) {
            $requirements = $this->loadRequirementsFromConfig($config['require']);
            foreach ($requirements as $name => $constraint) {
                $this->setRequirement($name, $constraint);
            }
        }

        if (file_exists($lockFile)) {
            $lock     = $this->loadJsonConfig($lockFile);
            $platform = $this->getRepository('Platform');
            $packages = $this->loadPackagesFromLock($lock);
            foreach ($packages as $package) {
                if ($this->hasRequirement($package->getName())) {
                    $platform->addPackage($package);
                    $this->lockedPackages[] = $package;
                }
            }
            $this->isLocked = true;
        }
    }

    public function isLocked()
    {
        return $this->isLocked;
    }

    public function getLockedPackages()
    {
        return $this->lockedPackages;
    }

    public function lock(array $packages)
    {
        // TODO: write installed packages info into $this->lockFile
    }

    private function loadPackagesFromLock(array $lockList)
    {
        $packages = array();
        foreach ($lockList as $info) {
            $packages[] = new Package\MemoryPackage($info['package'], $info['version']);
        }

        return $packages;
    }

    private function loadRepositoriesFromConfig(array $repositoriesList)
    {
        $repositories = array();
        foreach ($repositoriesList as $name => $spec) {
            if (is_array($spec) && count($spec) === 1) {
                $repositories[$name] = $this->repositoryFactory->create(
                    key($spec), $name, current($spec)
                );
            } elseif (null === $spec) {
                $repositories[$name] = null;
            } else {
                throw new \UnexpectedValueException(
                    'Invalid repositories specification '.
                    json_encode($spec).', should be: {"type": "url"}'
                );
            }
        }

        return $repositories;
    }

    private function loadRequirementsFromConfig(array $requirementsList)
    {
        $requirements = array();
        foreach ($requirementsList as $name => $version) {
            $name = strtolower($name);
            if ('latest' === $version) {
                $requirements[$name] = null;
            } else {
                preg_match('#^([>=<~]*)([\d.]+.*)$#', $version, $match);
                if (!$match[1]) {
                    $match[1] = '=';
                }
                $constraint = new Package\LinkConstraint\VersionConstraint($match[1], $match[2]);
                $requirements[$name] = $constraint;
            }
        }

        return $requirements;
    }

    private function loadJsonConfig($configFile)
    {
        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) {
            switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $msg = 'No error has occurred, is your composer.json file empty?';
                break;
            case JSON_ERROR_DEPTH:
                $msg = 'The maximum stack depth has been exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $msg = 'Invalid or malformed JSON';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $msg = 'Control character error, possibly incorrectly encoded';
                break;
            case JSON_ERROR_SYNTAX:
                $msg = 'Syntax error';
                break;
            case JSON_ERROR_UTF8:
                $msg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            }
            throw new \UnexpectedValueException('Incorrect composer.json file: '.$msg);
        }

        return $config;
    }
}
