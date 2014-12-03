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
    protected $rfs;
    protected $process;
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer(false);
        if ($composer) {
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'diagnose', $input, $output);
            $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

            $output->write('Checking composer.json: ');
            $this->outputResult($output, $this->checkComposerSchema());
        }

        if ($composer) {
            $config = $composer->getConfig();
        } else {
            $config = Factory::createConfig();
        }

        $this->rfs = new RemoteFilesystem($this->getIO(), $config);
        $this->process = new ProcessExecutor($this->getIO());

        $output->write('Checking platform settings: ');
        $this->outputResult($output, $this->checkPlatform());

        $output->write('Checking git settings: ');
        $this->outputResult($output, $this->checkGit());

        $output->write('Checking http connectivity: ');
        $this->outputResult($output, $this->checkHttp());

        $opts = stream_context_get_options(StreamContextFactory::getContext('http://example.org'));
        if (!empty($opts['http']['proxy'])) {
            $output->write('Checking HTTP proxy: ');
            $this->outputResult($output, $this->checkHttpProxy());
            $output->write('Checking HTTP proxy support for request_fulluri: ');
            $this->outputResult($output, $this->checkHttpProxyFullUriRequestParam());
            $output->write('Checking HTTPS proxy support for request_fulluri: ');
            $this->outputResult($output, $this->checkHttpsProxyFullUriRequestParam());
        }

        if ($oauth = $config->get('github-oauth')) {
            foreach ($oauth as $domain => $token) {
                $output->write('Checking '.$domain.' oauth access: ');
                $this->outputResult($output, $this->checkGithubOauth($domain, $token));
            }
        }

        $output->write('Checking disk free space: ');
        $this->outputResult($output, $this->checkDiskSpace($config));

        $output->write('Checking composer version: ');
        $this->outputResult($output, $this->checkVersion());

        return $this->failures;
    }

    private function checkComposerSchema()
    {
        $validator = new ConfigValidator($this->getIO());
        list($errors, $publishErrors, $warnings) = $validator->validate(Factory::getComposerFile());

        if ($errors || $publishErrors || $warnings) {
            $messages = array(
                'error' => array_merge($errors, $publishErrors),
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
            return '<warning>Your git color.ui setting is set to always, this is known to create issues. Use "git config --global color.ui true" to set it correctly.</warning>';
        }

        return true;
    }

    private function checkHttp()
    {
        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        try {
            $json = $this->rfs->getContents('packagist.org', $protocol . '://packagist.org/packages.json', false);
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
            $rfcResult = $this->rfs->getContents('github.com', $url, false);
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

            return $this->rfs->getContents($domain, $url, false) ? true : 'Unexpected error';
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $e->getCode() === 401) {
                return '<warning>The oauth token for '.$domain.' seems invalid, run "composer config --global --unset github-oauth.'.$domain.'" to remove it</warning>';
            }

            return $e;
        }
    }

    private function checkDiskSpace($config)
    {
        $minSpaceFree = 1024*1024;
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
            return '<warning>You are not running the latest version</warning>';
        }

        return true;
    }

    private function outputResult(OutputInterface $output, $result)
    {
        if (true === $result) {
            $output->writeln('<info>OK</info>');
        } else {
            $this->failures++;
            $output->writeln('<error>FAIL</error>');
            if ($result instanceof \Exception) {
                $output->writeln('['.get_class($result).'] '.$result->getMessage());
            } elseif ($result) {
                $output->writeln($result);
            }
        }
    }

    private function checkPlatform()
    {
        $output = '';
        $out = function ($msg, $style) use (&$output) {
            $output .= '<'.$style.'>'.$msg.'</'.$style.'>';
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

        if (!ini_get('allow_url_fopen')) {
            $errors['allow_url_fopen'] = true;
        }

        if (version_compare(PHP_VERSION, '5.3.2', '<')) {
            $errors['php'] = PHP_VERSION;
        }

        if (!isset($errors['php']) && version_compare(PHP_VERSION, '5.3.4', '<')) {
            $warnings['php'] = PHP_VERSION;
        }

        if (!extension_loaded('openssl')) {
            $warnings['openssl'] = true;
        }

        if (!defined('HHVM_VERSION') && !extension_loaded('apcu') && ini_get('apc.enable_cli')) {
            $warnings['apc_cli'] = true;
        }

        if (ini_get('xdebug.profiler_enabled')) {
            $warnings['xdebug_profile'] = true;
        } elseif (extension_loaded('xdebug')) {
            $warnings['xdebug_loaded'] = true;
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

        if (!empty($errors)) {
            foreach ($errors as $error => $current) {
                switch ($error) {
                    case 'php':
                        $text = PHP_EOL."Your PHP ({$current}) is too old, you must upgrade to PHP 5.3.2 or higher.";
                        break;

                    case 'allow_url_fopen':
                        $text = PHP_EOL."The allow_url_fopen setting is incorrect.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini`:".PHP_EOL;
                        $text .= "    allow_url_fopen = On";
                        $displayIniMessage = true;
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
                        $text = PHP_EOL."The apc.enable_cli setting is incorrect.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini`:".PHP_EOL;
                        $text .= "    apc.enable_cli = Off";
                        $displayIniMessage = true;
                        break;

                    case 'sigchild':
                        $text = PHP_EOL."PHP was compiled with --enable-sigchild which can cause issues on some platforms.".PHP_EOL;
                        $text .= "Recompile it without this flag if possible, see also:".PHP_EOL;
                        $text .= "    https://bugs.php.net/bug.php?id=22999";
                        break;

                    case 'curlwrappers':
                        $text = PHP_EOL."PHP was compiled with --with-curlwrappers which will cause issues with HTTP authentication and GitHub.".PHP_EOL;
                        $text .= "Recompile it without this flag if possible";
                        break;

                    case 'openssl':
                        $text = PHP_EOL."The openssl extension is missing, which will reduce the security and stability of Composer.".PHP_EOL;
                        $text .= "If possible you should enable it or recompile php with --with-openssl";
                        break;

                    case 'php':
                        $text = PHP_EOL."Your PHP ({$current}) is quite old, upgrading to PHP 5.3.4 or higher is recommended.".PHP_EOL;
                        $text .= "Composer works with 5.3.2+ for most people, but there might be edge case issues.";
                        break;

                    case 'xdebug_loaded':
                        $text = PHP_EOL."The xdebug extension is loaded, this can slow down Composer a little.".PHP_EOL;
                        $text .= "Disabling it when using Composer is recommended, but should not cause issues beyond slowness.";
                        break;

                    case 'xdebug_profile':
                        $text = PHP_EOL."The xdebug.profiler_enabled setting is enabled, this can slow down Composer a lot.".PHP_EOL;
                        $text .= "Add the following to the end of your `php.ini` to disable it:".PHP_EOL;
                        $text .= "    xdebug.profiler_enabled = 0";
                        $displayIniMessage = true;
                        break;
                }
                $out($text, 'warning');
            }
        }

        if ($displayIniMessage) {
            $out($iniMessage, 'warning');
        }

        return !$warnings && !$errors ? true : $output;
    }
}
