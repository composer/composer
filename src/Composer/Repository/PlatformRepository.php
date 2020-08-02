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

use Composer\Composer;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Platform\HhvmDetector;
use Composer\Platform\Runtime;
use Composer\Plugin\PluginInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Silencer;
use Composer\Util\Version;
use Composer\XdebugHandler\XdebugHandler;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer-(?:plugin|runtime)-api)$}iD';

    private static $hhvmVersion;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * Defines overrides so that the platform can be mocked
     *
     * Should be an array of package name => version number mappings
     *
     * @var array
     */
    private $overrides = array();

    private $runtime;
    private $hhvmDetector;

    public function __construct(array $packages = array(), array $overrides = array(), Runtime $runtime = null, HhvmDetector $hhvmDetector = null)
    {
        $this->runtime = $runtime ?: new Runtime();
        $this->hhvmDetector = $hhvmDetector ?: new HhvmDetector();
        foreach ($overrides as $name => $version) {
            $this->overrides[strtolower($name)] = array('name' => $name, 'version' => $version);
        }
        parent::__construct($packages);
    }

    public function getRepoName()
    {
        return 'platform repo';
    }

    protected function initialize()
    {
        parent::initialize();

        $this->versionParser = new VersionParser();

        // Add each of the override versions as options.
        // Later we might even replace the extensions instead.
        foreach ($this->overrides as $override) {
            // Check that it's a platform package.
            if (!preg_match(self::PLATFORM_PACKAGE_REGEX, $override['name'])) {
                throw new \InvalidArgumentException('Invalid platform package name in config.platform: '.$override['name']);
            }

            $this->addOverriddenPackage($override);
        }

        $prettyVersion = PluginInterface::PLUGIN_API_VERSION;
        $version = $this->versionParser->normalize($prettyVersion);
        $composerPluginApi = new CompletePackage('composer-plugin-api', $version, $prettyVersion);
        $composerPluginApi->setDescription('The Composer Plugin API');
        $this->addPackage($composerPluginApi);

        $prettyVersion = Composer::RUNTIME_API_VERSION;
        $version = $this->versionParser->normalize($prettyVersion);
        $composerRuntimeApi = new CompletePackage('composer-runtime-api', $version, $prettyVersion);
        $composerRuntimeApi->setDescription('The Composer Runtime API');
        $this->addPackage($composerRuntimeApi);

        try {
            $prettyVersion = PHP_VERSION;
            $version = $this->versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            $prettyVersion = preg_replace('#^([^~+-]+).*$#', '$1', PHP_VERSION);
            $version = $this->versionParser->normalize($prettyVersion);
        }

        $php = new CompletePackage('php', $version, $prettyVersion);
        $php->setDescription('The PHP interpreter');
        $this->addPackage($php);

        if (PHP_DEBUG) {
            $phpdebug = new CompletePackage('php-debug', $version, $prettyVersion);
            $phpdebug->setDescription('The PHP interpreter, with debugging symbols');
            $this->addPackage($phpdebug);
        }

        if (defined('PHP_ZTS') && PHP_ZTS) {
            $phpzts = new CompletePackage('php-zts', $version, $prettyVersion);
            $phpzts->setDescription('The PHP interpreter, with Zend Thread Safety');
            $this->addPackage($phpzts);
        }

        if (PHP_INT_SIZE === 8) {
            $php64 = new CompletePackage('php-64bit', $version, $prettyVersion);
            $php64->setDescription('The PHP interpreter, 64bit');
            $this->addPackage($php64);
        }

        // The AF_INET6 constant is only defined if ext-sockets is available but
        // IPv6 support might still be available.
        if (defined('AF_INET6') || Silencer::call('inet_pton', '::') !== false) {
            $phpIpv6 = new CompletePackage('php-ipv6', $version, $prettyVersion);
            $phpIpv6->setDescription('The PHP interpreter, with IPv6 support');
            $this->addPackage($phpIpv6);
        }

        $loadedExtensions = $this->runtime->getExtensions();

        // Extensions scanning
        foreach ($loadedExtensions as $name) {
            if (in_array($name, array('standard', 'Core'))) {
                continue;
            }

            $this->addExtension($name, $this->runtime->getExtensionVersion($name));
        }

        // Check for Xdebug in a restarted process
        if (!in_array('xdebug', $loadedExtensions, true) && ($prettyVersion = XdebugHandler::getSkippedVersion())) {
            $this->addExtension('xdebug', $prettyVersion);
        }

        // Another quick loop, just for possible libraries
        // Doing it this way to know that functions or constants exist before
        // relying on them.
        foreach ($loadedExtensions as $name) {
            switch ($name) {
                case 'amqp':
                    $info = $this->runtime->getExtensionInfo($name);

                    // librabbitmq version => 0.9.0
                    if (preg_match('/^librabbitmq version => (?<version>.+)$/m', $info, $librabbitmqMatches)) {
                        $this->addLibrary('amqp-librabbitmq', $librabbitmqMatches['version'], 'AMQP librabbitmq version');
                    }

                    // AMQP protocol version => 0-9-1
                    if (preg_match('/^AMQP protocol version => (?<version>.+)$/m', $info, $protocolMatches)) {
                        $this->addLibrary('amqp-protocol', str_replace('-', '.', $protocolMatches['version']), 'AMQP protocol version');
                    }
                    break;

                case 'curl':
                    $curlVersion = $this->runtime->invoke('curl_version');
                    $this->addLibrary($name, $curlVersion['version']);

                    $info = $this->runtime->getExtensionInfo($name);

                    // SSL Version => OpenSSL/1.0.1t
                    if (preg_match('{^SSL Version => (?<library>[^/]+)/(?<version>.+)$}m', $info, $sslMatches)) {
                        $parsedVersion = Version::parseOpenssl($sslMatches['version'], $isFips);
                        $this->addLibrary('curl-'.strtolower($sslMatches['library']).($isFips ? '-fips' : ''), $parsedVersion, 'curl '. $sslMatches['library'].' version ('. $sslMatches['version'].')', $isFips ? array('curl-'.strtolower($sslMatches['library'])) : array());
                    }

                    // libSSH Version => libssh2/1.4.3
                    if (preg_match('{^libSSH Version => (?<library>[^/]+)/(?<version>.+)$}m', $info, $sshMatches)) {
                        $this->addLibrary('curl-'.strtolower($sshMatches['library']), $sshMatches['version'], 'curl '.$sshMatches['library'].' version');
                    }

                    // ZLib Version => 1.2.8
                    if (preg_match('{^ZLib Version => (?<version>.+)$}m', $info, $zlibMatches)) {
                        $this->addLibrary('curl-zlib', $zlibMatches['version'], 'curl zlib version');
                    }
                    break;

                case 'date':
                    $info = $this->runtime->getExtensionInfo($name);

                    // timelib version => 2018.03
                    if (preg_match('/^timelib version => (?<version>.+)$/m', $info, $timelibMatches)) {
                        $this->addLibrary('date-timelib', $timelibMatches['version'], 'ext/date timelib version');
                    }
                    break;

                case 'fileinfo':
                    $info = $this->runtime->getExtensionInfo($name);

                    // libmagic => 537
                    if (preg_match('/^^libmagic => (?<version>.+)$/m', $info, $magicMatches)) {
                        $this->addLibrary('fileinfo-libmagic', $magicMatches['version'], 'fileinfo libmagic version');
                    }
                    break;

                case 'gd':
                    $this->addLibrary($name, $this->runtime->getConstant('GD_VERSION'));
                    break;

                case 'iconv':
                    $this->addLibrary($name, $this->runtime->getConstant('ICONV_VERSION'));
                    break;

                case 'intl':
                    $description = 'The ICU unicode and globalization support library';
                    // Truthy check is for testing only so we can make the condition fail
                    if ($this->runtime->hasConstant('INTL_ICU_VERSION')) {
                        $this->addLibrary('icu', $this->runtime->getConstant('INTL_ICU_VERSION'), $description);
                    } elseif (preg_match('/^ICU version => (?<version>.+)$/m', $this->runtime->getExtensionInfo($name), $matches)) {
                        $this->addLibrary('icu', $matches['version'], $description);
                    }

                    # Add a separate version for the CLDR library version
                    if ($this->runtime->hasClass('ResourceBundle')) {
                        $cldrVersion = $this->runtime->invoke(array('ResourceBundle', 'create'), array('root', 'ICUDATA', false))->get('Version');
                        $this->addLibrary('icu-cldr', $cldrVersion, 'ICU CLDR project version');
                    }

                    if ($this->runtime->hasClass('IntlChar')) {
                        $this->addLibrary('icu-unicode', implode('.', array_slice($this->runtime->invoke(array('IntlChar', 'getUnicodeVersion')), 0, 3)), 'ICU unicode version');
                    }
                    break;

                case 'imagick':
                    $imageMagickVersion = $this->runtime->construct('Imagick')->getVersion();
                    // 6.x: ImageMagick 6.2.9 08/24/06 Q16 http://www.imagemagick.org
                    // 7.x: ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org
                    preg_match('/^ImageMagick (?<version>[\d.]+)(?:-(?<patch>\d+))?/', $imageMagickVersion['versionString'], $matches);
                    $version = $matches['version'];
                    if (isset($matches['patch'])) {
                        $version .= '.'.$matches['patch'];
                    }

                    $this->addLibrary('imagick-imagemagick', $version, null, array('imagick'));
                    break;

                case 'libxml':
                    $this->addLibrary($name, $this->runtime->getConstant('LIBXML_DOTTED_VERSION'), 'libxml library version');
                    break;

                case 'mbstring':
                    $info = $this->runtime->getExtensionInfo($name);

                    // libmbfl version => 1.3.2
                    if (preg_match('/^libmbfl version => (?<version>.+)$/m', $info, $libmbflMatches)) {
                        $this->addLibrary('mbstring-libmbfl', $libmbflMatches['version'], 'mbstring libmbfl version');
                    }

                    if ($this->runtime->hasConstant('MB_ONIGURUMA_VERSION')) {
                        $this->addLibrary('mbstring-oniguruma', $this->runtime->getConstant('MB_ONIGURUMA_VERSION'), 'mbstring oniguruma version');

                    // Multibyte regex (oniguruma) version => 5.9.5
                    // oniguruma version => 6.9.0
                    } elseif (preg_match('/^(?:oniguruma|Multibyte regex \(oniguruma\)) version => (?<version>.+)$/m', $info, $onigurumaMatches)) {
                        $this->addLibrary('mbstring-oniguruma', $onigurumaMatches['version'], 'mbstring oniguruma version');
                    }

                    break;

                case 'memcached':
                    $info = $this->runtime->getExtensionInfo($name);

                    // libmemcached version => 1.0.18
                    if (preg_match('/^libmemcached version => (?<version>.+)$/m', $info, $matches)) {
                        $this->addLibrary('memcached-libmemcached', $matches['version'], 'libmemcached version');
                    }
                    break;

                case 'openssl':
                    // OpenSSL 1.1.1g  21 Apr 2020
                    if (preg_match('{^(?:OpenSSL|LibreSSL)?\s*(?<version>\S+)}i', $this->runtime->getConstant('OPENSSL_VERSION_TEXT'), $matches)) {
                        $parsedVersion = Version::parseOpenssl($matches['version'], $isFips);
                        $this->addLibrary($name.($isFips ? '-fips' : ''), $parsedVersion , $this->runtime->getConstant('OPENSSL_VERSION_TEXT'), $isFips ? array($name) : array());
                    }
                    break;

                case 'pcre':
                    $this->addLibrary($name, preg_replace('{^(\S+).*}', '$1', $this->runtime->getConstant('PCRE_VERSION')));

                    $info = $this->runtime->getExtensionInfo($name);

                    // PCRE Unicode Version => 12.1.0
                    if (preg_match('/^PCRE Unicode Version => (?<version>.+)$/m', $info, $pcreUnicodeMatches)) {
                        $this->addLibrary('pcre-unicode', $pcreUnicodeMatches['version'], 'PCRE Unicode version support');
                    }

                    break;

                case 'libsodium':
                case 'sodium':
                    $this->addLibrary('libsodium', $this->runtime->getConstant('SODIUM_LIBRARY_VERSION'));
                    break;

                case 'xsl':
                    $this->addLibrary('libxslt', $this->runtime->getConstant('LIBXSLT_DOTTED_VERSION'), null, array('xsl'));
                    break;

                case 'zip':
                    if ($this->runtime->hasConstant('LIBZIP_VERSION', 'ZipArchive')) {
                        $this->addLibrary('zip-libzip', $this->runtime->getConstant('LIBZIP_VERSION','ZipArchive'), null, array('zip'));
                    }
                    break;

                case 'zlib':
                    if ($this->runtime->hasConstant('ZLIB_VERSION')) {
                        $this->addLibrary($name, $this->runtime->getConstant('ZLIB_VERSION'));

                    // Linked Version => 1.2.8
                    } elseif (preg_match('/^Linked Version => (?<version>.+)$/m', $this->runtime->getExtensionInfo('zlib'), $matches)) {
                        $this->addLibrary($name, $matches['version']);
                    }
                    break;

                default:
                    break;
            }
        }

        $hhvmVersion = $this->hhvmDetector->getVersion();
        if ($hhvmVersion) {
            try {
                $prettyVersion = $hhvmVersion;
                $version = $this->versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                $prettyVersion = preg_replace('#^([^~+-]+).*$#', '$1', $hhvmVersion);
                $version = $this->versionParser->normalize($prettyVersion);
            }

            $hhvm = new CompletePackage('hhvm', $version, $prettyVersion);
            $hhvm->setDescription('The HHVM Runtime (64bit)');
            $this->addPackage($hhvm);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addPackage(PackageInterface $package)
    {
        // Skip if overridden
        if (isset($this->overrides[$package->getName()])) {
            $overrider = $this->findPackage($package->getName(), '*');
            if ($package->getVersion() === $overrider->getVersion()) {
                $actualText = 'same as actual';
            } else {
                $actualText = 'actual: '.$package->getPrettyVersion();
            }
            $overrider->setDescription($overrider->getDescription().', '.$actualText);

            return;
        }

        // Skip if PHP is overridden and we are adding a php-* package
        if (isset($this->overrides['php']) && 0 === strpos($package->getName(), 'php-')) {
            $overrider = $this->addOverriddenPackage($this->overrides['php'], $package->getPrettyName());
            if ($package->getVersion() === $overrider->getVersion()) {
                $actualText = 'same as actual';
            } else {
                $actualText = 'actual: '.$package->getPrettyVersion();
            }
            $overrider->setDescription($overrider->getDescription().', '.$actualText);

            return;
        }

        parent::addPackage($package);
    }

    private function addOverriddenPackage(array $override, $name = null)
    {
        $version = $this->versionParser->normalize($override['version']);
        $package = new CompletePackage($name ?: $override['name'], $version, $override['version']);
        $package->setDescription('Package overridden via config.platform');
        $package->setExtra(array('config.platform' => true));
        parent::addPackage($package);

        return $package;
    }

    /**
     * Parses the version and adds a new package to the repository
     *
     * @param string      $name
     * @param null|string $prettyVersion
     */
    private function addExtension($name, $prettyVersion)
    {
        $extraDescription = null;

        try {
            $version = $this->versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            $extraDescription = ' (actual version: '.$prettyVersion.')';
            if (preg_match('{^(\d+\.\d+\.\d+(?:\.\d+)?)}', $prettyVersion, $match)) {
                $prettyVersion = $match[1];
            } else {
                $prettyVersion = '0';
            }
            $version = $this->versionParser->normalize($prettyVersion);
        }

        $packageName = $this->buildPackageName($name);
        $ext = new CompletePackage($packageName, $version, $prettyVersion);
        $ext->setDescription('The '.$name.' PHP extension'.$extraDescription);

        if ($name === 'uuid') {
            $ext->setReplaces(array(new Link('ext-uuid', 'lib-uuid', new Constraint('=', $version))));
        }

        $this->addPackage($ext);
    }

    /**
     * @param string $name
     * @return string
     */
    private function buildPackageName($name)
    {
        return 'ext-' . str_replace(' ', '-', $name);
    }

    /**
     * @param string      $name
     * @param string      $prettyVersion
     * @param string|null $description
     * @param string[]    $compatibilityAliases
     */
    private function addLibrary($name, $prettyVersion, $description = null, array $compatibilityAliases = array())
    {
        try {
            $version = $this->versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            return;
        }

        if ($description === null) {
            $description = 'The '.$name.' library';
        }

        $lib = new CompletePackage('lib-'.$name, $version, $prettyVersion);
        $lib->setDescription($description);

        $replaces = array();
        foreach ($compatibilityAliases as $alias) {
            $replaces[] = new Link('lib-'.$name, 'lib-'.$alias, new Constraint('=', $version));
        }
        $lib->setReplaces($replaces);

        $this->addPackage($lib);
    }
}
