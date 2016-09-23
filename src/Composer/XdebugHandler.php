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

namespace Composer;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class XdebugHandler
{
    const ENV_ALLOW = 'COMPOSER_ALLOW_XDEBUG';
    const RESTART_ID = 'internal';

    private $output;
    private $loaded;
    private $envScanDir;

    /**
     * Constructor
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->loaded = extension_loaded('xdebug');
        $this->envScanDir = getenv('PHP_INI_SCAN_DIR');
    }

    /**
     * Checks if xdebug is loaded and composer needs to be restarted
     *
     * If so, then a tmp ini is created with the xdebug ini entry commented out.
     * If additional inis have been loaded, these are combined into the tmp ini
     * and PHP_INI_SCAN_DIR is set to an empty value.
     *
     * This behaviour can be disabled by setting the COMPOSER_ALLOW_XDEBUG
     * environment variable to 1. This variable is used internally so that the
     * restarted process is created only once and PHP_INI_SCAN_DIR can be
     * restored to its original value.
     */
    public function check()
    {
        $args = explode('|', strval(getenv(self::ENV_ALLOW)), 2);

        if ($this->needsRestart($args[0])) {
            $this->prepareRestart($command) && $this->restart($command);
            return;
        }

        // Restore environment variables if we are restarting
        if (self::RESTART_ID === $args[0]) {
            putenv(self::ENV_ALLOW);

            if (false !== $this->envScanDir) {
                // $args[1] contains the original value
                if (isset($args[1])) {
                    putenv('PHP_INI_SCAN_DIR='.$args[1]);
                } else {
                    putenv('PHP_INI_SCAN_DIR');
                }
            }
        }
    }

    /**
     * Executes the restarted command
     *
     * @param string $command
     */
    protected function restart($command)
    {
        passthru($command, $exitCode);
        exit($exitCode);
    }

    /**
     * Returns true if a restart is needed
     *
     * @param string $allow Environment value
     *
     * @return bool
     */
    private function needsRestart($allow)
    {
        if (PHP_SAPI !== 'cli' || !defined('PHP_BINARY')) {
            return false;
        }

        return empty($allow) && $this->loaded;
    }

    /**
     * Returns true if everything was written for the restart
     *
     * If any of the following fails (however unlikely) we must return false to
     * stop potential recursion:
     *   - tmp ini file creation
     *   - environment variable creation
     *
     * @param null|string $command The command to run, set by method
     *
     * @return bool
     */
    private function prepareRestart(&$command)
    {
        $iniFiles = array();
        if ($loadedIni = php_ini_loaded_file()) {
            $iniFiles[] = $loadedIni;
        }

        $additional = $this->getAdditionalInis($iniFiles, $replace);
        $tmpIni = $this->writeTmpIni($iniFiles, $replace);

        if (false !== $tmpIni) {
            $command = $this->getCommand($tmpIni);
            return $this->setEnvironment($additional);
        }

        return false;
    }

    /**
     * Writes the tmp ini file and returns its filename
     *
     * The filename is passed as the -c option when the process restarts. On
     * non-Windows platforms the filename is prefixed with the username to
     * avoid any multi-user conflict. Windows always uses the user temp dir.
     *
     * @param array $iniFiles The php.ini locations
     * @param bool $replace Whether we need to modify the files
     *
     * @return bool|string False if the tmp ini could not be created
     */
    private function writeTmpIni(array $iniFiles, $replace)
    {
        if (empty($iniFiles)) {
            // Unlikely, maybe xdebug was loaded through a command line option.
            return '';
        }

        if (function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_getuid());
        }
        $prefix = !empty($user) ? $user['name'].'-' : '';
        $tmpIni = sys_get_temp_dir().'/'.$prefix.'composer-php.ini';

        $content = $this->getIniHeader($iniFiles);
        foreach ($iniFiles as $file) {
            $content .= $this->getIniData($file, $replace);
        }

        return @file_put_contents($tmpIni, $content) ? $tmpIni : false;
    }

    /**
     * Returns true if additional inis were loaded
     *
     * @param array $iniFiles Populated by method
     * @param bool $replace Whether we need to modify the files
     *
     * @return bool
     */
    private function getAdditionalInis(array &$iniFiles, &$replace)
    {
        $replace = true;

        if ($scanned = php_ini_scanned_files()) {
            $list = explode(',', $scanned);

            foreach ($list as $file) {
                $file = trim($file);
                if (preg_match('/xdebug.ini$/', $file)) {
                    // Skip the file, no need for regex replacing
                    $replace = false;
                } else {
                    $iniFiles[] = $file;
                }
            }
        }

        return !empty($scanned);
    }

    /**
     * Returns formatted ini file data
     *
     * @param string $iniFile The location of the ini file
     * @param bool $replace Whether to regex replace content
     *
     * @return string The ini data
     */
    private function getIniData($iniFile, $replace)
    {
        $data = str_repeat(PHP_EOL, 3);
        $data .= sprintf('; %s%s', $iniFile, PHP_EOL);
        $contents = file_get_contents($iniFile);

        if ($replace) {
            // Comment out xdebug config
            $regex = '/^\s*(zend_extension\s*=.*xdebug.*)$/mi';
            $data .= preg_replace($regex, ';$1', $contents);
        } else {
            $data .= $contents;
        }

        return $data;
    }

    /**
     * Returns the restart command line
     *
     * @param string $tmpIni The temporary ini file location
     *
     * @return string
     */
    private function getCommand($tmpIni)
    {
        $phpArgs = array(PHP_BINARY, '-c', $tmpIni);
        $params = array_merge($phpArgs, $this->getScriptArgs($_SERVER['argv']));

        return implode(' ', array_map(array($this, 'escape'), $params));
    }

    /**
     * Returns true if the restart environment variables were set
     *
     * @param bool $additional Whether additional inis were loaded
     *
     * @return bool
     */
    private function setEnvironment($additional)
    {
        $args = array(self::RESTART_ID);

        if (false !== $this->envScanDir) {
            // Save current PHP_INI_SCAN_DIR
            $args[] = $this->envScanDir;
        }

        if ($additional && !putenv('PHP_INI_SCAN_DIR=')) {
            return false;
        }

        return putenv(self::ENV_ALLOW.'='.implode('|', $args));
    }

    /**
     * Returns the restart script arguments, adding --ansi if required
     *
     * If we are a terminal with color support we must ensure that the --ansi
     * option is set, because the restarted output is piped.
     *
     * @param array $args The argv array
     *
     * @return array
     */
    private function getScriptArgs(array $args)
    {
        if (in_array('--no-ansi', $args) || in_array('--ansi', $args)) {
            return $args;
        }

        if ($this->output->isDecorated()) {
            $offset = count($args) > 1 ? 2: 1;
            array_splice($args, $offset, 0, '--ansi');
        }

        return $args;
    }

    /**
     * Escapes a string to be used as a shell argument.
     *
     * From https://github.com/johnstevenson/winbox-args
     * MIT Licensed (c) John Stevenson <john-stevenson@blueyonder.co.uk>
     *
     * @param string $arg The argument to be escaped
     * @param bool $meta Additionally escape cmd.exe meta characters
     *
     * @return string The escaped argument
     */
    private function escape($arg, $meta = true)
    {
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            return escapeshellarg($arg);
        }

        $quote = strpbrk($arg, " \t") !== false || $arg === '';
        $arg = preg_replace('/(\\\\*)"/', '$1$1\\"', $arg, -1, $dquotes);

        if ($meta) {
            $meta = $dquotes || preg_match('/%[^%]+%/', $arg);

            if (!$meta && !$quote) {
                $quote = strpbrk($arg, '^&|<>()') !== false;
            }
        }

        if ($quote) {
            $arg = preg_replace('/(\\\\*)$/', '$1$1', $arg);
            $arg = '"'.$arg.'"';
        }

        if ($meta) {
            $arg = preg_replace('/(["^&|<>()%])/', '^$1', $arg);
        }

        return $arg;
    }

    /**
     * Returns the location of the original ini data used.
     *
     * @param array $iniFiles loaded php.ini locations
     *
     * @return string
     */
    private function getIniHeader($iniFiles)
    {
        $ini = implode(PHP_EOL.';  ', $iniFiles);
        $header = <<<EOD
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; This file was automatically generated by Composer and can now be deleted.
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

; It is a modified copy of your php.ini configuration, found at:
;  {$ini}

; Make any changes there because this data will not be used again.
EOD;

        $header .= str_repeat(PHP_EOL, 50);
        return $header;
    }
}
