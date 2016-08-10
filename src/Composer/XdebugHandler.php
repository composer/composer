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

/**
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class XdebugHandler
{
    const ENV_ALLOW = 'COMPOSER_ALLOW_XDEBUG';

    private $argv;
    private $loaded;
    private $tmpIni;
    private $scanDir;

    /**
     * @param array $argv The global argv passed to script
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;
        $this->loaded = extension_loaded('xdebug');

        $tmp = sys_get_temp_dir();
        $this->tmpIni = $tmp.DIRECTORY_SEPARATOR.'composer-php.ini';
        $this->scanDir = $tmp.DIRECTORY_SEPARATOR.'composer-php-empty';
    }

    /**
     * Checks if xdebug is loaded and composer needs to be restarted
     *
     * If so, then a tmp ini is created with the xdebug ini entry commented out.
     * If additional inis have been loaded, these are combined into the tmp ini
     * and PHP_INI_SCAN_DIR is set to an empty directory. An environment
     * variable is set so that the new process is created only once.
     */
    public function check()
    {
        if (!$this->needsRestart()) {
            return;
        }

        if ($this->prepareRestart($command)) {
            $this->restart($command);
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
     * @return bool
     */
    private function needsRestart()
    {
        if (PHP_SAPI !== 'cli' || !defined('PHP_BINARY')) {
            return false;
        }

        return !getenv(self::ENV_ALLOW) && $this->loaded;
    }

    /**
     * Returns true if everything was written for the restart
     *
     * If any of the following fails (however unlikely) we must return false to
     * stop potential recursion:
     *   - tmp ini file creation
     *   - environment variable creation
     *   - tmp scan dir creation
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
        if ($this->writeTmpIni($iniFiles, $replace)) {
            $command = $this->getCommand($additional);
        }

        return !empty($command) && putenv(self::ENV_ALLOW.'=1');
    }

    /**
     * Writes the temporary ini file, or clears its name if no ini
     *
     * If there are no ini files, the tmp ini name is cleared so that
     * an empty value is passed with the -c option.
     *
     * @param array $iniFiles The php.ini locations
     * @param bool $replace Whether we need to modify the files
     *
     * @return bool False if the tmp ini could not be created
     */
    private function writeTmpIni(array $iniFiles, $replace)
    {
        if (empty($iniFiles)) {
            // Unlikely, maybe xdebug was loaded through the -d option.
            $this->tmpIni = '';
            return true;
        }

        $content = $this->getIniHeader($iniFiles);
        foreach ($iniFiles as $file) {
            $content .= $this->getIniData($file, $replace);
        }

        return @file_put_contents($this->tmpIni, $content);
    }

    /**
     * Return true if additional inis were loaded
     *
     * @param array $iniFiles Populated by method
     * @param bool $replace Whether we need to modify the files
     *
     * @return bool
     */
    private function getAdditionalInis(array &$iniFiles , &$replace)
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
        $data = str_repeat("\n", 3);
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
     * Returns the command line to restart composer
     *
     * @param bool $additional Whether additional inis were loaded
     *
     * @return string The command line
     */
    private function getCommand($additional)
    {
        if ($additional) {
            if (!file_exists($this->scanDir) && !@mkdir($this->scanDir, 0777)) {
                return;
            }
            putenv('PHP_INI_SCAN_DIR='.$this->scanDir);
        }

        $phpArgs = array(PHP_BINARY, '-c', $this->tmpIni);
        $params = array_merge($phpArgs, $this->getScriptArgs($this->argv));

        return implode(' ', array_map(array($this, 'escape'), $params));
    }

    /**
     * Returns the restart script arguments, adding --ansi if required
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

        if ($this->isColorTerminal()) {
            $offset = count($args) > 1 ? 2: 1;
            array_splice($args, $offset, 0, '--ansi');
        }

        return $args;
    }

    /**
     * Returns whether we are a terminal and have colour capabilities
     *
     * @return bool
     */
    private function isColorTerminal()
    {
        if (function_exists('posix_isatty')) {
            $result = posix_isatty(STDOUT);
        } else {
            // See if STDOUT is a character device (S_IFCHR)
            $stat = fstat(STDOUT);
            $result = ($stat['mode'] & 0170000) === 0020000;
        }

        if ($result && defined('PHP_WINDOWS_VERSION_BUILD')) {
            $result = 0 >= version_compare('10.0.10586', PHP_WINDOWS_VERSION_MAJOR.'.'.PHP_WINDOWS_VERSION_MINOR.'.'.PHP_WINDOWS_VERSION_BUILD)
            || false !== getenv('ANSICON')
            || 'ON' === getenv('ConEmuANSI')
            || 'xterm' === getenv('TERM');
        }

        return $result;
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

        $header .= str_repeat("\n", 50);
        return $header;
    }
}
