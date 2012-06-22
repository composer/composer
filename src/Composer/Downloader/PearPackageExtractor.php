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

namespace Composer\Downloader;

use Composer\Util\Filesystem;

/**
 * Extractor for pear packages.
 *
 * Composer cannot rely on tar files structure when place it inside package target dir. Correct source files
 * disposition must be read from package.xml
 * This extract pear package source files to target dir.
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class PearPackageExtractor
{
    /** @var Filesystem */
    private $filesystem;
    private $file;

    public function __construct($file)
    {
        if (!is_file($file)) {
            throw new \UnexpectedValueException('PEAR package file is not found at '.$file);
        }

        $this->file = $file;
    }

    /**
     * Installs PEAR source files according to package.xml definitions and removes extracted files
     *
     * @param $file   string path to downloaded PEAR archive file
     * @param $target string target install location. all source installation would be performed relative to target path.
     * @param $role   string type of files to install. default role for PEAR source files are 'php'.
     *
     * @throws \RuntimeException
     */
    public function extractTo($target, $role = 'php')
    {
        $this->filesystem = new Filesystem();

        $extractionPath = $target.'/tarball';

        try {
            $archive = new \PharData($this->file);
            $archive->extractTo($extractionPath, null, true);

            if (!is_file($this->combine($extractionPath, '/package.xml'))) {
                throw new \RuntimeException('Invalid PEAR package. It must contain package.xml file.');
            }

            $fileCopyActions = $this->buildCopyActions($extractionPath, $role);
            $this->copyFiles($fileCopyActions, $extractionPath, $target);
            $this->filesystem->removeDirectory($extractionPath);
        } catch (\Exception $exception) {
            throw new \UnexpectedValueException(sprintf('Failed to extract PEAR package %s to %s. Reason: %s', $this->file, $target, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * Perform copy actions on files
     *
     * @param $files array array('from', 'to') with relative paths
     * @param $source string path to source dir.
     * @param $target string path to destination dir
     */
    private function copyFiles($files, $source, $target)
    {
        foreach ($files as $file) {
            $from = $this->combine($source, $file['from']);
            $to = $this->combine($target, $file['to']);
            $this->copyFile($from, $to);
        }
    }

    private function copyFile($from, $to)
    {
        if (!is_file($from)) {
            throw new \RuntimeException('Invalid PEAR package. package.xml defines file that is not located inside tarball.');
        }

        $this->filesystem->ensureDirectoryExists(dirname($to));

        if (!copy($from, $to)) {
            throw new \RuntimeException(sprintf('Failed to copy %s to %s', $from, $to));
        }
    }

    /**
     * Builds list of copy and list of remove actions that would transform extracted PEAR tarball into installed package.
     *
     * @param $source  string path to extracted files.
     * @param $role    string package file types to extract.
     * @return array array of 'source' => 'target', where source is location of file in the tarball (relative to source
     *  path, and target is destination of file (also relative to $source path)
     * @throws \RuntimeException
     */
    private function buildCopyActions($source, $role)
    {
        /** @var $package \SimpleXmlElement */
        $package = simplexml_load_file($this->combine($source, 'package.xml'));
        if(false === $package)
            throw new \RuntimeException('Package definition file is not valid.');

        $packageSchemaVersion = $package['version'];
        if ('1.0' == $packageSchemaVersion) {
            $children = $package->release->filelist->children();
            $packageName = (string) $package->name;
            $packageVersion = (string) $package->release->version;
            $sourceDir = $packageName . '-' . $packageVersion;
            $result = $this->buildSourceList10($children, $role, $sourceDir);
        } elseif ('2.0' == $packageSchemaVersion || '2.1' == $packageSchemaVersion) {
            $children = $package->contents->children();
            $packageName = (string) $package->name;
            $packageVersion = (string) $package->version->release;
            $sourceDir = $packageName . '-' . $packageVersion;
            $result = $this->buildSourceList20($children, $role, $sourceDir);
        } else {
            throw new \RuntimeException('Unsupported schema version of package definition file.');
        }

        return $result;
    }

    private function buildSourceList10($children, $targetRole, $source = '', $target = '', $role = null)
    {
        $result = array();

        // enumerating files
        foreach ($children as $child) {
            /** @var $child \SimpleXMLElement */
            if ($child->getName() == 'dir') {
                $dirSource = $this->combine($source, (string) $child['name']);
                $dirTarget = $child['baseinstalldir'] ? : $target;
                $dirRole = $child['role'] ? : $role;
                $dirFiles = $this->buildSourceList10($child->children(), $targetRole, $dirSource, $dirTarget, $dirRole);
                $result = array_merge($result, $dirFiles);
            } elseif ($child->getName() == 'file') {
                if (($child['role'] ? : $role) == $targetRole) {
                    $fileName = (string) ($child['name'] ? : $child[0]); // $child[0] means text content
                    $fileSource = $this->combine($source, $fileName);
                    $fileTarget = $this->combine((string) $child['baseinstalldir'] ? : $target, $fileName);
                    $result[] = array('from' => $fileSource, 'to' => $fileTarget);
                }
            }
        }

        return $result;
    }

    private function buildSourceList20($children, $targetRole, $source = '', $target = '', $role = null)
    {
        $result = array();

        // enumerating files
        foreach ($children as $child) {
            /** @var $child \SimpleXMLElement */
            if ($child->getName() == 'dir') {
                $dirSource = $this->combine($source, $child['name']);
                $dirTarget = $child['baseinstalldir'] ? : $target;
                $dirRole = $child['role'] ? : $role;
                $dirFiles = $this->buildSourceList20($child->children(), $targetRole, $dirSource, $dirTarget, $dirRole);
                $result = array_merge($result, $dirFiles);
            } elseif ($child->getName() == 'file') {
                if (($child['role'] ? : $role) == $targetRole) {
                    $fileSource = $this->combine($source, (string) $child['name']);
                    $fileTarget = $this->combine((string) ($child['baseinstalldir'] ? : $target), (string) $child['name']);
                    $result[] = array('from' => $fileSource, 'to' => $fileTarget);
                }
            }
        }

        return $result;
    }

    private function combine($left, $right)
    {
        return rtrim($left, '/') . '/' . ltrim($right, '/');
    }
}
