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

namespace Composer\Util;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Finder\Finder;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Filesystem
{
    private $processExecutor;

    public function __construct(ProcessExecutor $executor = null)
    {
        $this->processExecutor = $executor ?: new ProcessExecutor();
    }

    public function remove($file)
    {
        if (is_dir($file)) {
            return $this->removeDirectory($file);
        }

        if (file_exists($file)) {
            return $this->unlink($file);
        }

        return false;
    }

    /**
     * Checks if a directory is empty
     *
     * @param  string $dir
     * @return bool
     */
    public function isDirEmpty($dir)
    {
        $finder = Finder::create()
            ->ignoreVCS(false)
            ->ignoreDotFiles(false)
            ->depth(0)
            ->in($dir);

        return count($finder) === 0;
    }

    public function emptyDirectory($dir, $ensureDirectoryExists = true)
    {
        if (file_exists($dir) && is_link($dir)) {
            $this->unlink($dir);
        }

        if ($ensureDirectoryExists) {
            $this->ensureDirectoryExists($dir);
        }

        if (is_dir($dir)) {
            $finder = Finder::create()
                ->ignoreVCS(false)
                ->ignoreDotFiles(false)
                ->depth(0)
                ->in($dir);

            foreach ($finder as $path) {
                $this->remove((string) $path);
            }
        }
    }

    /**
     * Recursively remove a directory
     *
     * Uses the process component if proc_open is enabled on the PHP
     * installation.
     *
     * @param  string $directory
     * @return bool
     *
     * @throws \RuntimeException
     */
    public function removeDirectory($directory)
    {
        if ($this->isSymlinkedDirectory($directory)) {
            return $this->unlinkSymlinkedDirectory($directory);
        }

        if (!file_exists($directory) || !is_dir($directory)) {
            return true;
        }

        if (preg_match('{^(?:[a-z]:)?[/\\\\]+$}i', $directory)) {
            throw new \RuntimeException('Aborting an attempted deletion of '.$directory.', this was probably not intended, if it is a real use case please report it.');
        }

        if (!function_exists('proc_open')) {
            return $this->removeDirectoryPhp($directory);
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $cmd = sprintf('rmdir /S /Q %s', ProcessExecutor::escape(realpath($directory)));
        } else {
            $cmd = sprintf('rm -rf %s', ProcessExecutor::escape($directory));
        }

        $result = $this->getProcess()->execute($cmd, $output) === 0;

        // clear stat cache because external processes aren't tracked by the php stat cache
        clearstatcache();

        if ($result && !file_exists($directory)) {
            return true;
        }

        return $this->removeDirectoryPhp($directory);
    }

    /**
     * Recursively delete directory using PHP iterators.
     *
     * Uses a CHILD_FIRST RecursiveIteratorIterator to sort files
     * before directories, creating a single non-recursive loop
     * to delete files/directories in the correct order.
     *
     * @param  string $directory
     * @return bool
     */
    public function removeDirectoryPhp($directory)
    {
        $it = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($ri as $file) {
            if ($file->isDir()) {
                $this->rmdir($file->getPathname());
            } else {
                $this->unlink($file->getPathname());
            }
        }

        return $this->rmdir($directory);
    }

    public function ensureDirectoryExists($directory)
    {
        if (!is_dir($directory)) {
            if (file_exists($directory)) {
                throw new \RuntimeException(
                    $directory.' exists and is not a directory.'
                );
            }
            if (!@mkdir($directory, 0777, true)) {
                throw new \RuntimeException(
                    $directory.' does not exist and could not be created.'
                );
            }
        }
    }

    /**
     * Attempts to unlink a file and in case of failure retries after 350ms on windows
     *
     * @param  string $path
     * @return bool
     *
     * @throws \RuntimeException
     */
    public function unlink($path)
    {
        if (!@$this->unlinkImplementation($path)) {
            // retry after a bit on windows since it tends to be touchy with mass removals
            if (!defined('PHP_WINDOWS_VERSION_BUILD') || (usleep(350000) && !@$this->unlinkImplementation($path))) {
                $error = error_get_last();
                $message = 'Could not delete '.$path.': ' . @$error['message'];
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $message .= "\nThis can be due to an antivirus or the Windows Search Indexer locking the file while they are analyzed";
                }

                throw new \RuntimeException($message);
            }
        }

        return true;
    }

    /**
     * Attempts to rmdir a file and in case of failure retries after 350ms on windows
     *
     * @param  string $path
     * @return bool
     *
     * @throws \RuntimeException
     */
    public function rmdir($path)
    {
        if (!@rmdir($path)) {
            // retry after a bit on windows since it tends to be touchy with mass removals
            if (!defined('PHP_WINDOWS_VERSION_BUILD') || (usleep(350000) && !@rmdir($path))) {
                $error = error_get_last();
                $message = 'Could not delete '.$path.': ' . @$error['message'];
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $message .= "\nThis can be due to an antivirus or the Windows Search Indexer locking the file while they are analyzed";
                }

                throw new \RuntimeException($message);
            }
        }

        return true;
    }

    /**
     * Copy then delete is a non-atomic version of {@link rename}.
     *
     * Some systems can't rename and also don't have proc_open,
     * which requires this solution.
     *
     * @param string $source
     * @param string $target
     */
    public function copyThenRemove($source, $target)
    {
        if (!is_dir($source)) {
            copy($source, $target);
            $this->unlink($source);

            return;
        }

        $it = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
        $this->ensureDirectoryExists($target);

        foreach ($ri as $file) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $ri->getSubPathName();
            if ($file->isDir()) {
                $this->ensureDirectoryExists($targetPath);
            } else {
                copy($file->getPathname(), $targetPath);
            }
        }

        $this->removeDirectoryPhp($source);
    }

    public function rename($source, $target)
    {
        if (true === @rename($source, $target)) {
            return;
        }

        if (!function_exists('proc_open')) {
            return $this->copyThenRemove($source, $target);
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            // Try to copy & delete - this is a workaround for random "Access denied" errors.
            $command = sprintf('xcopy %s %s /E /I /Q', ProcessExecutor::escape($source), ProcessExecutor::escape($target));
            $result = $this->processExecutor->execute($command, $output);

            // clear stat cache because external processes aren't tracked by the php stat cache
            clearstatcache();

            if (0 === $result) {
                $this->remove($source);

                return;
            }
        } else {
            // We do not use PHP's "rename" function here since it does not support
            // the case where $source, and $target are located on different partitions.
            $command = sprintf('mv %s %s', ProcessExecutor::escape($source), ProcessExecutor::escape($target));
            $result = $this->processExecutor->execute($command, $output);

            // clear stat cache because external processes aren't tracked by the php stat cache
            clearstatcache();

            if (0 === $result) {
                return;
            }
        }

        return $this->copyThenRemove($source, $target);
    }

    /**
     * Returns the shortest path from $from to $to
     *
     * @param  string                    $from
     * @param  string                    $to
     * @param  bool                      $directories if true, the source/target are considered to be directories
     * @throws \InvalidArgumentException
     * @return string
     */
    public function findShortestPath($from, $to, $directories = false)
    {
        if (!$this->isAbsolutePath($from) || !$this->isAbsolutePath($to)) {
            throw new \InvalidArgumentException(sprintf('$from (%s) and $to (%s) must be absolute paths.', $from, $to));
        }

        $from = lcfirst($this->normalizePath($from));
        $to = lcfirst($this->normalizePath($to));

        if ($directories) {
            $from .= '/dummy_file';
        }

        if (dirname($from) === dirname($to)) {
            return './'.basename($to);
        }

        $commonPath = $to;
        while (strpos($from.'/', $commonPath.'/') !== 0 && '/' !== $commonPath && !preg_match('{^[a-z]:/?$}i', $commonPath)) {
            $commonPath = strtr(dirname($commonPath), '\\', '/');
        }

        if (0 !== strpos($from, $commonPath) || '/' === $commonPath) {
            return $to;
        }

        $commonPath = rtrim($commonPath, '/') . '/';
        $sourcePathDepth = substr_count(substr($from, strlen($commonPath)), '/');
        $commonPathCode = str_repeat('../', $sourcePathDepth);

        return ($commonPathCode . substr($to, strlen($commonPath))) ?: './';
    }

    /**
     * Returns PHP code that, when executed in $from, will return the path to $to
     *
     * @param  string                    $from
     * @param  string                    $to
     * @param  bool                      $directories if true, the source/target are considered to be directories
     * @throws \InvalidArgumentException
     * @return string
     */
    public function findShortestPathCode($from, $to, $directories = false)
    {
        if (!$this->isAbsolutePath($from) || !$this->isAbsolutePath($to)) {
            throw new \InvalidArgumentException(sprintf('$from (%s) and $to (%s) must be absolute paths.', $from, $to));
        }

        $from = lcfirst($this->normalizePath($from));
        $to = lcfirst($this->normalizePath($to));

        if ($from === $to) {
            return $directories ? '__DIR__' : '__FILE__';
        }

        $commonPath = $to;
        while (strpos($from.'/', $commonPath.'/') !== 0 && '/' !== $commonPath && !preg_match('{^[a-z]:/?$}i', $commonPath) && '.' !== $commonPath) {
            $commonPath = strtr(dirname($commonPath), '\\', '/');
        }

        if (0 !== strpos($from, $commonPath) || '/' === $commonPath || '.' === $commonPath) {
            return var_export($to, true);
        }

        $commonPath = rtrim($commonPath, '/') . '/';
        if (strpos($to, $from.'/') === 0) {
            return '__DIR__ . '.var_export(substr($to, strlen($from)), true);
        }
        $sourcePathDepth = substr_count(substr($from, strlen($commonPath)), '/') + $directories;
        $commonPathCode = str_repeat('dirname(', $sourcePathDepth).'__DIR__'.str_repeat(')', $sourcePathDepth);
        $relTarget = substr($to, strlen($commonPath));

        return $commonPathCode . (strlen($relTarget) ? '.' . var_export('/' . $relTarget, true) : '');
    }

    /**
     * Checks if the given path is absolute
     *
     * @param  string $path
     * @return bool
     */
    public function isAbsolutePath($path)
    {
        return substr($path, 0, 1) === '/' || substr($path, 1, 1) === ':';
    }

    /**
     * Returns size of a file or directory specified by path. If a directory is
     * given, it's size will be computed recursively.
     *
     * @param  string            $path Path to the file or directory
     * @throws \RuntimeException
     * @return int
     */
    public function size($path)
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("$path does not exist.");
        }
        if (is_dir($path)) {
            return $this->directorySize($path);
        }

        return filesize($path);
    }

    /**
     * Normalize a path. This replaces backslashes with slashes, removes ending
     * slash and collapses redundant separators and up-level references.
     *
     * @param  string $path Path to the file or directory
     * @return string
     */
    public function normalizePath($path)
    {
        $parts = array();
        $path = strtr($path, '\\', '/');
        $prefix = '';
        $absolute = false;

        if (preg_match('{^([0-9a-z]+:(?://(?:[a-z]:)?)?)}i', $path, $match)) {
            $prefix = $match[1];
            $path = substr($path, strlen($prefix));
        }

        if (substr($path, 0, 1) === '/') {
            $absolute = true;
            $path = substr($path, 1);
        }

        $up = false;
        foreach (explode('/', $path) as $chunk) {
            if ('..' === $chunk && ($absolute || $up)) {
                array_pop($parts);
                $up = !(empty($parts) || '..' === end($parts));
            } elseif ('.' !== $chunk && '' !== $chunk) {
                $parts[] = $chunk;
                $up = '..' !== $chunk;
            }
        }

        return $prefix.($absolute ? '/' : '').implode('/', $parts);
    }

    /**
     * Return if the given path is local
     *
     * @param  string $path
     * @return bool
     */
    public static function isLocalPath($path)
    {
        return (bool) preg_match('{^(file://|/|[a-z]:[\\\\/]|\.\.[\\\\/]|[a-z0-9_.-]+[\\\\/])}i', $path);
    }

    public static function getPlatformPath($path)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $path = preg_replace('{^(?:file:///([a-z])/)}i', 'file://$1:/', $path);
        }

        return preg_replace('{^file://}i', '', $path);
    }

    protected function directorySize($directory)
    {
        $it = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        $size = 0;
        foreach ($ri as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    protected function getProcess()
    {
        return new ProcessExecutor;
    }

    /**
     * delete symbolic link implementation (commonly known as "unlink()")
     *
     * symbolic links on windows which link to directories need rmdir instead of unlink
     *
     * @param string $path
     *
     * @return bool
     */
    private function unlinkImplementation($path)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD') && is_dir($path) && is_link($path)) {
            return rmdir($path);
        }

        return unlink($path);
    }

    private function isSymlinkedDirectory($directory)
    {
        if (!is_dir($directory)) {
            return false;
        }

        $resolved = $this->resolveSymlinkedDirectorySymlink($directory);

        return is_link($resolved);
    }

    /**
     * @param string $directory
     *
     * @return bool
     */
    private function unlinkSymlinkedDirectory($directory)
    {
        $resolved = $this->resolveSymlinkedDirectorySymlink($directory);

        return $this->unlink($resolved);
    }

    /**
     * resolve pathname to symbolic link of a directory
     *
     * @param string $pathname directory path to resolve
     *
     * @return string resolved path to symbolic link or original pathname (unresolved)
     */
    private function resolveSymlinkedDirectorySymlink($pathname)
    {
        if (!is_dir($pathname)) {
            return $pathname;
        }

        $resolved = rtrim($pathname, '/');

        if (!strlen($resolved)) {
            return $pathname;
        }

        return $resolved;
    }
}
