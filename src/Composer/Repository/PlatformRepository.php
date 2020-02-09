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
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\Silencer;
use Composer\Util\Platform;
use Composer\XdebugHandler\XdebugHandler;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer-plugin-api)$}iD';

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
        $this->process = $process === null ? (new ProcessExecutor()) : $process;
        foreach ($overrides as $name => $version) {
            $this->overrides[strtolower($name)] = array('name' => $name, 'version' => $version);
        }
        parent::__construct($packages);
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
            $prettyVersion = null;
            $description = 'The '.$name.' PHP library';
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

                case 'imagick':
                    $imagick = new \Imagick();
                    $imageMagickVersion = $imagick->getVersion();
                    // 6.x: ImageMagick 6.2.9 08/24/06 Q16 http://www.imagemagick.org
                    // 7.x: ImageMagick 7.0.8-34 Q16 x86_64 2019-03-23 https://imagemagick.org
                    preg_match('/^ImageMagick ([\d.]+)(?:-(\d+))?/', $imageMagickVersion['versionString'], $matches);
                    if (isset($matches[2])) {
                        $prettyVersion = "{$matches[1]}.{$matches[2]}";
                    } else {
                        $prettyVersion = $matches[1];
                    }
                    break;

                case 'libxml':
                    $prettyVersion = LIBXML_DOTTED_VERSION;
                    break;

                case 'openssl':
                    $prettyVersion = preg_replace_callback('{^(?:OpenSSL|LibreSSL)?\s*([0-9.]+)([a-z]*).*}i', function ($match) {
                        if (empty($match[2])) {
                            return $match[1];
                        }

                        // OpenSSL versions add another letter when they reach Z.
                        // e.g. OpenSSL 0.9.8zh 3 Dec 2015

                        if (!preg_match('{^z*[a-z]$}', $match[2])) {
                            // 0.9.8abc is garbage
                            return 0;
                        }

                        $len = strlen($match[2]);
                        $patchVersion = ($len - 1) * 26; // All Z
                        $patchVersion += ord($match[2][$len - 1]) - 96;

                        return $match[1].'.'.$patchVersion;
                    }, OPENSSL_VERSION_TEXT);

                    $description = OPENSSL_VERSION_TEXT;
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
                $version = $this->versionParser->normalize($prettyVersion);
            } catch (\UnexpectedValueException $e) {
                continue;
            }

            $lib = new CompletePackage('lib-'.$name, $version, $prettyVersion);
            $lib->setDescription($description);
            $this->addPackage($lib);
        }

        $hhvmVersion = defined('HHVM_VERSION') ? HHVM_VERSION : null;
        if ($hhvmVersion === null && !Platform::isWindows()) {
            $finder = new ExecutableFinder();
            $hhvm = $finder->find('hhvm');
            if ($hhvm !== null) {
                $exitCode = $this->process->execute(
                    ProcessExecutor::escape($hhvm).
                    ' --php -d hhvm.jit=0 -r "echo HHVM_VERSION;" 2>/dev/null',
                    $hhvmVersion
                );
                if ($exitCode !== 0) {
                    $hhvmVersion = null;
                }
            }
        }
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
            $overrider->setDescription($overrider->getDescription().' ('.$actualText.')');

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
            $overrider->setDescription($overrider->getDescription().' ('.$actualText.')');

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

    private function buildPackageName($name)
    {
        return 'ext-' . str_replace(' ', '-', $name);
    }
}
