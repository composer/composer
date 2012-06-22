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
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{

    protected function initialize()
    {
        parent::initialize();

        $versionParser = new VersionParser();

        try {
            $prettyVersion = PHP_VERSION;
            $version = $versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            $prettyVersion = preg_replace('#^(.+?)(-.+)?$#', '$1', PHP_VERSION);
            $version = $versionParser->normalize($prettyVersion);
        }

        $php = new MemoryPackage('php', $version, $prettyVersion);
        $php->setDescription('The PHP interpreter');
        parent::addPackage($php);
        
        $loadedExtensions = get_loaded_extensions();
        
        // Extensions scanning
        foreach ($loadedExtensions as $name) {
            switch ($name) {
                // Skipped "extensions"
                case 'standard':
                case 'Core':
                    continue;
                    
                // All normal cases for standard extensions    
                default:
                    $reflExt = new \ReflectionExtension($name);
                    $prettyVersion = $reflExt->getVersion();
                    break;
            }
            
            try {
                $version = $versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                $prettyVersion = '0';
                $version = $versionParser->normalize($prettyVersion);
            }

            $ext = new MemoryPackage('ext-'.$name, $version, $prettyVersion);
            $ext->setDescription('The '.$name.' PHP extension');
            parent::addPackage($ext);
        }
        
        // Another quick loop, just for possible libraries
        foreach ($loadedExtensions as $name) {
            switch ($name) {
                // Skipped "extensions"
                case 'standard':
                case 'Core':
                    continue;
        
                    // Curl exposes its version by the curl_version function
                case 'curl':
                    $curlVersion = curl_version();
                    $prettyVersion = $curlVersion['version'];
                    break;
        
                case 'libxml':
                    $prettyVersion = LIBXML_DOTTED_VERSION;
                    break;
        
                case 'openssl':
                    $prettyVersion = str_replace('OpenSSL', '', OPENSSL_VERSION_TEXT);
                    $prettyVersion = trim($prettyVersion);
                    break;
        
                case 'pcre':
                    $prettyVersion = PCRE_VERSION;
                    break;
                    
                default:
                    // None handled extensions have no special cases, skip 
                    continue;
            }
        
            try {
                $version = $versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                $prettyVersion = '0';
                $version = $versionParser->normalize($prettyVersion);
            }
        
            $ext = new MemoryPackage('lib-'.$name, $version, $prettyVersion);
            $ext->setDescription('The '.$name.' PHP library');
            parent::addPackage($ext);
        }
    }
}
