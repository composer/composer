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
use Composer\Util\ConfigValidator;
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
        $this->rfs = new RemoteFilesystem($this->getIO());

        $output->write('Checking platform settings: ');
        $this->outputResult($output, $this->checkPlatform());

        $output->write('Checking http connectivity: ');
        $this->outputResult($output, $this->checkHttp());

        $opts = stream_context_get_options(StreamContextFactory::getContext());
        if (!empty($opts['http']['proxy'])) {
            $output->write('Checking HTTP proxy: ');
            $this->outputResult($output, $this->checkHttpProxy());
            $output->write('Checking HTTP proxy fullrequest_uri: ');
            $this->outputResult($output, $this->checkHttpProxyFullUriRequestParam($opts));
        }

        $composer = $this->getComposer(false);
        if ($composer) {
            $output->write('Checking composer.json: ');
            $this->outputResult($output, $this->checkComposerSchema());
        }

        if ($composer) {
            $config = $composer->getConfig();
        } else {
            $config = Factory::createConfig();
        }

        if ($oauth = $config->get('github-oauth')) {
            foreach ($oauth as $domain => $token) {
                $output->write('Checking '.$domain.' oauth access: ');
                $this->outputResult($output, $this->checkGithubOauth($domain, $token));
            }
        }

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

    private function checkHttpProxyFullUriRequestParam($opts)
    {
        if (!function_exists('curl_version'))
        {
            return "Sorry, but you need CURL extension installed for this check\n";
        }

        if (!extension_loaded('openssl'))
        {
            return "Sorry, but you need openssl extension installed for this check\n";
        }

        $protocol = 'https';
        $resultMessage = true;

        set_error_handler(function ($code, $msg) use (&$errorMessage) {
        });

        /* i've found no better file to test with so far :( */
        $filePath = '://api.github.com/repos/Seldaek/jsonlint/zipball/1.0.0 ';
        try
        {
            $rfcResult = $this->rfs->getContents('api.github.com', $protocol . $filePath);
        }
        catch (TransportException $e)
        {
            $resultMessage = false;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $protocol . $filePath."");
            curl_setopt($ch, CURLOPT_PROXY, $opts['http']['proxy']);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FILETIME, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $opts['http']['follow_location']);
            curl_setopt($ch, CURLOPT_MAXREDIRS, $opts['http']['max_redirects']);
            curl_setopt($ch, CURLOPT_USERAGENT,
                sprintf('User-Agent: Composer/%s (%s; %s; PHP %s.%s.%s)',
                    Composer::VERSION === '@package_version@' ? 'source' : Composer::VERSION,
                    php_uname('s'),
                    php_uname('r'),
                    PHP_MAJOR_VERSION,
                    PHP_MINOR_VERSION,
                    PHP_RELEASE_VERSION
                )
            );

            curl_exec($ch);
            $headers = curl_getinfo($ch);
            if ($headers['http_code'] == 200)
            {
                $resultMessage = "Seems there is a problem with your proxy server, try setting value of your environment variable \"http_proxy_request_fulluri\" to false, and run diagnostics again\n";
            }

        }


        restore_error_handler();

        return $resultMessage;
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

    private function checkVersion()
    {
        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        $latest = trim($this->rfs->getContents('getcomposer.org', $protocol . '://getcomposer.org/version', false));

        if (Composer::VERSION !== $latest && Composer::VERSION !== '@package_version@') {
            return '<warning>Your are not running the latest version</warning>';
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

        if (ini_get('apc.enable_cli')) {
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
