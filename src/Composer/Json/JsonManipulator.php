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

namespace Composer\Json;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonManipulator
{
    private $contents;
    private $newline;
    private $indent;

    public function __construct($contents)
    {
        if (!preg_match('#^\{(.*)\}$#s', trim($contents), $match)) {
            throw new \InvalidArgumentException('The json file must be an object ({})');
        }
        $this->newline = false !== strpos("\r\n", $contents) ? "\r\n": "\n";
        $this->contents = '{' . $match[1] . '}';
        $this->detectIndenting();
    }

    public function getContents()
    {
        return $this->contents . $this->newline;
    }

    public function addLink($type, $package, $constraint)
    {
        // no link of that type yet
        if (!preg_match('#"'.$type.'":\s*\{#', $this->contents)) {
            $this->addMainKey($type, $this->format(array($package => $constraint)));

            return true;
        }

        $linksRegex = '#("'.$type.'":\s*\{)([^}]+)(\})#s';
        if (!preg_match($linksRegex, $this->contents, $match)) {
            return false;
        }

        $links = $match[2];
        $packageRegex = str_replace('/', '\\\\?/', preg_quote($package));

        // link exists already
        if (preg_match('{"'.$packageRegex.'"\s*:}i', $links)) {
            $links = preg_replace('{"'.$packageRegex.'"(\s*:\s*)"[^"]+"}i', JsonFile::encode($package).'$1"'.$constraint.'"', $links);
        } elseif (preg_match('#[^\s](\s*)$#', $links, $match)) {
            // link missing but non empty links
            $links = preg_replace(
                '#'.$match[1].'$#',
                ',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $match[1],
                $links
            );
        } else {
            // links empty
            $links = $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $links;
        }

        $this->contents = preg_replace($linksRegex, '$1'.$links.'$3', $this->contents);

        return true;
    }

    public function addMainKey($key, $content)
    {
        if (preg_match('#[^{\s](\s*)\}$#', $this->contents, $match)) {
            $this->contents = preg_replace(
                '#'.$match[1].'\}$#',
                ',' . $this->newline . $this->indent . JsonFile::encode($key). ': '. $content . $this->newline . '}',
                $this->contents
            );
        } else {
            $this->contents = preg_replace(
                '#\}$#',
                $this->indent . JsonFile::encode($key). ': '.$content . $this->newline . '}',
                $this->contents
            );
        }
    }

    protected function format($data)
    {
        if (is_array($data)) {
            reset($data);

            if (is_numeric(key($data))) {
                return '['.implode(', ', $data).']';
            }

            $out = '{' . $this->newline;
            foreach ($data as $key => $val) {
                $elems[] = $this->indent . $this->indent . JsonFile::encode($key). ': '.$this->format($val);
            }
            return $out . implode(','.$this->newline, $elems) . $this->newline . $this->indent . '}';
        }

        return JsonFile::encode($data);
    }

    protected function detectIndenting()
    {
        if (preg_match('{^(\s+)"}', $this->contents, $match)) {
            $this->indent = $match[1];
        } else {
            $this->indent = '    ';
        }
    }
}
