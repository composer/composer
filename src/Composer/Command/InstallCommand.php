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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstallCommand
{
    protected $composer;

    public function install($composer)
    {
        $this->composer = $composer;

        $config = $this->loadConfig();

        foreach ($config['repositories'] as $name => $spec) {
            $composer->addRepository($name, $spec);
        }

        // TODO this should just do dependency solving based on all repositories
        $packages = array();
        foreach ($composer->getRepositories() as $repository) {
            $packages[] = $repository->getPackages();
        }
        $packages = call_user_func_array('array_merge', $packages);

        $lock = array();

        // TODO this should use the transaction returned by the solver
        foreach ($config['require'] as $name => $version) {
            unset($package);
            foreach ($packages as $pkg) {
                if (strtolower($pkg->getName()) === strtolower($name)) {
                    $package = $pkg;
                    break;
                }
            }
            if (!isset($package)) {
                throw new \UnexpectedValueException('Could not find package '.$name.' in any of your repositories');
            }
            $downloader = $composer->getDownloader($package->getSourceType());
            $installer = $composer->getInstaller($package->getType());
            $lock[$name] = $installer->install($package, $downloader);
            echo '> '.$name.' installed'.PHP_EOL;
        }

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
        file_put_contents('composer.lock', json_encode($content)."\n");
        echo '> composer.lock dumped'.PHP_EOL;
    }
}