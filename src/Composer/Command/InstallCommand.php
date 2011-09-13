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

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Solver;
use Composer\Repository\PlatformRepository;
use Composer\Package\MemoryPackage;
use Composer\Package\LinkConstraint\VersionConstraint;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstallCommand
{
    protected $composer;

    public function install($composer)
    {
        $this->composer = $composer;

        // TODO this needs a parameter to enable installing from source (i.e. git clone, instead of downloading archives)
        $sourceInstall = false;

        $config = $this->loadConfig();

        echo 'Loading repositories'.PHP_EOL;

        if (isset($config['repositories'])) {
            foreach ($config['repositories'] as $name => $spec) {
                $composer->addRepository($name, $spec);
            }
        }

        $pool = new Pool;

        $repoInstalled = new PlatformRepository;
        $pool->addRepository($repoInstalled);

        // TODO check the lock file to see what's currently installed
        // $repoInstalled->addPackage(new MemoryPackage('$Package', '$Version'));

        echo 'Loading package list'.PHP_EOL;

        foreach ($composer->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        $request = new Request($pool);

        echo 'Building up request'.PHP_EOL;

        // TODO there should be an update flag or dedicated update command
        // TODO check lock file to remove packages that disappeared from the requirements
        foreach ($config['require'] as $name => $version) {
            $name = $this->lowercase($name);
            if ('latest' === $version) {
                $request->install($name);
            } else {
                preg_match('#^([>=<~]*)([\d.]+.*)$#', $version, $match);
                if (!$match[1]) {
                    $match[1] = '=';
                }
                $constraint = new VersionConstraint($match[1], $match[2]);
                $request->install($name, $constraint);
            }
        }

        echo 'Solving dependencies'.PHP_EOL;

        $policy = new DefaultPolicy;
        $solver = new Solver($policy, $pool, $repoInstalled);
        $transaction = $solver->solve($request);

        $lock = array();

        foreach ($transaction as $task) {
            switch ($task['job']) {
            case 'install':
                $package = $task['package'];
                echo '> Installing '.$package->getName().PHP_EOL;
                if ($sourceInstall) {
                    // TODO
                } else {
                    if ($package->getDistType()) {
                        $downloaderType = $package->getDistType();
                        $type = 'dist';
                    } elseif ($package->getSourceType()) {
                        echo 'Package '.$package->getName().' has no dist url, installing from source instead.';
                        $downloaderType = $package->getSourceType();
                        $type = 'source';
                    } else {
                        throw new \UnexpectedValueException('Package '.$package->getName().' has no source or dist URL.');
                    }
                    $downloader = $composer->getDownloader($downloaderType);
                    $installer = $composer->getInstaller($package->getType());
                    if (!$installer->install($package, $downloader, $type)) {
                        throw new \LogicException($package->getName().' could not be installed.');
                    }
                }
                $lock[$package->getName()] = array('version' => $package->getVersion());
                break;
            default:
                throw new \UnexpectedValueException('Unhandled job type : '.$task['job']);
            }
        }
        echo '> Done'.PHP_EOL;

        $this->storeLockFile($lock);
    }

    protected function loadConfig()
    {
        if (!file_exists('composer.json')) {
            throw new \UnexpectedValueException('composer.json config file not found in '.getcwd());
        }
        $config = json_decode(file_get_contents('composer.json'), true);
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

    protected function storeLockFile(array $content)
    {
        file_put_contents('composer.lock', json_encode($content, JSON_FORCE_OBJECT)."\n");
        echo '> composer.lock dumped'.PHP_EOL;
    }

    protected function lowercase($str)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($str, 'UTF-8');
        }
        return strtolower($str, 'UTF-8');
    }
}