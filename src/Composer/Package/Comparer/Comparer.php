<?php declare(strict_types=1);

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

use Composer\Util\Platform;

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

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function setUpdate(string $update): void
    {
        $this->update = $update;
    }

    /**
     * @return array{changed?: string[], removed?: string[], added?: string[]}|false false if no change
     */
    public function getChanged(bool $explicated = false)
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

        return $changed;
    }

    /**
     * @return string empty string if no changes
     */
    public function getChangedAsString(bool $toString = false, bool $explicated = false): string
    {
        $changed = $this->getChanged($explicated);
        if (false === $changed) {
            return '';
        }

        $strings = [];
        foreach ($changed as $sectionKey => $itemSection) {
            foreach ($itemSection as $itemKey => $item) {
                $strings[] = $item."\r\n";
            }
        }

        return trim(implode("\r\n", $strings));
    }

    public function doCompare(): void
    {
        $source = [];
        $destination = [];
        $this->changed = [];
        $currentDirectory = Platform::getCwd();
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
     * @param mixed[] $array
     *
     * @return array<string, array<string, string|false>>|false
     */
    private function doTree(string $dir, array &$array)
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
                        $array[$dir][$file] = hash_file(\PHP_VERSION_ID > 80100 ? 'xxh3' : 'sha1', $dir.'/'.$file);
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
