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

namespace Composer\Package\Comparer;

/**
 * class Comparer
 *
 * @author Hector Prats <hectorpratsortega@gmail.com>
 */
class Comparer
{
    /** @var string Source directory */
    private $source;
    /** @var string Target directory */
    private $update;
    /** @var array{changed?: string[], removed?: string[], added?: string[]} */
    private $changed;

    /**
     * @param string $source
     *
     * @return void
     */
    public function setSource($source)
    {
        $this->source = $source;
    }

    /**
     * @param string $update
     *
     * @return void
     */
    public function setUpdate($update)
    {
        $this->update = $update;
    }

    /**
     * @param bool $toString
     * @param bool $explicated
     *
     * @return array{changed?: string[], removed?: string[], added?: string[]}|string|false false if no change, string only if $toString is true
     */
    public function getChanged($toString = false, $explicated = false)
    {
        $changed = $this->changed;
        if (!count($changed)) {
            return false;
        }
        if ($explicated) {
            foreach ($changed as $sectionKey => $itemSection) {
                foreach ($itemSection as $itemKey => $item) {
                    $changed[$sectionKey][$itemKey] = $item.' ('.$sectionKey.')';
                }
            }
        }

        if ($toString) {
            foreach ($changed as $sectionKey => $itemSection) {
                foreach ($itemSection as $itemKey => $item) {
                    $changed['string'][] = $item."\r\n";
                }
            }
            $changed = implode("\r\n", $changed['string']);
        }

        return $changed;
    }

    /**
     * @return void
     */
    public function doCompare()
    {
        $source = array();
        $destination = array();
        $this->changed = array();
        $currentDirectory = getcwd();
        chdir($this->source);
        $source = $this->doTree('.', $source);
        if (!is_array($source)) {
            return;
        }
        chdir($currentDirectory);
        chdir($this->update);
        $destination = $this->doTree('.', $destination);
        if (!is_array($destination)) {
            exit;
        }
        chdir($currentDirectory);
        foreach ($source as $dir => $value) {
            foreach ($value as $file => $hash) {
                if (isset($destination[$dir][$file])) {
                    if ($hash !== $destination[$dir][$file]) {
                        $this->changed['changed'][] = $dir.'/'.$file;
                    }
                } else {
                    $this->changed['removed'][] = $dir.'/'.$file;
                }
            }
        }
        foreach ($destination as $dir => $value) {
            foreach ($value as $file => $hash) {
                if (!isset($source[$dir][$file])) {
                    $this->changed['added'][] = $dir.'/'.$file;
                }
            }
        }
    }

    /**
     * @param string $dir
     * @param mixed $array
     *
     * @return array<string, array<string, string|false>>|false
     */
    private function doTree($dir, &$array)
    {
        if ($dh = opendir($dir)) {
            while ($file = readdir($dh)) {
                if ($file !== '.' && $file !== '..') {
                    if (is_link($dir.'/'.$file)) {
                        $array[$dir][$file] = readlink($dir.'/'.$file);
                    } elseif (is_dir($dir.'/'.$file)) {
                        if (!count($array)) {
                            $array[0] = 'Temp';
                        }
                        if (!$this->doTree($dir.'/'.$file, $array)) {
                            return false;
                        }
                    } elseif (is_file($dir.'/'.$file) && filesize($dir.'/'.$file)) {
                        $array[$dir][$file] = md5_file($dir.'/'.$file);
                    }
                }
            }
            if (count($array) > 1 && isset($array['0'])) {
                unset($array['0']);
            }

            return $array;
        }

        return false;
    }
}
