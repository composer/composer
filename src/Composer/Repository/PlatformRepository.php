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
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Platform\HhvmDetector;
use Composer\Platform\Runtime;
use Composer\Platform\Version;
use Composer\Plugin\PluginInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Silencer;
use Composer\XdebugHandler\XdebugHandler;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer-(?:plugin|runtime)-api)$}iD';

    /**
     * @var ?string
     */
    private static $lastSeenPlatformPhp = null;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * Defines overrides so that the platform can be mocked
     *
     * Keyed by package name (lowercased)
     *
     * @var array<string, array{name: string, version: string}>
     */
    private $overrides = array();

    /** @var Runtime */
    private $runtime;
    /** @var HhvmDetector */
    private $hhvmDetector;

    /**
     * @param array<string, string> $overrides
     */
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
            if (!self::isPlatformPackage($override['name'])) {
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
            $prettyVersion = $this->runtime->getConstant('PHP_VERSION');
            $version = $this->versionParser->normalize($prettyVersion);
        } catch (\UnexpectedValueException $e) {
            $prettyVersion = preg_replace('#^([^~+-]+).*$#', '$1', $this->runtime->getConstant('PHP_VERSION'));
            $version = $this->versionParser->normalize($prettyVersion);
        }

        $php = new CompletePackage('php', $version, $prettyVersion);
        $php->setDescription('The PHP interpreter');
        $this->addPackage($php);

        if ($this->runtime->getConstant('PHP_DEBUG')) {
            $phpdebug = new CompletePackage('php-debug', $version, $prettyVersion);
            $phpdebug->setDescription('The PHP interpreter, with debugging symbols');
            $this->addPackage($phpdebug);
        }

        if ($this->runtime->hasConstant('PHP_ZTS') && $this->runtime->getConstant('PHP_ZTS')) {
            $phpzts = new CompletePackage('php-zts', $version, $prettyVersion);
            $phpzts->setDescription('The PHP interpreter, with Zend Thread Safety');
            $this->addPackage($phpzts);
        }

        if ($this->runtime->getConstant('PHP_INT_SIZE') === 8) {
            $php64 = new CompletePackage('php-64bit', $version, $prettyVersion);
            $php64->setDescription('The PHP interpreter, 64bit');
            $this->addPackage($php64);
        }

        // The AF_INET6 constant is only defined if ext-sockets is available but
        // IPv6 support might still be available.
        if ($this->runtime->hasConstant('AF_INET6') || Silencer::call(array($this->runtime, 'invoke'), 'inet_pton', array('::')) !== false) {
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
                    if (preg_match('/^librabbitmq version => (?<version>.+)$/im', $info, $librabbitmqMatches)) {
                        $this->addLibrary($name.'-librabbitmq', $librabbitmqMatches['version'], 'AMQP librabbitmq version');
                    }

                    // AMQP protocol version => 0-9-1
                    if (preg_match('/^AMQP protocol version => (?<version>.+)$/im', $info, $protocolMatches)) {
                        $this->addLibrary($name.'-protocol', str_replace('-', '.', $protocolMatches['version']), 'AMQP protocol version');
                    }
                    break;

                case 'bz2':
                    $info = $this->runtime->getExtensionInfo($name);

                    // BZip2 Version => 1.0.6, 6-Sept-2010
                    if (preg_match('/^BZip2 Version => (?<version>.*),/im', $info, $matches)) {
                        $this->addLibrary($name, $matches['version']);
                    }
                    break;

                case 'curl':
                    $curlVersion = $this->runtime->invoke('curl_version');
                    $this->addLibrary($name, $curlVersion['version']);

                    $info = $this->runtime->getExtensionInfo($name);

                    // SSL Version => OpenSSL/1.0.1t
                    if (preg_match('{^SSL Version => (?<library>[^/]+)/(?<version>.+)$}im', $info, $sslMatches)) {
                        $library = strtolower($sslMatches['library']);
                        if ($library === 'openssl') {
                            $parsedVersion = Version::parseOpenssl($sslMatches['version'], $isFips);
                            $this->addLibrary($name.'-openssl'.($isFips ? '-fips' : ''), $parsedVersion, 'curl OpenSSL version ('.$parsedVersion.')', array(), $isFips ? array('curl-openssl') : array());
                        } else {
                            $this->addLibrary($name.'-'.$library, $sslMatches['version'], 'curl '.$library.' version ('.$sslMatches['version'].')', array('curl-openssl'));
                        }
                    }

                    // libSSH Version => libssh2/1.4.3
                    if (preg_match('{^libSSH Version => (?<library>[^/]+)/(?<version>.+?)(?:/.*)?$}im', $info, $sshMatches)) {
                        $this->addLibrary($name.'-'.strtolower($sshMatches['library']), $sshMatches['version'], 'curl '.$sshMatches['library'].' version');
                    }

                    // ZLib Version => 1.2.8
                    if (preg_match('{^ZLib Version => (?<version>.+)$}im', $info, $zlibMatches)) {
                        $this->addLibrary($name.'-zlib', $zlibMatches['version'], 'curl zlib version');
                    }
                    break;

                case 'date':
                    $info = $this->runtime->getExtensionInfo($name);

                    // timelib version => 2018.03
                    if (preg_match('/^timelib version => (?<version>.+)$/im', $info, $timelibMatches)) {
                        $this->addLibrary($name.'-timelib', $timelibMatches['version'], 'date timelib version');
                    }

                    // Timezone Database => internal
                    if (preg_match('/^Timezone Database => (?<source>internal|external)$/im', $info, $zoneinfoSourceMatches)) {
                        $external = $zoneinfoSourceMatches['source'] === 'external';
                        if (preg_match('/^"Olson" Timezone Database Version => (?<version>.+?)(\.system)?$/im', $info, $zoneinfoMatches)) {
                            // If the timezonedb is provided by ext/timezonedb, register that version as a replacement
                            if ($external && in_array('timezonedb', $loadedExtensions, true)) {
                                $this->addLibrary('timezonedb-zoneinfo', $zoneinfoMatches['version'], 'zoneinfo ("Olson") database for date (replaced by timezonedb)', array($name.'-zoneinfo'));
                            } else {
                                $this->addLibrary($name.'-zoneinfo', $zoneinfoMatches['version'], 'zoneinfo ("Olson") database for date');
                            }
                        }
                    }
                    break;

                case 'fileinfo':
                    $info = $this->runtime->getExtensionInfo($name);

                    // libmagic => 537
                    if (preg_match('/^libmagic => (?<version>.+)$/im', $info, $magicMatches)) {
                        $this->addLibrary($name.'-libmagic', $magicMatches['version'], 'fileinfo libmagic version');
                    }
                    break;

                case 'gd':
                    $this->addLibrary($name, $this->runtime->getConstant('GD_VERSION'));

                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^libJPEG Version => (?<version>.+?)(?: compatible)?$/im', $info, $libjpegMatches)) {
                        $this->addLibrary($name.'-libjpeg', Version::parseLibjpeg($libjpegMatches['version']), 'libjpeg version for gd');
                    }

                    if (preg_match('/^libPNG Version => (?<version>.+)$/im', $info, $libpngMatches)) {
                        $this->addLibrary($name.'-libpng', $libpngMatches['version'], 'libpng version for gd');
                    }

                    if (preg_match('/^FreeType Version => (?<version>.+)$/im', $info, $freetypeMatches)) {
                        $this->addLibrary($name.'-freetype', $freetypeMatches['version'], 'freetype version for gd');
                    }

                    if (preg_match('/^libXpm Version => (?<versionId>\d+)$/im', $info, $libxpmMatches)) {
                        $this->addLibrary($name.'-libxpm', Version::convertLibxpmVersionId($libxpmMatches['versionId']), 'libxpm version for gd');
                    }

                    break;

                case 'gmp':
                    $this->addLibrary($name, $this->runtime->getConstant('GMP_VERSION'));
                    break;

                case 'iconv':
                    $this->addLibrary($name, $this->runtime->getConstant('ICONV_VERSION'));
                    break;

                case 'intl':
                    $info = $this->runtime->getExtensionInfo($name);

                    $description = 'The ICU unicode and globalization support library';
                    // Truthy check is for testing only so we can make the condition fail
                    if ($this->runtime->hasConstant('INTL_ICU_VERSION')) {
                        $this->addLibrary('icu', $this->runtime->getConstant('INTL_ICU_VERSION'), $description);
                    } elseif (preg_match('/^ICU version => (?<version>.+)$/im', $info, $matches)) {
                        $this->addLibrary('icu', $matches['version'], $description);
                    }

                    // ICU TZData version => 2019c
                    if (preg_match('/^ICU TZData version => (?<version>.*)$/im', $info, $zoneinfoMatches)) {
                        $this->addLibrary('icu-zoneinfo', Version::parseZoneinfoVersion($zoneinfoMatches['version']), 'zoneinfo ("Olson") database for icu');
                    }

                    // Add a separate version for the CLDR library version
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

                    $this->addLibrary($name.'-imagemagick', $version, null, array('imagick'));
                    break;

                case 'ldap':
                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^Vendor Version => (?<versionId>\d+)$/im', $info, $matches) && preg_match('/^Vendor Name => (?<vendor>.+)$/im', $info, $vendorMatches)) {
                        $this->addLibrary($name.'-'.strtolower($vendorMatches['vendor']), Version::convertOpenldapVersionId($matches['versionId']), $vendorMatches['vendor'].' version of ldap');
                    }
                    break;

                case 'libxml':
                    // ext/dom, ext/simplexml, ext/xmlreader and ext/xmlwriter use the same libxml as the ext/libxml
                    $libxmlProvides = array_map(function ($extension) {
                        return $extension . '-libxml';
                    }, array_intersect($loadedExtensions, array('dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter')));
                    $this->addLibrary($name, $this->runtime->getConstant('LIBXML_DOTTED_VERSION'), 'libxml library version', array(), $libxmlProvides);

                    break;

                case 'mbstring':
                    $info = $this->runtime->getExtensionInfo($name);

                    // libmbfl version => 1.3.2
                    if (preg_match('/^libmbfl version => (?<version>.+)$/im', $info, $libmbflMatches)) {
                        $this->addLibrary($name.'-libmbfl', $libmbflMatches['version'], 'mbstring libmbfl version');
                    }

                    if ($this->runtime->hasConstant('MB_ONIGURUMA_VERSION')) {
                        $this->addLibrary($name.'-oniguruma', $this->runtime->getConstant('MB_ONIGURUMA_VERSION'), 'mbstring oniguruma version');

                    // Multibyte regex (oniguruma) version => 5.9.5
                    // oniguruma version => 6.9.0
                    } elseif (preg_match('/^(?:oniguruma|Multibyte regex \(oniguruma\)) version => (?<version>.+)$/im', $info, $onigurumaMatches)) {
                        $this->addLibrary($name.'-oniguruma', $onigurumaMatches['version'], 'mbstring oniguruma version');
                    }

                    break;

                case 'memcached':
                    $info = $this->runtime->getExtensionInfo($name);

                    // libmemcached version => 1.0.18
                    if (preg_match('/^libmemcached version => (?<version>.+)$/im', $info, $matches)) {
                        $this->addLibrary($name.'-libmemcached', $matches['version'], 'libmemcached version');
                    }
                    break;

                case 'openssl':
                    // OpenSSL 1.1.1g  21 Apr 2020
                    if (preg_match('{^(?:OpenSSL|LibreSSL)?\s*(?<version>\S+)}i', $this->runtime->getConstant('OPENSSL_VERSION_TEXT'), $matches)) {
                        $parsedVersion = Version::parseOpenssl($matches['version'], $isFips);
                        $this->addLibrary($name.($isFips ? '-fips' : ''), $parsedVersion, $this->runtime->getConstant('OPENSSL_VERSION_TEXT'), array(), $isFips ? array($name) : array());
                    }
                    break;

                case 'pcre':
                    $this->addLibrary($name, preg_replace('{^(\S+).*}', '$1', $this->runtime->getConstant('PCRE_VERSION')));

                    $info = $this->runtime->getExtensionInfo($name);

                    // PCRE Unicode Version => 12.1.0
                    if (preg_match('/^PCRE Unicode Version => (?<version>.+)$/im', $info, $pcreUnicodeMatches)) {
                        $this->addLibrary($name.'-unicode', $pcreUnicodeMatches['version'], 'PCRE Unicode version support');
                    }

                    break;

                case 'mysqlnd':
                case 'pdo_mysql':
                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^(?:Client API version|Version) => mysqlnd (?<version>.+?) /mi', $info, $matches)) {
                        $this->addLibrary($name.'-mysqlnd', $matches['version'], 'mysqlnd library version for '.$name);
                    }
                    break;

                case 'mongodb':
                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^libmongoc bundled version => (?<version>.+)$/im', $info, $libmongocMatches)) {
                        $this->addLibrary($name.'-libmongoc', $libmongocMatches['version'], 'libmongoc version of mongodb');
                    }

                    if (preg_match('/^libbson bundled version => (?<version>.+)$/im', $info, $libbsonMatches)) {
                        $this->addLibrary($name.'-libbson', $libbsonMatches['version'], 'libbson version of mongodb');
                    }
                    break;

                case 'pgsql':
                case 'pdo_pgsql':
                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^PostgreSQL\(libpq\) Version => (?<version>.*)$/im', $info, $matches)) {
                        $this->addLibrary($name.'-libpq', $matches['version'], 'libpq for '.$name);
                    }
                    break;

                case 'libsodium':
                case 'sodium':
                    if ($this->runtime->hasConstant('SODIUM_LIBRARY_VERSION')) {
                        $this->addLibrary('libsodium', $this->runtime->getConstant('SODIUM_LIBRARY_VERSION'));
                    }
                    break;

                case 'sqlite3':
                case 'pdo_sqlite':
                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^SQLite Library => (?<version>.+)$/im', $info, $matches)) {
                        $this->addLibrary($name.'-sqlite', $matches['version']);
                    }
                    break;

                case 'ssh2':
                    $info = $this->runtime->getExtensionInfo($name);

                    if (preg_match('/^libssh2 version => (?<version>.+)$/im', $info, $matches)) {
                        $this->addLibrary($name.'-libssh2', $matches['version']);
                    }
                    break;

                case 'xsl':
                    $this->addLibrary('libxslt', $this->runtime->getConstant('LIBXSLT_DOTTED_VERSION'), null, array('xsl'));

                    $info = $this->runtime->getExtensionInfo('xsl');
                    if (preg_match('/^libxslt compiled against libxml Version => (?<version>.+)$/im', $info, $matches)) {
                        $this->addLibrary('libxslt-libxml', $matches['version'], 'libxml version libxslt is compiled against');
                    }
                    break;

                case 'yaml':
                    $info = $this->runtime->getExtensionInfo('yaml');

                    if (preg_match('/^LibYAML Version => (?<version>.+)$/im', $info, $matches)) {
                        $this->addLibrary($name.'-libyaml', $matches['version'], 'libyaml version of yaml');
                    }
                    break;

                case 'zip':
                    if ($this->runtime->hasConstant('LIBZIP_VERSION', 'ZipArchive')) {
                        $this->addLibrary($name.'-libzip', $this->runtime->getConstant('LIBZIP_VERSION', 'ZipArchive'), null, array('zip'));
                    }
                    break;

                case 'zlib':
                    if ($this->runtime->hasConstant('ZLIB_VERSION')) {
                        $this->addLibrary($name, $this->runtime->getConstant('ZLIB_VERSION'));

                    // Linked Version => 1.2.8
                    } elseif (preg_match('/^Linked Version => (?<version>.+)$/im', $this->runtime->getExtensionInfo($name), $matches)) {
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
     * @inheritDoc
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
            if ($overrider instanceof CompletePackageInterface) {
                $overrider->setDescription($overrider->getDescription().', '.$actualText);
            }

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

    /**
     * @param array{version: string, name: string} $override
     * @param string|null $name
     *
     * @return CompletePackage
     */
    private function addOverriddenPackage(array $override, $name = null)
    {
        $version = $this->versionParser->normalize($override['version']);
        $package = new CompletePackage($name ?: $override['name'], $version, $override['version']);
        $package->setDescription('Package overridden via config.platform');
        $package->setExtra(array('config.platform' => true));
        parent::addPackage($package);

        if ($package->getName() === 'php') {
            self::$lastSeenPlatformPhp = implode('.', array_slice(explode('.', $package->getVersion()), 0, 3));
        }

        return $package;
    }

    /**
     * Parses the version and adds a new package to the repository
     *
     * @param string      $name
     * @param null|string $prettyVersion
     *
     * @return void
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
            $ext->setReplaces(array(
                'lib-uuid' => new Link('ext-uuid', 'lib-uuid', new Constraint('=', $version), Link::TYPE_REPLACE, $ext->getPrettyVersion()),
            ));
        }

        $this->addPackage($ext);
    }

    /**
     * @param  string $name
     * @return string
     */
    private function buildPackageName($name)
    {
        return 'ext-' . str_replace(' ', '-', strtolower($name));
    }

    /**
     * @param string      $name
     * @param string      $prettyVersion
     * @param string|null $description
     * @param string[]    $replaces
     * @param string[]    $provides
     *
     * @return void
     */
    private function addLibrary($name, $prettyVersion, $description = null, array $replaces = array(), array $provides = array())
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

        $links = function ($alias) use ($name, $version, $lib) {
            return new Link('lib-'.$name, 'lib-'.$alias, new Constraint('=', $version), Link::TYPE_REPLACE, $lib->getPrettyVersion());
        };
        $lib->setReplaces(array_map($links, $replaces));
        $lib->setProvides(array_map($links, $provides));

        $this->addPackage($lib);
    }

    /**
     * Check if a package name is a platform package.
     *
     * @param  string $name
     * @return bool
     */
    public static function isPlatformPackage($name)
    {
        static $cache = array();

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        return $cache[$name] = (bool) preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name);
    }

    /**
     * Returns the last seen config.platform.php version if defined
     *
     * This is a best effort attempt for internal purposes, retrieve the real
     * packages from a PlatformRepository instance if you need a version guaranteed to
     * be correct.
     *
     * @internal
     * @return string|null
     */
    public static function getPlatformPhpVersion()
    {
        return self::$lastSeenPlatformPhp;
    }
}
