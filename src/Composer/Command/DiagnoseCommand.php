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
use Composer\Downloader\TransportException;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Util\ConfigValidator;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Composer\Util\StreamContextFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DiagnoseCommand extends Command
{
    /** @var RemoteFileSystem */
    protected $rfs;

    /** @var ProcessExecutor */
    protected $process;

    /** @var int */
    protected $failures = 0;

    protected function configure()
    {
        $this
            ->setName('diagnose')
            ->setDescription('Diagnoses the system to identify common errors.')
            ->setHelp(<<<EOT
The <info>diagnose</info> command checks common errors to help debugging problems.

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

        $this->rfs = new RemoteFilesystem($io, $config);
        $this->process = new ProcessExecutor($io);

        $io->write('Checking platform settings: ', false);
        $this->outputResult($this->checkPlatform());

        $io->write('Checking git settings: ', false);
        $this->outputResult($this->checkGit());

        $io->write('Checking http connectivity to packagist: ', false);
        $this->outputResult($this->checkHttp('http'));

        $io->write('Checking https connectivity to packagist: ', false);
        $this->outputResult($this->checkHttp('https'));

        $opts = stream_context_get_options(StreamContextFactory::getContext('http://example.org'));
        if (!empty($opts['http']['proxy'])) {
            $io->write('Checking HTTP proxy: ', false);
            $this->outputResult($this->checkHttpProxy());
            $io->write('Checking HTTP proxy support for request_fulluri: ', false);
            $this->outputResult($this->checkHttpProxyFullUriRequestParam());
            $io->write('Checking HTTPS proxy support for request_fulluri: ', false);
            $this->outputResult($this->checkHttpsProxyFullUriRequestParam());
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
                $this->outputResult(true);
                if (10 > $rate['remaining']) {
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

        $io->write('Checking composer version: ', false);
        $this->outputResult($this->checkVersion());

        return $this->failures;
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
                    $output .=  '<' . $style . '>' . $msg . '</' . $style . '>' . PHP_EOL;
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

    private function checkHttp($proto)
    {
        try {
            $this->rfs->getContents('packagist.org', $proto . '://packagist.org/packages.json', false);
        } catch (\Exception $e) {
            return $e;
        }

        return true;
    }

    private function checkHttpProxy()
    {
        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        try {
            $json = json_decode($this->rfs->getContents('packagist.org', $protocol . '://packagist.org/packages.json', false), true);
            $hash = reset($json['provider-includes']);
            $hash = $hash['sha256'];
            $path = str_replace('%hash%', $hash, key($json['provider-includes']));
            $provider = $this->rfs->getContents('packagist.org', $protocol . '://packagist.org/'.$path, false);

            if (hash('sha256', $provider) !== $hash) {
                return 'It seems that your proxy is modifying http traffic on the fly';
            }
        } catch (\Exception $e) {
            return $e;
        }

        return true;
    }

    /**
     * Due to various proxy servers configurations, some servers can't handle non-standard HTTP "http_proxy_request_fulluri" parameter,
     * and will return error 500/501 (as not implemented), see discussion @ https://github.com/composer/composer/pull/1825.
     * This method will test, if you need to disable this parameter via setting extra environment variable in your system.
     *
     * @return bool|string
     */
    private function checkHttpProxyFullUriRequestParam()
    {
        $url = 'http://packagist.org/packages.json';
        try {
            $this->rfs->getContents('packagist.org', $url, false);
        } catch (TransportException $e) {
            try {
                $this->rfs->getContents('packagist.org', $url, false, array('http' => array('request_fulluri' => false)));
            } catch (TransportException $e) {
                return 'Unable to assess the situation, maybe packagist.org is down ('.$e->getMessage().')';
            }

            return 'It seems there is a problem with your proxy server, try setting the "HTTP_PROXY_REQUEST_FULLURI" and "HTTPS_PROXY_REQUEST_FULLURI" environment variables to "false"';
        }

        return true;
    }

    /**
     * Due to various proxy servers configurations, some servers can't handle non-standard HTTP "http_proxy_request_fulluri" parameter,
     * and will return error 500/501 (as not implemented), see discussion @ https://github.com/composer/composer/pull/1825.
     * This method will test, if you need to disable this parameter via setting extra environment variable in your system.
     *
     * @return bool|string
     */
    private function checkHttpsProxyFullUriRequestParam()
    {
        if (!extension_loaded('openssl')) {
            return 'You need the openssl extension installed for this check';
        }

        $url = 'https://api.github.com/repos/Seldaek/jsonlint/zipball/1.0.0';
        try {
            $this->rfs->getContents('github.com', $url, false);
        } catch (TransportException $e) {
            try {
                $this->rfs->getContents('github.com', $url, false, array('http' => array('request_fulluri' => false)));
            } catch (TransportException $e) {
                return 'Unable to assess the situation, maybe github is down ('.$e->getMessage().')';
            }

            return 'It seems there is a problem with your proxy server, try setting the "HTTPS_PROXY_REQUEST_FULLURI" environment variable to "false"';
        }

        return true;
    }

    private function checkGithubOauth($domain, $token)
    {
        $this->getIO()->setAuthentication($domain, $token, 'x-oauth-basic');
        try {
            $url = $domain === 'github.com' ? 'https://api.'.$domain.'/user/repos' : 'https://'.$domain.'/api/v3/user/repos';

            return $this->rfs->getContents($domain, $url, false, array(
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
     * @return array
     */
    private function getGithubRateLimit($domain, $token = null)
    {
        if ($token) {
            $this->getIO()->setAuthentication($domain, $token, 'x-oauth-basic');
        }

        $url = $domain === 'github.com' ? 'https://api.'.$domain.'/rate_limit' : 'https://'.$domain.'/api/rate_limit';
        $json = $this->rfs->getContents($domain, $url, false, array('retry-auth-failure' => false));
        $data = json_decode($json, true);

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

    private function checkVersion()
    {
        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        $latest = trim($this->rfs->getContents('getcomposer.org', $protocol . '://getcomposer.org/version', false));

        if (Composer::VERSION !== $latest && Composer::VERSION !== '@package_version@') {
            return '<comment>You are not running the latest version, run `composer self-update` to update</comment>';
        }

        return true;
    }

    /**
     * @param bool|string|\Exception $result
     */
    private function outputResult($result)
    {
        $io = $this->getIO();
        if (true === $result) {
            $io->write('<info>OK</info>');
        } else {
            $this->failures++;
            $io->write('<error>FAIL</error>');
            if ($result instanceof \Exception) {
                $io->write('['.get_class($result).'] '.$result->getMessage());
            } elseif ($result) {
                $io->write(trim($result));
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

        $iniPath = php_ini_loaded_file();
        $displayIniMessage = false;
        if ($iniPath) {
            $iniMessage = PHP_EOL.PHP_EOL.'The php.ini used by your command-line PHP is: ' . $iniPath;
        } else {
            $iniMessage = PHP_EOL.PHP_EOL.'A php.ini file does not exist. You will have to create one.';
        }
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

        if (!ini_get('allow_url_fopen')) {
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

        if (!defined('HHVM_VERSION') && !extension_loaded('apcu') && ini_get('apc.enable_cli')) {
            $warnings['apc_cli'] = true;
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

        if (ini_get('xdebug.profiler_enabled')) {
            $warnings['xdebug_profile'] = true;
        } elseif (extension_loaded('xdebug')) {
            $warnings['xdebug_loaded'] = true;
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

                    case 'unicode':
                        $text = PHP_EOL."The detect_unicode setting must be disabled.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini`:".PHP_EOL;
                        $text .= "    detect_unicode = Off";
                        $displayIniMessage = true;
                        break;

                    case 'suhosin':
                        $text = PHP_EOL."The suhosin.executor.include.whitelist setting is incorrect.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini` or suhosin.ini (Example path [for Debian]: /etc/php5/cli/conf.d/suhosin.ini):".PHP_EOL;
                        $text .= "    suhosin.executor.include.whitelist = phar ".$current;
                        $displayIniMessage = true;
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
                }
                $out($text, 'error');
            }

            $output .= PHP_EOL;
        }

        if (!empty($warnings)) {
            foreach ($warnings as $warning => $current) {
                switch ($warning) {
                    case 'apc_cli':
                        $text  = "The apc.enable_cli setting is incorrect.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini`:".PHP_EOL;
                        $text .= "  apc.enable_cli = Off";
                        $displayIniMessage = true;
                        break;

                    case 'sigchild':
                        $text  = "PHP was compiled with --enable-sigchild which can cause issues on some platforms.".PHP_EOL;
                        $text .= "Recompile it without this flag if possible, see also:".PHP_EOL;
                        $text .= "  https://bugs.php.net/bug.php?id=22999";
                        break;

                    case 'curlwrappers':
                        $text  = "PHP was compiled with --with-curlwrappers which will cause issues with HTTP authentication and GitHub.".PHP_EOL;
                        $text .= " Recompile it without this flag if possible";
                        break;

                    case 'php':
                        $text  = "Your PHP ({$current}) is quite old, upgrading to PHP 5.3.4 or higher is recommended.".PHP_EOL;
                        $text .= " Composer works with 5.3.2+ for most people, but there might be edge case issues.";
                        break;

                    case 'xdebug_loaded':
                        $text  = "The xdebug extension is loaded, this can slow down Composer a little.".PHP_EOL;
                        $text .= " Disabling it when using Composer is recommended.";
                        break;

                    case 'xdebug_profile':
                        $text  = "The xdebug.profiler_enabled setting is enabled, this can slow down Composer a lot.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini` to disable it:".PHP_EOL;
                        $text .= "  xdebug.profiler_enabled = 0";
                        $displayIniMessage = true;
                        break;
                }
                $out($text, 'comment');
            }
        }

        if ($displayIniMessage) {
            $out($iniMessage, 'comment');
        }

        return !$warnings && !$errors ? true : $output;
    }
}
