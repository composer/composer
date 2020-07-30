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
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\Silencer;
use Composer\Util\Platform;
use Composer\Util\Version;
use Composer\XdebugHandler\XdebugHandler;
use Symfony\Component\Process\ExecutableFinder;

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

    private $process;

    public function __construct(array $packages = array(), array $overrides = array(), ProcessExecutor $process = null)
    {
        $this->process = $process;
        foreach ($overrides as $name => $version) {
            $this->overrides[strtolower($name)] = array('name' => $name, 'version' => $version);
        }
        parent::__construct($packages);
    }

    /**
     * @param string $extension
     * @return string
     */
    private static function getExtensionInfo($extension)
    {
        $reflector = new \ReflectionExtension($extension);

        ob_start();
        $reflector->info();
        return ob_get_clean();
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

        $loadedExtensions = get_loaded_extensions();

        // Extensions scanning
        foreach ($loadedExtensions as $name) {
            if (in_array($name, array('standard', 'Core'))) {
                continue;
            }

            $reflExt = new \ReflectionExtension($name);
            $prettyVersion = $reflExt->getVersion();
            $this->addExtension($name, $prettyVersion);
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
                    $info = self::getExtensionInfo($name);

                    // librabbitmq version => 0.9.0
                    if (preg_match('/^librabbitmq version => (?P<version>.+)$/m', $info, $librabbitmqMatches)) {
                        $this->addLibrary('amqp-librabbitmq', $librabbitmqMatches['version'], 'AMQP librabbitmq version');
                    }

                    // AMQP protocol version => 0-9-1
                    if (preg_match('/^AMQP protocol version => (?P<version>.+)$/m', $info, $protocolMatches)) {
                        $this->addLibrary('amqp-protocol', str_replace('-', '.', $protocolMatches['version']), 'AMQP protocol version');
                    }
                    break;

                case 'curl':
                    $curlVersion = curl_version();
                    $this->addLibrary($name, $curlVersion['version']);

                    $info = self::getExtensionInfo($name);

                    // SSL Version => OpenSSL/1.0.1t
                    if (preg_match('{^SSL Version => (?P<library>[^/]+)/(?P<version>.+)$}m', $info, $sslMatches)) {
                        $this->addLibrary('curl-'.strtolower($sslMatches['library']), Version::normalizeOpenssl($sslMatches['version']), 'curl '. $sslMatches['library'].' version ('. $sslMatches['version'].')');
                    }

                    // libSSH Version => libssh2/1.4.3
                    if (preg_match('{^libSSH Version => (?P<library>[^/]+)/(?P<version>.+)$}m', $info, $sshMatches)) {
                        $this->addLibrary('curl-'.strtolower($sshMatches['library']), $sshMatches['version'], 'curl '.$sshMatches['library'].' version');
                    }

                    // ZLib Version => 1.2.8
                    if (preg_match('{^ZLib Version => (?P<version>.+)$}m', $info, $zlibMatches)) {
                        $this->addLibrary('curl-zlib', $zlibMatches['version'], 'curl zlib version');
                    }
                    break;

                case 'date':
                    $info = self::getExtensionInfo($name);

                    // timelib version => 2018.03
                    if (preg_match('/^timelib version => (?P<version>.+)$/m', $info, $timelibMatches)) {
                        $this->addLibrary('date-timelib', $timelibMatches['version'], 'ext/date timelib version');
                    }
                    break;

                case 'fileinfo':
                    $info = self::getExtensionInfo($name);

                    // libmagic => 537
                    if (preg_match('/^^libmagic => (?P<version>.+)$/m', $info, $magicMatches)) {
                        $this->addLibrary('fileinfo-libmagic', $magicMatches['version'], 'fileinfo libmagic version');
                    }
                    break;

                case 'gd':
                    $this->addLibrary($name, GD_VERSION);
                    break;

                case 'iconv':
                    $this->addLibrary($name, ICONV_VERSION);
                    break;

                case 'intl':
                    $description = 'The ICU unicode and globalization support library';
                    if (defined('INTL_ICU_VERSION')) {
                        $this->addLibrary('icu', INTL_ICU_VERSION, $description);
                    } elseif (preg_match('/^ICU version => (?P<version>.*)$/m', self::getExtensionInfo($name), $matches)) {
                        $this->addLibrary('icu', $matches[1], $description);
                    }

                    if (class_exists('ResourceBundle', false)) {
                        # Add a separate version for the CLDR library version
                        $cldrVersion = \ResourceBundle::create('root', 'ICUDATA-curr', false)->get('Version');
                        $this->addLibrary('icu-cldr', $cldrVersion, 'ICU CLDR project version');
                    }

                    if (class_exists('IntlChar', false)) {
                        $this->addLibrary('icu-unicode', implode('.', \IntlChar::getUnicodeVersion()), 'ICU unicode version');
                    }
                    break;

                case 'imagick':
                    $imagick = new \Imagick();
                    $imageMagickVersion = $imagick->getVersion();
                    // 6.x: ImageMagick 6.2.9 08/24/06 Q16 http://www.imagemagick.org
                    // 7.x: ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org
                    preg_match('/^ImageMagick ([\d.]+)(?:-(\d+))?/', $imageMagickVersion['versionString'], $matches);
                    if (isset($matches[2])) {
                        $version = "{$matches[1]}.{$matches[2]}";
                    } else {
                        $version = $matches[1];
                    }

                    $this->addLibrary('imagick-imagemagick', $version);

                    // Alias for backwards compatibility
                    $this->addLibrary('imagick', $version);
                    break;

                case 'libxml':
                    $this->addLibrary($name, LIBXML_DOTTED_VERSION, 'libxml library version');
                    break;

                case 'mbstring':
                    $info = self::getExtensionInfo($name);

                    // libmbfl version => 1.3.2
                    if (preg_match('/^libmbfl version => (?P<version>.+)$/m', $info, $libmbflMatches)) {
                        $this->addLibrary('mbstring-libmbfl', $libmbflMatches['version'], 'mbstring libmbfl version');
                    }

                    if (defined('MB_ONIGURUMA_VERSION')) {
                        $this->addLibrary('mbstring-oniguruma', MB_ONIGURUMA_VERSION, 'mbstring oniguruma version');

                    // Multibyte regex (oniguruma) version => 5.9.5
                    // oniguruma version => 6.9.0
                    } elseif (preg_match('/^(?:oniguruma|Multibyte regex \(oniguruma\)) version => (?P<version>.+)$/m', $info, $onigurumaMatches)) {
                        $this->addLibrary('mbstring-oniguruma', $onigurumaMatches['version'], 'mbstring oniguruma version');
                    }

                    break;

                case 'memcached':
                    $info = self::getExtensionInfo($name);

                    // libmemcached version => 1.0.18
                    if (preg_match('/^libmemcached version => (?P<version>.+)$/m', $info, $matches)) {
                        $this->addLibrary('memcached-libmemcached', $matches['version'], 'libmemcached version');
                    }
                    break;

                case 'openssl':
                    // OpenSSL 1.1.1g  21 Apr 2020
                    if (preg_match('{^(?:OpenSSL|LibreSSL)?\s*(?<version>[^ ]+)}i', OPENSSL_VERSION_TEXT, $matches)) {
                        $this->addLibrary($name, Version::normalizeOpenssl($matches['version']), OPENSSL_VERSION_TEXT);
                    }
                    break;

                case 'pcre':
                    $this->addLibrary($name, preg_replace('{^(\S+).*}', '$1', PCRE_VERSION));

                    $info = self::getExtensionInfo($name);

                    // PCRE Unicode Version => 12.1.0
                    if (preg_match('/^PCRE Unicode Version => (?P<version>.+)$/m', $info, $pcreUnicodeMatches)) {
                        $this->addLibrary('pcre-unicode', $pcreUnicodeMatches['version'], 'PCRE Unicode version support');
                    }

                    break;

                case 'libsodium':
                case 'sodium':
                    $this->addLibrary('libsodium', SODIUM_LIBRARY_VERSION);
                    break;

                case 'uuid':
                    $this->addLibrary($name, phpversion('uuid'));
                    break;

                case 'xsl':
                    $this->addLibrary($name, LIBXSLT_DOTTED_VERSION);
                    break;

                case 'zip':
                    if (defined('ZipArchive::LIBZIP_VERSION')) {
                        $this->addLibrary($name, \ZipArchive::LIBZIP_VERSION);
                    }
                    break;

                case 'zlib':
                    $this->addLibrary($name, ZLIB_VERSION);
                    break;

                default:
                    break;
            }
        }

        if ($hhvmVersion = self::getHHVMVersion($this->process)) {
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
     */
    private function addLibrary($name, $prettyVersion, $description = null)
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
        $this->addPackage($lib);
    }

    private static function getHHVMVersion(ProcessExecutor $process = null)
    {
        if (null !== self::$hhvmVersion) {
            return self::$hhvmVersion ?: null;
        }

        self::$hhvmVersion = defined('HHVM_VERSION') ? HHVM_VERSION : null;
        if (self::$hhvmVersion === null && !Platform::isWindows()) {
            self::$hhvmVersion = false;
            $finder = new ExecutableFinder();
            $hhvmPath = $finder->find('hhvm');
            if ($hhvmPath !== null) {
                $process = $process ?: new ProcessExecutor();
                $exitCode = $process->execute(
                    ProcessExecutor::escape($hhvmPath).
                    ' --php -d hhvm.jit=0 -r "echo HHVM_VERSION;" 2>/dev/null',
                    self::$hhvmVersion
                );
                if ($exitCode !== 0) {
                    self::$hhvmVersion = false;
                }
            }
        }
    }
}
