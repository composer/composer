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

namespace Composer\Package;

use Composer\Package\MemoryPackage;
use Composer\Package\Version\VersionParser;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class PackageLock
{
    private $file;
    private $isLocked = false;

    public function __construct($file = 'composer.lock')
    {
        if (file_exists($file)) {
            $this->file     = $file;
            $this->isLocked = true;
        }
    }

    public function isLocked()
    {
        return $this->isLocked;
    }

    public function getLockedPackages()
    {
        $lockList = $this->loadJsonConfig($this->file);

        $versionParser = new VersionParser();
        $packages      = array();
        foreach ($lockList as $info) {
            $version    = $versionParser->parse($info['version']);
            $packages[] = new MemoryPackage($info['package'], $version['version'], $version['type']);
        }

        return $packages;
    }

    public function lock(array $packages)
    {
        // TODO: write installed packages info into $this->file
    }

    private function loadJsonConfig($json)
    {
        if (is_file($json)) {
            $json = file_get_contents($json);
        }

        $config = json_decode($json, true);
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
