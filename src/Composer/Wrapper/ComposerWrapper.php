#!/usr/bin/env php
<?php

/**
 * This is a wrapper around composer that installs or updates it
 * and delegates call to it as if composer itself was called.
 *
 * If it breaks, check out newer version or report an issue at
 * https://github.com/kamazee/composer-wrapper
 *
 * @version 1.0.0
 */
if (!class_exists('ComposerWrapper')) {
    class ComposerWrapper
    {
        const COMPOSER_HASHBANG = "#!/usr/bin/env php\n";
        const EXPECTED_INSTALLER_CHECKSUM_URL = 'https://composer.github.io/installer.sig';
        const INSTALLER_URL = 'https://getcomposer.org/installer';
        const INSTALLER_FILE = 'composer-setup.php';
        const EXIT_CODE_SUCCESS = 0;
        const EXIT_CODE_ERROR_DOWNLOADING_CHECKSUM = 1;
        const MSG_ERROR_DOWNLOADING_CHECKSUM = 'Error when downloading composer installer checksum';
        const EXIT_CODE_ERROR_DOWNLOADING_INSTALLER = 2;
        const MSG_ERROR_DOWNLOADING_INSTALLER = 'Error when downloading composer installer';
        const EXIT_CODE_ERROR_INSTALLER_CHECKSUM_MISMATCH = 3;
        const MSG_ERROR_INSTALLER_CHECKSUM_MISMATCH = 'Failed to install composer: checksum of composer installer does not match expected checksum';
        const EXIT_CODE_ERROR_WHEN_INSTALLING = 4;
        const MSG_ERROR_WHEN_INSTALLING = 'Error when running composer installer';
        const EXIT_CODE_ERROR_MAKING_EXECUTABLE = 5;
        const MSG_SELF_UPDATE_FAILED = 'composer self-update failed; proceeding with existing';
        const EXIT_CODE_COMPOSER_EXCEPTION = 6;

        protected function file_get_contents()
        {
            return \call_user_func_array('file_get_contents', func_get_args());
        }

        protected function copy()
        {
            return \call_user_func_array('copy', func_get_args());
        }

        protected function passthru($command, &$exitCode)
        {
            passthru($command, $exitCode);
        }

        protected function unlink()
        {
            return call_user_func_array('unlink', func_get_args());
        }

        public function getPhpBinary()
        {
            if (defined('PHP_BINARY')) {
                return PHP_BINARY;
            }

            return PHP_BINDIR . '/php';
        }

        public function installComposer($dir)
        {
            $installerPathName = $dir . DIRECTORY_SEPARATOR . static::INSTALLER_FILE;
            if (!$this->copy(static::INSTALLER_URL, $installerPathName)) {
                throw new Exception(
                    self::MSG_ERROR_DOWNLOADING_INSTALLER, self::EXIT_CODE_ERROR_DOWNLOADING_INSTALLER
                );
            }

            $this->verifyChecksum($installerPathName);

            \passthru(
                \sprintf(
                    '%s %s --install-dir=%s',
                    \escapeshellarg($this->getPhpBinary()),
                    \escapeshellarg($installerPathName),
                    \escapeshellarg($dir)
                ),
                $exitCode
            );

            $this->unlink($installerPathName);

            if (self::EXIT_CODE_SUCCESS !== $exitCode) {
                throw new Exception(
                    self::MSG_ERROR_WHEN_INSTALLING, self::EXIT_CODE_ERROR_WHEN_INSTALLING
                );
            }

            unset($exitCode);
        }

        protected function verifyChecksum($installerPathName)
        {
            $expectedInstallerHash = $this->file_get_contents(static::EXPECTED_INSTALLER_CHECKSUM_URL);
            if (empty($expectedInstallerHash)) {
                throw new Exception(
                    self::MSG_ERROR_DOWNLOADING_CHECKSUM, self::EXIT_CODE_ERROR_DOWNLOADING_CHECKSUM
                );
            }

            $expectedInstallerHash = trim($expectedInstallerHash);

            $actualInstallerHash = \hash_file('sha384', $installerPathName);
            if ($expectedInstallerHash !== $actualInstallerHash) {
                $this->unlink($installerPathName);

                throw new Exception(
                    self::MSG_ERROR_INSTALLER_CHECKSUM_MISMATCH, self::EXIT_CODE_ERROR_INSTALLER_CHECKSUM_MISMATCH
                );
            }
        }

        protected function ensureInstalled($filename)
        {
            if (\file_exists($filename)) {
                return;
            }

            $this->installComposer(dirname($filename));
        }

        protected function ensureExecutable($filename)
        {
            if (!\file_exists($filename)) {
                throw new Exception("Can't make $filename executable: it doesn't exist");
            }

            if (\is_executable($filename)) {
                return;
            }

            $currentMode = \fileperms($filename);
            $executablePermissions = $currentMode | 0111;
            if (false === \chmod($filename, $executablePermissions)) {
                throw new Exception(
                    "Can't make $filename executable", self::EXIT_CODE_ERROR_MAKING_EXECUTABLE
                );
            }
        }

        protected function ensureUpToDate($filename)
        {
            if (!\file_exists($filename)) {
                throw new \Exception("Can't run composer self-update for $filename: it doesn't exist");
            }

            $composerUpdateFrequency = '7 days';
            $composerUpdateFrequencyEnv = \getenv(
                'COMPOSER_UPDATE_FREQ'
            );
            if (\strlen($composerUpdateFrequencyEnv) > 1) {
                $composerUpdateFrequency = $composerUpdateFrequencyEnv;
            }

            $now = new \DateTime('now', new DateTimeZone('UTC'));
            $nowClone = clone $now;
            $nowPlusFrequency = $nowClone->modify($composerUpdateFrequency);

            if ($nowPlusFrequency < $now) {
                $this->showError('Composer update frequency must not be negative');
            }

            $mtimeTimestamp = \filemtime($filename);
            $mtimePlusFrequency = \DateTime::createFromFormat(
                'U',
                $mtimeTimestamp,
                new \DateTimeZone('UTC')
            )->modify($composerUpdateFrequency);

            if ($mtimePlusFrequency <= $now) {
                $this->passthru(
                    \sprintf('%s self-update', \escapeshellarg($filename)),
                    $exitCode
                );

                // composer exits both when self-update downloaded a new version
                // and when no new version was available (and everything went OK)
                if (self::EXIT_CODE_SUCCESS === $exitCode) {
                    \touch($filename);
                } else {
                    // if self-update failed, next call should try it again, hence no touch()
                    $this->showError(self::MSG_SELF_UPDATE_FAILED);
                }
            }
        }

        protected function delegate($filename)
        {
            \ob_start(
                function ($buffer) {
                    if (0 === \strpos($buffer, ComposerWrapper::COMPOSER_HASHBANG)) {
                        return \substr($buffer, \strlen(ComposerWrapper::COMPOSER_HASHBANG));
                    }

                    return false;
                }
            );

            try {
                require $filename;
            } catch (Exception $e) {
                \ob_end_flush();
                throw new Exception(
                    "Composer exception was thrown: {$e->getMessage()}", self::EXIT_CODE_COMPOSER_EXCEPTION, $e
                );
            }

            \ob_end_flush();
        }

        public function showError($text)
        {
            \fwrite(STDERR, $text . "\n");
        }

        /**
         * @return string
         * @throws Exception
         */
        private function getComposerDir()
        {
            $defaultComposerDir = __DIR__;
            $composerDirEnv = \getenv('COMPOSER_DIR');

            if (\strlen($composerDirEnv) > 0) {
                if (!is_dir($composerDirEnv)) {
                    throw new \Exception("$composerDirEnv is not a dir");
                }

                return $composerDirEnv;
            }

            return $defaultComposerDir;
        }

        /**
         * @throws Exception
         */
        public function run()
        {
            $composerPathName = "{$this->getComposerDir()}/composer.phar";

            $this->ensureInstalled($composerPathName);
            $this->ensureExecutable($composerPathName);
            $this->ensureUpToDate($composerPathName);
            $this->delegate($composerPathName);
        }
    }
}

if ('cli' === \PHP_SAPI && @\realpath($_SERVER['argv'][0]) === __FILE__) {
    $runner = new ComposerWrapper();

    try {
        $runner->run();
    } catch (Exception $e) {
        $runner->showError('ERROR: ' . $e->getMessage());
        exit($e->getCode());
    }
}