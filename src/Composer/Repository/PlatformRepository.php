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

use Composer\Package\CompletePackage;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit)?|hhvm|(?:ext|lib)-[^/]+)$}i';

    protected function initialize()
    {
        parent::initialize();

        $versionParser = new VersionParser();

        $prettyVersion = PluginInterface::PLUGIN_API_VERSION;
        $version = $versionParser->normalize($prettyVersion);
        $composerPluginApi = new CompletePackage('composer-plugin-api', $version, $prettyVersion);
        $composerPluginApi->setDescription('The Composer Plugin API');
        parent::addPackage($composerPluginApi);

        try {
            $prettyVersion = PHP_VERSION;
            $version = $versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            $prettyVersion = preg_replace('#^([^~+-]+).*$#', '$1', PHP_VERSION);
            $version = $versionParser->normalize($prettyVersion);
        }

        $php = new CompletePackage('php', $version, $prettyVersion);
        $php->setDescription('The PHP interpreter');
        parent::addPackage($php);

        if (PHP_INT_SIZE === 8) {
            $php64 = new CompletePackage('php-64bit', $version, $prettyVersion);
            $php64->setDescription('The PHP interpreter (64bit)');
            parent::addPackage($php64);
        }

        $loadedExtensions = get_loaded_extensions();

        // Extensions scanning
        foreach ($loadedExtensions as $name) {
            if (in_array($name, array('standard', 'Core'))) {
                continue;
            }

            $reflExt = new \ReflectionExtension($name);
            try {
                $prettyVersion = $reflExt->getVersion();
                $version = $versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                $prettyVersion = '0';
                $version = $versionParser->normalize($prettyVersion);
            }

            $packageName = $this->buildPackageName($name);
            $ext = new CompletePackage($packageName, $version, $prettyVersion);
            $ext->setDescription('The '.$name.' PHP extension');
            parent::addPackage($ext);
        }

        // Another quick loop, just for possible libraries
        // Doing it this way to know that functions or constants exist before
        // relying on them.
        foreach ($loadedExtensions as $name) {
            $prettyVersion = null;
            switch ($name) {
                case 'curl':
                    $curlVersion = curl_version();
                    $prettyVersion = $curlVersion['version'];
                    break;

                case 'iconv':
                    $prettyVersion = ICONV_VERSION;
                    break;

                case 'intl':
                    $name = 'ICU';
                    if (defined('INTL_ICU_VERSION')) {
                        $prettyVersion = INTL_ICU_VERSION;
                    } else {
                        $reflector = new \ReflectionExtension('intl');

                        ob_start();
                        $reflector->info();
                        $output = ob_get_clean();

                        preg_match('/^ICU version => (.*)$/m', $output, $matches);
                        $prettyVersion = $matches[1];
                    }

                    break;

                case 'libxml':
                    $prettyVersion = LIBXML_DOTTED_VERSION;
                    break;

                case 'openssl':
                    $prettyVersion = preg_replace_callback('{^(?:OpenSSL\s*)?([0-9.]+)([a-z]?).*}', function ($match) {
                        return $match[1] . (empty($match[2]) ? '' : '.'.(ord($match[2]) - 96));
                    }, OPENSSL_VERSION_TEXT);
                    break;

                case 'pcre':
                    $prettyVersion = preg_replace('{^(\S+).*}', '$1', PCRE_VERSION);
                    break;

                case 'uuid':
                    $prettyVersion = phpversion('uuid');
                    break;

                case 'xsl':
                    $prettyVersion = LIBXSLT_DOTTED_VERSION;
                    break;

                default:
                    // None handled extensions have no special cases, skip
                    continue 2;
            }

            try {
                $version = $versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                continue;
            }

            $lib = new CompletePackage('lib-'.$name, $version, $prettyVersion);
            $lib->setDescription('The '.$name.' PHP library');
            parent::addPackage($lib);
        }

        if (defined('HHVM_VERSION')) {
            try {
                $prettyVersion = HHVM_VERSION;
                $version = $versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                $prettyVersion = preg_replace('#^([^~+-]+).*$#', '$1', HHVM_VERSION);
                $version = $versionParser->normalize($prettyVersion);
            }

            $hhvm = new CompletePackage('hhvm', $version, $prettyVersion);
            $hhvm->setDescription('The HHVM Runtime (64bit)');
            parent::addPackage($hhvm);
        }
    }

    private function buildPackageName($name)
    {
        return 'ext-' . str_replace(' ', '-', $name);
    }
}
