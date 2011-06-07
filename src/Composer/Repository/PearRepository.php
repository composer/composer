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

namespace Composer\Repository;

use Composer\Package\MemoryPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;

/**
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class PearRepository extends ArrayRepository
{
    private $name;
    private $url;

    public function __construct($url, $name)
    {
        $this->url = $url;
        $this->name = $name;
        
        if (!filter_var($this->url, FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException("Invalid url given for PEAR repository " . $name);
        }
    }
    
    protected function initialize()
    {
        parent::initialize();
        
        exec("pear remote-list -c ".escapeshellarg($this->name), $output, $return);
        
        if ($return != 0) {
            throw new \BadMethodCallException("Could not execute pear channel-list, an error occured.");
        }
        
        $headersDone = false;
        foreach ($output AS $line) {
            $parts = explode(" ", preg_replace('(\s{2,})', ' ', trim($line)));
            if (count($parts) != 2) {
                continue;
            }
            list($packageName, $pearVersion) = $parts;
            
            if (!$headersDone) {
                if ($packageName == "PACKAGE" && $pearVersion == "VERSION") {
                    $headersDone = true;
                }
                continue;
            }
            
            if ($pearVersion == "-n/a-") {
                continue; // Preferred stability is set to a level that this package can't fullfil.
            }
            
            $version = BasePackage::parseVersion($pearVersion);

            $package = new MemoryPackage($packageName, $version['version'], $version['type']);
            $package->setSourceType('pear');
            $package->setSourceUrl($this->url.'/get/'.$packageName.'-'.$pearVersion.".tgz");
            
            $this->addPackage($package);
        }
    }
}
