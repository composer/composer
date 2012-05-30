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

/**
 * Extractor for pear packages.
 *
 * Composer cannot rely on tar files structure when when place it inside package target dir. Correct source files
 * disposition must be read from package.xml
 * This extract pear package source files to target dir.
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class PearPackageExtractor {
    /**
     * Installs PEAR source files according to package.xml definitions and removes extracted files
     *
     * @param $source string path to extracted package files (it must contain 'package.xml' and '{pear}-{version}' dir
     * @param $target string target install location. all source installation would be performed relative to target path.
     * @param $role string type of files to install. default role for PEAR source files are 'php'.
     */
    public function install($source, $target, $role = 'php') {
        if(!is_file($this->combine($source, '/package.xml')))
            throw new \RuntimeException('Invalid PEAR package. It must contain package.xml file.');

        $fileActions = $this->buildFileActions($source, $role);
        $this->copyFiles($fileActions['copy'], $source, $target);
        $this->unlinkFiles($fileActions['remove'], $source, $target);
    }

    /**
     * Perform copy actions on files
     *
     * @param $files array array('from', 'to') with relative paths
     * @param $source string path to source dir.
     * @param $target string path to destination dir
     */
    private function copyFiles($files, $source, $target) {
        foreach($files as $file) {
            $from = $this->combine($source, $file['from']);
            $to = $this->combine($target, $file['to']);
            $this->copyFile($from, $to);
        }
    }

    /**
     * Perform unlink actions on files
     *
     * @param $files
     * @param $source
     */
    private function unlinkFiles($files, $source) {
        foreach($files as $file) {
            $from = $this->combine($source, $file['from']);
            $this->unlinkFile($from);
        }
    }

    private function copyFile($from, $to) {
        if(!is_file($from))
            throw new \RuntimeException('Invalid PEAR package. package.xml defines file that is not located inside tarball.');


        $this->ensureDirectoryExists(dirname($to));

        copy($from, $to);

        if(!is_file($to))
            print "failed to copy {$from} = > {$to}\n";
    }

    private function unlinkFile($from) {
        if(is_dir($from))
            exec('rm -rf '.escapeshellarg($from));
        elseif(is_file($from))
            unlink($from);
        else
            print "tarball does not have $from\n";
    }

    private function ensureDirectoryExists($dir) {
        if(!is_dir($dir))
            mkdir($dir, 0777, true);
    }

    /**
     * @return array array of 'source' => 'target', where source is location of file in the tarball, and target is destination of file
     */
    private function buildFileActions($source, $role) {
        $result = array(
            'copy' => array(),
            'remove' => array(),
        );
        $result['remove'][] = array('from' => 'package.xml');
        if (file_exists($source . '/package.sig')) {
            $result['remove'][] = array('from' => 'package.sig');
        }

        /** @var $package \SimpleXmlElement */
        $package = simplexml_load_file($this->combine($source, 'package.xml'));
        $packageSchemaVersion = $package['version'];
        if($packageSchemaVersion == '1.0') {
            $children = $package->release->filelist->children();
            $packageName = (string)$package->name;
            $packageVersion = (string)$package->release->version;
            $sourceDir = $packageName . '-' . $packageVersion;
            $result['remove'][] = array('from' => $sourceDir);
            $result['copy'] = $this->buildSourceList10($children, $role, $sourceDir);
        } elseif($packageSchemaVersion == '2.0') {
            $children = $package->contents->children();
            $packageName = (string)$package->name;
            $packageVersion = (string)$package->version->release;
            $sourceDir = $packageName . '-' . $packageVersion;
            $result['remove'][] = array('from' => $sourceDir);
            $result['copy'] = $this->buildSourceList20($children, $role, $sourceDir);
        } else
            throw new \RuntimeException('Unsupported schema version of package.xml.');

        return $result;
    }

    private function buildSourceList10($children, $targetRole, $source = '', $target = '', $role = null) {
        $result = array();

        // enumerating files
        foreach($children as $child) {
            /** @var $child \SimpleXMLElement */
            if($child->getName() == 'dir') {
                $dirSource = $this->combine($source, (string)$child['name']);
                $dirTarget = $child['baseinstalldir'] ?: $target;
                $dirRole = $child['role'] ?: $role;
                $dirFiles = $this->buildSourceList10($child->children(), $targetRole, $dirSource, $dirTarget, $role);
                $result = array_merge($result, $dirFiles);
            } elseif($child->getName() == 'file') {
                if(($child['role'] ?: $role) == $targetRole) {
                    $fileName = (string)($child['name'] ?: $child[0]); // $child[0] means text content
                    $fileSource = $this->combine($source, $fileName);
                    $fileTarget = $this->combine((string)$child['baseinstalldir'] ?: $target, $fileName);
                    $result[] = array('from' => $fileSource, 'to' => $fileTarget);
                }
            }
        }
        return $result;
    }

    private function buildSourceList20($children, $targetRole, $source = '', $target = '', $role = null) {
        $result = array();

        // enumerating files
        foreach($children as $child) {
            /** @var $child \SimpleXMLElement */
            if($child->getName() == 'dir') {
                $dirSource = $this->combine($source, $child['name']);
                $dirTarget = $child['baseinstalldir'] ?: $target;
                $dirRole = $child['role'] ?: $role;
                $dirFiles = $this->buildSourceList20($child->children(), $targetRole, $dirSource, $dirTarget, $dirRole);
                $result = array_merge($result, $dirFiles);
            } elseif($child->getName() == 'file') {
                if(is_null($child['role']) || $child['role'] == $targetRole) {
                    $fileSource = $this->combine($source, (string)$child['name']);
                    $fileTarget = $this->combine((string)($child['baseinstalldir'] ?: $target), (string)$child['name']);
                    $result[] = array('from' => $fileSource, 'to' => $fileTarget);
                }
            }
        }
        return $result;
    }

    private function combine($left, $right) {
        return rtrim($left, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($right, DIRECTORY_SEPARATOR);
    }
}
