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

use Composer\Composer;
use Composer\Factory;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\Repository\PlatformRepository;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Util\ConfigValidator;
use Composer\Util\IniHelper;
use Composer\Util\ProcessExecutor;
use Composer\Util\HttpDownloader;
use Composer\Util\StreamContextFactory;
use Composer\Util\Platform;
use Composer\SelfUpdate\Keys;
use Composer\SelfUpdate\Versions;
use Composer\IO\NullIO;
use Composer\Package\CompletePackageInterface;
use Composer\XdebugHandler\XdebugHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DiagnoseCommand extends BaseCommand
{
    /** @var HttpDownloader */
    protected $httpDownloader;

    /** @var ProcessExecutor */
    protected $process;

    /** @var int */
    protected $exitCode = 0;

    protected function configure()
    {
        $this
            ->setName('diagnose')
            ->setDescription('Diagnoses the system to identify common errors.')
            ->setHelp(
                <<<EOT
The <info>diagnose</info> command checks common errors to help debugging problems.

The process exit code will be 1 in case of warnings and 2 for errors.

Read more at https://getcomposer.org/doc/03-cli.md#diagnose
EOT
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer(false);
        $io = $this->getIO();

        if ($composer) {
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'diagnose', $input, $output);
            $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

            $io->write('Checking composer.json: ', false);
            $this->outputResult($this->checkComposerSchema());
        }

        if ($composer) {
            $config = $composer->getConfig();
        } else {
            $config = Factory::createConfig();
        }

        $config->merge(array('config' => array('secure-http' => false)));
        $config->prohibitUrlByConfig('http://repo.packagist.org', new NullIO);

        $this->httpDownloader = Factory::createHttpDownloader($io, $config);
        $this->process = new ProcessExecutor($io);

        $io->write('Checking platform settings: ', false);
        $this->outputResult($this->checkPlatform());

        $io->write('Checking git settings: ', false);
        $this->outputResult($this->checkGit());

        $io->write('Checking http connectivity to packagist: ', false);
        $this->outputResult($this->checkHttp('http', $config));

        $io->write('Checking https connectivity to packagist: ', false);
        $this->outputResult($this->checkHttp('https', $config));

        $opts = stream_context_get_options(StreamContextFactory::getContext('http://example.org'));
        if (!empty($opts['http']['proxy'])) {
            $io->write('Checking HTTP proxy: ', false);
            $this->outputResult($this->checkHttpProxy());
        }

        if ($oauth = $config->get('github-oauth')) {
            foreach ($oauth as $domain => $token) {
                $io->write('Checking '.$domain.' oauth access: ', false);
                $this->outputResult($this->checkGithubOauth($domain, $token));
            }
        } else {
            $io->write('Checking github.com rate limit: ', false);
            try {
                $rate = $this->getGithubRateLimit('github.com');
                if (!is_array($rate)) {
                    $this->outputResult($rate);
                } elseif (10 > $rate['remaining']) {
                    $io->write('<warning>WARNING</warning>');
                    $io->write(sprintf(
                        '<comment>Github has a rate limit on their API. '
                        . 'You currently have <options=bold>%u</options=bold> '
                        . 'out of <options=bold>%u</options=bold> requests left.' . PHP_EOL
                        . 'See https://developer.github.com/v3/#rate-limiting and also' . PHP_EOL
                        . '    https://getcomposer.org/doc/articles/troubleshooting.md#api-rate-limit-and-oauth-tokens</comment>',
                        $rate['remaining'],
                        $rate['limit']
                    ));
                } else {
                    $this->outputResult(true);
                }
            } catch (\Exception $e) {
                if ($e instanceof TransportException && $e->getCode() === 401) {
                    $this->outputResult('<comment>The oauth token for github.com seems invalid, run "composer config --global --unset github-oauth.github.com" to remove it</comment>');
                } else {
                    $this->outputResult($e);
                }
            }
        }

        $io->write('Checking disk free space: ', false);
        $this->outputResult($this->checkDiskSpace($config));

        if (strpos(__FILE__, 'phar:') === 0) {
            $io->write('Checking pubkeys: ', false);
            $this->outputResult($this->checkPubKeys($config));

            $io->write('Checking composer version: ', false);
            $this->outputResult($this->checkVersion($config));
        }

        $io->write(sprintf('Composer version: <comment>%s</comment>', Composer::getVersion()));

        $platformOverrides = $config->get('platform') ?: array();
        $platformRepo = new PlatformRepository(array(), $platformOverrides);
        $phpPkg = $platformRepo->findPackage('php', '*');
        $phpVersion = $phpPkg->getPrettyVersion();
        if ($phpPkg instanceof CompletePackageInterface && false !== strpos($phpPkg->getDescription(), 'overridden')) {
            $phpVersion .= ' - ' . $phpPkg->getDescription();
        }

        $io->write(sprintf('PHP version: <comment>%s</comment>', $phpVersion));

        if (defined('PHP_BINARY')) {
            $io->write(sprintf('PHP binary path: <comment>%s</comment>', PHP_BINARY));
        }

        $io->write('OpenSSL version: ' . (defined('OPENSSL_VERSION_TEXT') ? '<comment>'.OPENSSL_VERSION_TEXT.'</comment>' : '<error>missing</error>'));
        $io->write('cURL version: ' . $this->getCurlVersion());

        $finder = new ExecutableFinder;
        $hasSystemUnzip = (bool) $finder->find('unzip');
        $bin7zip = '';
        if ($hasSystem7zip = (bool) $finder->find('7z', null, array('C:\Program Files\7-Zip'))) {
            $bin7zip = '7z';
        }
        if (!Platform::isWindows() && !$hasSystem7zip && $hasSystem7zip = (bool) $finder->find('7zz')) {
            $bin7zip = '7zz';
        }

        $io->write(
            'zip: ' . (extension_loaded('zip') ? '<comment>extension present</comment>' : '<comment>extension not loaded</comment>')
            . ', ' . ($hasSystemUnzip ? '<comment>unzip present</comment>' : '<comment>unzip not available</comment>')
            . ', ' . ($hasSystem7zip ? '<comment>7-Zip present ('.$bin7zip.')</comment>' : '<comment>7-Zip not available</comment>')
        );

        return $this->exitCode;
    }

    private function checkComposerSchema()
    {
        $validator = new ConfigValidator($this->getIO());
        list($errors, , $warnings) = $validator->validate(Factory::getComposerFile());

        if ($errors || $warnings) {
            $messages = array(
                'error' => $errors,
                'warning' => $warnings,
            );

            $output = '';
            foreach ($messages as $style => $msgs) {
                foreach ($msgs as $msg) {
                    $output .= '<' . $style . '>' . $msg . '</' . $style . '>' . PHP_EOL;
                }
            }

            return rtrim($output);
        }

        return true;
    }

    private function checkGit()
    {
        $this->process->execute('git config color.ui', $output);
        if (strtolower(trim($output)) === 'always') {
            return '<comment>Your git color.ui setting is set to always, this is known to create issues. Use "git config --global color.ui true" to set it correctly.</comment>';
        }

        return true;
    }

    private function checkHttp($proto, Config $config)
    {
        $result = $this->checkConnectivity();
        if ($result !== true) {
            return $result;
        }

        $result = array();
        if ($proto === 'https' && $config->get('disable-tls') === true) {
            $tlsWarning = '<warning>Composer is configured to disable SSL/TLS protection. This will leave remote HTTPS requests vulnerable to Man-In-The-Middle attacks.</warning>';
        }

        try {
            $this->httpDownloader->get($proto . '://repo.packagist.org/packages.json');
        } catch (TransportException $e) {
            if ($hints = HttpDownloader::getExceptionHints($e)) {
                foreach ($hints as $hint) {
                    $result[] = $hint;
                }
            }

            $result[] = '<error>[' . get_class($e) . '] ' . $e->getMessage() . '</error>';
        }

        if (isset($tlsWarning)) {
            $result[] = $tlsWarning;
        }

        if (count($result) > 0) {
            return $result;
        }

        return true;
    }

    private function checkHttpProxy()
    {
        $result = $this->checkConnectivity();
        if ($result !== true) {
            return $result;
        }

        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        try {
            $json = $this->httpDownloader->get($protocol . '://repo.packagist.org/packages.json')->decodeJson();
            $hash = reset($json['provider-includes']);
            $hash = $hash['sha256'];
            $path = str_replace('%hash%', $hash, key($json['provider-includes']));
            $provider = $this->httpDownloader->get($protocol . '://repo.packagist.org/'.$path)->getBody();

            if (hash('sha256', $provider) !== $hash) {
                return 'It seems that your proxy is modifying http traffic on the fly';
            }
        } catch (\Exception $e) {
            return $e;
        }

        return true;
    }

    private function checkGithubOauth($domain, $token)
    {
        $result = $this->checkConnectivity();
        if ($result !== true) {
            return $result;
        }

        $this->getIO()->setAuthentication($domain, $token, 'x-oauth-basic');
        try {
            $url = $domain === 'github.com' ? 'https://api.'.$domain.'/' : 'https://'.$domain.'/api/v3/';

            return $this->httpDownloader->get($url, array(
                'retry-auth-failure' => false,
            )) ? true : 'Unexpected error';
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $e->getCode() === 401) {
                return '<comment>The oauth token for '.$domain.' seems invalid, run "composer config --global --unset github-oauth.'.$domain.'" to remove it</comment>';
            }

            return $e;
        }
    }

    /**
     * @param  string             $domain
     * @param  string             $token
     * @throws TransportException
     * @return array|string
     */
    private function getGithubRateLimit($domain, $token = null)
    {
        $result = $this->checkConnectivity();
        if ($result !== true) {
            return $result;
        }

        if ($token) {
            $this->getIO()->setAuthentication($domain, $token, 'x-oauth-basic');
        }

        $url = $domain === 'github.com' ? 'https://api.'.$domain.'/rate_limit' : 'https://'.$domain.'/api/rate_limit';
        $data = $this->httpDownloader->get($url, array('retry-auth-failure' => false))->decodeJson();

        return $data['resources']['core'];
    }

    private function checkDiskSpace($config)
    {
        $minSpaceFree = 1024 * 1024;
        if ((($df = @disk_free_space($dir = $config->get('home'))) !== false && $df < $minSpaceFree)
            || (($df = @disk_free_space($dir = $config->get('vendor-dir'))) !== false && $df < $minSpaceFree)
        ) {
            return '<error>The disk hosting '.$dir.' is full</error>';
        }

        return true;
    }

    private function checkPubKeys($config)
    {
        $home = $config->get('home');
        $errors = array();
        $io = $this->getIO();

        if (file_exists($home.'/keys.tags.pub') && file_exists($home.'/keys.dev.pub')) {
            $io->write('');
        }

        if (file_exists($home.'/keys.tags.pub')) {
            $io->write('Tags Public Key Fingerprint: ' . Keys::fingerprint($home.'/keys.tags.pub'));
        } else {
            $errors[] = '<error>Missing pubkey for tags verification</error>';
        }

        if (file_exists($home.'/keys.dev.pub')) {
            $io->write('Dev Public Key Fingerprint: ' . Keys::fingerprint($home.'/keys.dev.pub'));
        } else {
            $errors[] = '<error>Missing pubkey for dev verification</error>';
        }

        if ($errors) {
            $errors[] = '<error>Run composer self-update --update-keys to set them up</error>';
        }

        return $errors ?: true;
    }

    private function checkVersion($config)
    {
        $result = $this->checkConnectivity();
        if ($result !== true) {
            return $result;
        }

        $versionsUtil = new Versions($config, $this->httpDownloader);
        try {
            $latest = $versionsUtil->getLatest();
        } catch (\Exception $e) {
            return $e;
        }

        if (Composer::VERSION !== $latest['version'] && Composer::VERSION !== '@package_version@') {
            return '<comment>You are not running the latest '.$versionsUtil->getChannel().' version, run `composer self-update` to update ('.Composer::VERSION.' => '.$latest['version'].')</comment>';
        }

        return true;
    }

    private function getCurlVersion()
    {
        if (extension_loaded('curl')) {
            if (!HttpDownloader::isCurlEnabled()) {
                return '<error>disabled via disable_functions, using php streams fallback, which reduces performance</error>';
            }

            $version = curl_version();

            return '<comment>'.$version['version'].'</comment> '.
                'libz <comment>'.(isset($version['libz_version']) ? $version['libz_version'] : 'missing').'</comment> '.
                'ssl <comment>'.(isset($version['ssl_version']) ? $version['ssl_version'] : 'missing').'</comment>';
        }

        return '<error>missing, using php streams fallback, which reduces performance</error>';
    }

    /**
     * @param bool|string|string[]|\Exception $result
     */
    private function outputResult($result)
    {
        $io = $this->getIO();
        if (true === $result) {
            $io->write('<info>OK</info>');

            return;
        }

        $hadError = false;
        $hadWarning = false;
        if ($result instanceof \Exception) {
            $result = '<error>['.get_class($result).'] '.$result->getMessage().'</error>';
        }

        if (!$result) {
            // falsey results should be considered as an error, even if there is nothing to output
            $hadError = true;
        } else {
            if (!is_array($result)) {
                $result = array($result);
            }
            foreach ($result as $message) {
                if (false !== strpos($message, '<error>')) {
                    $hadError = true;
                } elseif (false !== strpos($message, '<warning>')) {
                    $hadWarning = true;
                }
            }
        }

        if ($hadError) {
            $io->write('<error>FAIL</error>');
            $this->exitCode = max($this->exitCode, 2);
        } elseif ($hadWarning) {
            $io->write('<warning>WARNING</warning>');
            $this->exitCode = max($this->exitCode, 1);
        }

        if ($result) {
            foreach ($result as $message) {
                $io->write($message);
            }
        }
    }

    private function checkPlatform()
    {
        $output = '';
        $out = function ($msg, $style) use (&$output) {
            $output .= '<'.$style.'>'.$msg.'</'.$style.'>'.PHP_EOL;
        };

        // code below taken from getcomposer.org/installer, any changes should be made there and replicated here
        $errors = array();
        $warnings = array();
        $displayIniMessage = false;

        $iniMessage = PHP_EOL.PHP_EOL.IniHelper::getMessage();
        $iniMessage .= PHP_EOL.'If you can not modify the ini file, you can also run `php -d option=value` to modify ini values on the fly. You can use -d multiple times.';

        if (!function_exists('json_decode')) {
            $errors['json'] = true;
        }

        if (!extension_loaded('Phar')) {
            $errors['phar'] = true;
        }

        if (!extension_loaded('filter')) {
            $errors['filter'] = true;
        }

        if (!extension_loaded('hash')) {
            $errors['hash'] = true;
        }

        if (!extension_loaded('iconv') && !extension_loaded('mbstring')) {
            $errors['iconv_mbstring'] = true;
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $errors['allow_url_fopen'] = true;
        }

        if (extension_loaded('ionCube Loader') && ioncube_loader_iversion() < 40009) {
            $errors['ioncube'] = ioncube_loader_version();
        }

        if (PHP_VERSION_ID < 50302) {
            $errors['php'] = PHP_VERSION;
        }

        if (!isset($errors['php']) && PHP_VERSION_ID < 50304) {
            $warnings['php'] = PHP_VERSION;
        }

        if (!extension_loaded('openssl')) {
            $errors['openssl'] = true;
        }

        if (extension_loaded('openssl') && OPENSSL_VERSION_NUMBER < 0x1000100f) {
            $warnings['openssl_version'] = true;
        }

        if (!defined('HHVM_VERSION') && !extension_loaded('apcu') && filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN)) {
            $warnings['apc_cli'] = true;
        }

        if (!extension_loaded('zlib')) {
            $warnings['zlib'] = true;
        }

        ob_start();
        phpinfo(INFO_GENERAL);
        $phpinfo = ob_get_clean();
        if (preg_match('{Configure Command(?: *</td><td class="v">| *=> *)(.*?)(?:</td>|$)}m', $phpinfo, $match)) {
            $configure = $match[1];

            if (false !== strpos($configure, '--enable-sigchild')) {
                $warnings['sigchild'] = true;
            }

            if (false !== strpos($configure, '--with-curlwrappers')) {
                $warnings['curlwrappers'] = true;
            }
        }

        if (filter_var(ini_get('xdebug.profiler_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $warnings['xdebug_profile'] = true;
        } elseif (XdebugHandler::isXdebugActive()) {
            $warnings['xdebug_loaded'] = true;
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD')
            && (version_compare(PHP_VERSION, '7.2.23', '<')
            || (version_compare(PHP_VERSION, '7.3.0', '>=')
            && version_compare(PHP_VERSION, '7.3.10', '<')))) {
            $warnings['onedrive'] = PHP_VERSION;
        }

        if (!empty($errors)) {
            foreach ($errors as $error => $current) {
                switch ($error) {
                    case 'json':
                        $text = PHP_EOL."The json extension is missing.".PHP_EOL;
                        $text .= "Install it or recompile php without --disable-json";
                        break;

                    case 'phar':
                        $text = PHP_EOL."The phar extension is missing.".PHP_EOL;
                        $text .= "Install it or recompile php without --disable-phar";
                        break;

                    case 'filter':
                        $text = PHP_EOL."The filter extension is missing.".PHP_EOL;
                        $text .= "Install it or recompile php without --disable-filter";
                        break;

                    case 'hash':
                        $text = PHP_EOL."The hash extension is missing.".PHP_EOL;
                        $text .= "Install it or recompile php without --disable-hash";
                        break;

                    case 'iconv_mbstring':
                        $text = PHP_EOL."The iconv OR mbstring extension is required and both are missing.".PHP_EOL;
                        $text .= "Install either of them or recompile php without --disable-iconv";
                        break;

                    case 'php':
                        $text = PHP_EOL."Your PHP ({$current}) is too old, you must upgrade to PHP 5.3.2 or higher.";
                        break;

                    case 'allow_url_fopen':
                        $text = PHP_EOL."The allow_url_fopen setting is incorrect.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini`:".PHP_EOL;
                        $text .= "    allow_url_fopen = On";
                        $displayIniMessage = true;
                        break;

                    case 'ioncube':
                        $text = PHP_EOL."Your ionCube Loader extension ($current) is incompatible with Phar files.".PHP_EOL;
                        $text .= "Upgrade to ionCube 4.0.9 or higher or remove this line (path may be different) from your `php.ini` to disable it:".PHP_EOL;
                        $text .= "    zend_extension = /usr/lib/php5/20090626+lfs/ioncube_loader_lin_5.3.so";
                        $displayIniMessage = true;
                        break;

                    case 'openssl':
                        $text = PHP_EOL."The openssl extension is missing, which means that secure HTTPS transfers are impossible.".PHP_EOL;
                        $text .= "If possible you should enable it or recompile php with --with-openssl";
                        break;

                    default:
                        throw new \InvalidArgumentException(sprintf("DiagnoseCommand: Unknown error type \"%s\". Please report at https://github.com/composer/composer/issues/new.", $error));
                }
                $out($text, 'error');
            }

            $output .= PHP_EOL;
        }

        if (!empty($warnings)) {
            foreach ($warnings as $warning => $current) {
                switch ($warning) {
                    case 'apc_cli':
                        $text = "The apc.enable_cli setting is incorrect.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini`:".PHP_EOL;
                        $text .= "  apc.enable_cli = Off";
                        $displayIniMessage = true;
                        break;

                    case 'zlib':
                        $text = 'The zlib extension is not loaded, this can slow down Composer a lot.'.PHP_EOL;
                        $text .= 'If possible, enable it or recompile php with --with-zlib'.PHP_EOL;
                        $displayIniMessage = true;
                        break;

                    case 'sigchild':
                        $text = "PHP was compiled with --enable-sigchild which can cause issues on some platforms.".PHP_EOL;
                        $text .= "Recompile it without this flag if possible, see also:".PHP_EOL;
                        $text .= "  https://bugs.php.net/bug.php?id=22999";
                        break;

                    case 'curlwrappers':
                        $text = "PHP was compiled with --with-curlwrappers which will cause issues with HTTP authentication and GitHub.".PHP_EOL;
                        $text .= " Recompile it without this flag if possible";
                        break;

                    case 'php':
                        $text = "Your PHP ({$current}) is quite old, upgrading to PHP 5.3.4 or higher is recommended.".PHP_EOL;
                        $text .= " Composer works with 5.3.2+ for most people, but there might be edge case issues.";
                        break;

                    case 'openssl_version':
                        // Attempt to parse version number out, fallback to whole string value.
                        $opensslVersion = strstr(trim(strstr(OPENSSL_VERSION_TEXT, ' ')), ' ', true);
                        $opensslVersion = $opensslVersion ?: OPENSSL_VERSION_TEXT;

                        $text = "The OpenSSL library ({$opensslVersion}) used by PHP does not support TLSv1.2 or TLSv1.1.".PHP_EOL;
                        $text .= "If possible you should upgrade OpenSSL to version 1.0.1 or above.";
                        break;

                    case 'xdebug_loaded':
                        $text = "The xdebug extension is loaded, this can slow down Composer a little.".PHP_EOL;
                        $text .= " Disabling it when using Composer is recommended.";
                        break;

                    case 'xdebug_profile':
                        $text = "The xdebug.profiler_enabled setting is enabled, this can slow down Composer a lot.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini` to disable it:".PHP_EOL;
                        $text .= "  xdebug.profiler_enabled = 0";
                        $displayIniMessage = true;
                        break;

                    case 'onedrive':
                        $text = "The Windows OneDrive folder is not supported on PHP versions below 7.2.23 and 7.3.10.".PHP_EOL;
                        $text .= "Upgrade your PHP ({$current}) to use this location with Composer.".PHP_EOL;
                        break;

                    default:
                        throw new \InvalidArgumentException(sprintf("DiagnoseCommand: Unknown warning type \"%s\". Please report at https://github.com/composer/composer/issues/new.", $warning));
                }
                $out($text, 'comment');
            }
        }

        if ($displayIniMessage) {
            $out($iniMessage, 'comment');
        }

        return !$warnings && !$errors ? true : $output;
    }

    /**
     * Check if allow_url_fopen is ON
     *
     * @return true|string
     */
    private function checkConnectivity()
    {
        if (!ini_get('allow_url_fopen')) {
            return '<info>Skipped because allow_url_fopen is missing.</info>';
        }

        return true;
    }
}
