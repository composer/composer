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
    private static $RECURSE_BLOCKS;
    private static $RECURSE_ARRAYS;
    private static $JSON_VALUE;
    private static $JSON_STRING;

    private $contents;
    private $newline;
    private $indent;

    public function __construct($contents)
    {
        if (!self::$RECURSE_BLOCKS) {
            self::$RECURSE_BLOCKS = '(?:[^{}]*|\{(?:[^{}]*|\{(?:[^{}]*|\{(?:[^{}]*|\{[^{}]*\})*\})*\})*\})*';
            self::$RECURSE_ARRAYS = '(?:[^\]]*|\[(?:[^\]]*|\[(?:[^\]]*|\[(?:[^\]]*|\[[^\]]*\])*\])*\])*\]|'.self::$RECURSE_BLOCKS.')*';
            self::$JSON_STRING = '"(?:[^\0-\x09\x0a-\x1f\\\\"]+|\\\\["bfnrt/\\\\]|\\\\u[a-fA-F0-9]{4})*"';
            self::$JSON_VALUE = '(?:[0-9.]+|null|true|false|'.self::$JSON_STRING.'|\['.self::$RECURSE_ARRAYS.'\]|\{'.self::$RECURSE_BLOCKS.'\})';
        }

        $contents = trim($contents);
        if ($contents === '') {
            $contents = '{}';
        }
        if (!$this->pregMatch('#^\{(.*)\}$#s', $contents)) {
            throw new \InvalidArgumentException('The json file must be an object ({})');
        }
        $this->newline = false !== strpos($contents, "\r\n") ? "\r\n" : "\n";
        $this->contents = $contents === '{}' ? '{' . $this->newline . '}' : $contents;
        $this->detectIndenting();
    }

    public function getContents()
    {
        return $this->contents . $this->newline;
    }

    public function addLink($type, $package, $constraint, $sortPackages = false)
    {
        $decoded = JsonFile::parseJson($this->contents);

        // no link of that type yet
        if (!isset($decoded[$type])) {
            return $this->addMainKey($type, array($package => $constraint));
        }

        $regex = '{^(\s*\{\s*(?:'.self::$JSON_STRING.'\s*:\s*'.self::$JSON_VALUE.'\s*,\s*)*?)'.
            '('.preg_quote(JsonFile::encode($type)).'\s*:\s*)('.self::$JSON_VALUE.')(.*)}s';
        if (!$this->pregMatch($regex, $this->contents, $matches)) {
            return false;
        }

        $links = $matches[3];

        if (isset($decoded[$type][$package])) {
            // update existing link
            $packageRegex = str_replace('/', '\\\\?/', preg_quote($package));
            // addcslashes is used to double up backslashes since preg_replace resolves them as back references otherwise, see #1588
            $links = preg_replace('{"'.$packageRegex.'"(\s*:\s*)'.self::$JSON_STRING.'}i', addcslashes(JsonFile::encode($package).'${1}"'.$constraint.'"', '\\'), $links);
        } else {
            if ($this->pregMatch('#^\s*\{\s*\S+.*?(\s*\}\s*)$#s', $links, $match)) {
                // link missing but non empty links
                $links = preg_replace(
                    '{'.preg_quote($match[1]).'$}',
                    addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $match[1], '\\'),
                    $links
                );
            } else {
                // links empty
                $links = '{' . $this->newline .
                    $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $this->newline .
                    $this->indent . '}';
            }
        }

        if (true === $sortPackages) {
            $requirements = json_decode($links, true);

            ksort($requirements);
            $links = $this->format($requirements);
        }

        $this->contents = $matches[1] . $matches[2] . $links . $matches[4];

        return true;
    }

    public function addRepository($name, $config)
    {
        return $this->addSubNode('repositories', $name, $config);
    }

    public function removeRepository($name)
    {
        return $this->removeSubNode('repositories', $name);
    }

    public function addConfigSetting($name, $value)
    {
        return $this->addSubNode('config', $name, $value);
    }

    public function removeConfigSetting($name)
    {
        return $this->removeSubNode('config', $name);
    }

    public function addSubNode($mainNode, $name, $value)
    {
        $decoded = JsonFile::parseJson($this->contents);

        // no main node yet
        if (!isset($decoded[$mainNode])) {
            $this->addMainKey($mainNode, array($name => $value));

            return true;
        }

        $subName = null;
        if (in_array($mainNode, array('config', 'repositories')) && false !== strpos($name, '.')) {
            list($name, $subName) = explode('.', $name, 2);
        }

        // main node content not match-able
        $nodeRegex = '{^(\s*\{\s*(?:'.self::$JSON_STRING.'\s*:\s*'.self::$JSON_VALUE.'\s*,\s*)*?)'.
            '('.preg_quote(JsonFile::encode($mainNode)).'\s*:\s*\{)('.self::$RECURSE_BLOCKS.')(\})(.*)}s';
        try {
            if (!$this->pregMatch($nodeRegex, $this->contents, $match)) {
                return false;
            }
        } catch (\RuntimeException $e) {
            if ($e->getCode() === PREG_BACKTRACK_LIMIT_ERROR) {
                return false;
            }
            throw $e;
        }

        $children = $match[3];

        // invalid match due to un-regexable content, abort
        if (!@json_decode('{'.$children.'}')) {
            return false;
        }

        $that = $this;

        // child exists
        if ($this->pregMatch('{("'.preg_quote($name).'"\s*:\s*)('.self::$JSON_VALUE.')(,?)}', $children, $matches)) {
            $children = preg_replace_callback('{("'.preg_quote($name).'"\s*:\s*)('.self::$JSON_VALUE.')(,?)}', function ($matches) use ($name, $subName, $value, $that) {
                if ($subName !== null) {
                    $curVal = json_decode($matches[2], true);
                    $curVal[$subName] = $value;
                    $value = $curVal;
                }

                return $matches[1] . $that->format($value, 1) . $matches[3];
            }, $children);
        } elseif ($this->pregMatch('#[^\s](\s*)$#', $children, $match)) {
            if ($subName !== null) {
                $value = array($subName => $value);
            }

            // child missing but non empty children
            $children = preg_replace(
                '#'.$match[1].'$#',
                addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $match[1], '\\'),
                $children
            );
        } else {
            if ($subName !== null) {
                $value = array($subName => $value);
            }

            // children present but empty
            $children = $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $children;
        }

        $this->contents = preg_replace($nodeRegex, addcslashes('${1}${2}'.$children.'${4}${5}', '\\'), $this->contents);

        return true;
    }

    public function removeSubNode($mainNode, $name)
    {
        $decoded = JsonFile::parseJson($this->contents);

        // no node or empty node
        if (empty($decoded[$mainNode])) {
            return true;
        }

        // no node content match-able
        $nodeRegex = '{^(\s*\{\s*(?:'.self::$JSON_STRING.'\s*:\s*'.self::$JSON_VALUE.'\s*,\s*)*?)'.
            '('.preg_quote(JsonFile::encode($mainNode)).'\s*:\s*\{)('.self::$RECURSE_BLOCKS.')(\})(.*)}s';
        try {
            if (!$this->pregMatch($nodeRegex, $this->contents, $match)) {
                return false;
            }
        } catch (\RuntimeException $e) {
            if ($e->getCode() === PREG_BACKTRACK_LIMIT_ERROR) {
                return false;
            }
            throw $e;
        }

        $children = $match[3];

        // invalid match due to un-regexable content, abort
        if (!@json_decode('{'.$children.'}', true)) {
            return false;
        }

        $subName = null;
        if (in_array($mainNode, array('config', 'repositories')) && false !== strpos($name, '.')) {
            list($name, $subName) = explode('.', $name, 2);
        }

        // no node to remove
        if (!isset($decoded[$mainNode][$name]) || ($subName && !isset($decoded[$mainNode][$name][$subName]))) {
            return true;
        }

        // try and find a match for the subkey
        if ($this->pregMatch('{"'.preg_quote($name).'"\s*:}i', $children)) {
            // find best match for the value of "name"
            if (preg_match_all('{"'.preg_quote($name).'"\s*:\s*(?:'.self::$JSON_VALUE.')}', $children, $matches)) {
                $bestMatch = '';
                foreach ($matches[0] as $match) {
                    if (strlen($bestMatch) < strlen($match)) {
                        $bestMatch = $match;
                    }
                }
                $childrenClean = preg_replace('{,\s*'.preg_quote($bestMatch).'}i', '', $children, -1, $count);
                if (1 !== $count) {
                    $childrenClean = preg_replace('{'.preg_quote($bestMatch).'\s*,?\s*}i', '', $childrenClean, -1, $count);
                    if (1 !== $count) {
                        return false;
                    }
                }
            }
        } else {
            $childrenClean = $children;
        }

        // no child data left, $name was the only key in
        if (!trim($childrenClean)) {
            $this->contents = preg_replace($nodeRegex, '$1$2'.$this->newline.$this->indent.'$4$5', $this->contents);

            // we have a subname, so we restore the rest of $name
            if ($subName !== null) {
                $curVal = json_decode('{'.$children.'}', true);
                unset($curVal[$name][$subName]);
                $this->addSubNode($mainNode, $name, $curVal[$name]);
            }

            return true;
        }

        $that = $this;
        $this->contents = preg_replace_callback($nodeRegex, function ($matches) use ($that, $name, $subName, $childrenClean) {
            if ($subName !== null) {
                $curVal = json_decode('{'.$matches[3].'}', true);
                unset($curVal[$name][$subName]);
                $childrenClean = substr($that->format($curVal, 0), 1, -1);
            }

            return $matches[1] . $matches[2] . $childrenClean . $matches[4] . $matches[5];
        }, $this->contents);

        return true;
    }

    public function addMainKey($key, $content)
    {
        $decoded = JsonFile::parseJson($this->contents);
        $content = $this->format($content);

        // key exists already
        $regex = '{^(\s*\{\s*(?:'.self::$JSON_STRING.'\s*:\s*'.self::$JSON_VALUE.'\s*,\s*)*?)'.
            '('.preg_quote(JsonFile::encode($key)).'\s*:\s*'.self::$JSON_VALUE.')(.*)}s';
        if (isset($decoded[$key]) && $this->pregMatch($regex, $this->contents, $matches)) {
            // invalid match due to un-regexable content, abort
            if (!@json_decode('{'.$matches[2].'}')) {
                return false;
            }

            $this->contents = $matches[1] . JsonFile::encode($key).': '.$content . $matches[3];

            return true;
        }

        // append at the end of the file and keep whitespace
        if ($this->pregMatch('#[^{\s](\s*)\}$#', $this->contents, $match)) {
            $this->contents = preg_replace(
                '#'.$match[1].'\}$#',
                addcslashes(',' . $this->newline . $this->indent . JsonFile::encode($key). ': '. $content . $this->newline . '}', '\\'),
                $this->contents
            );

            return true;
        }

        // append at the end of the file
        $this->contents = preg_replace(
            '#\}$#',
            addcslashes($this->indent . JsonFile::encode($key). ': '.$content . $this->newline . '}', '\\'),
            $this->contents
        );

        return true;
    }

    public function format($data, $depth = 0)
    {
        if (is_array($data)) {
            reset($data);

            if (is_numeric(key($data))) {
                foreach ($data as $key => $val) {
                    $data[$key] = $this->format($val, $depth + 1);
                }

                return '['.implode(', ', $data).']';
            }

            $out = '{' . $this->newline;
            $elems = array();
            foreach ($data as $key => $val) {
                $elems[] = str_repeat($this->indent, $depth + 2) . JsonFile::encode($key). ': '.$this->format($val, $depth + 1);
            }

            return $out . implode(','.$this->newline, $elems) . $this->newline . str_repeat($this->indent, $depth + 1) . '}';
        }

        return JsonFile::encode($data);
    }

    protected function detectIndenting()
    {
        if ($this->pregMatch('{^(\s+)"}m', $this->contents, $match)) {
            $this->indent = $match[1];
        } else {
            $this->indent = '    ';
        }
    }

    protected function pregMatch($re, $str, &$matches = array())
    {
        $count = preg_match($re, $str, $matches);

        if ($count === false) {
            switch (preg_last_error()) {
                case PREG_NO_ERROR:
                    throw new \RuntimeException('Failed to execute regex: PREG_NO_ERROR', PREG_NO_ERROR);
                case PREG_INTERNAL_ERROR:
                    throw new \RuntimeException('Failed to execute regex: PREG_INTERNAL_ERROR', PREG_INTERNAL_ERROR);
                case PREG_BACKTRACK_LIMIT_ERROR:
                    throw new \RuntimeException('Failed to execute regex: PREG_BACKTRACK_LIMIT_ERROR', PREG_BACKTRACK_LIMIT_ERROR);
                case PREG_RECURSION_LIMIT_ERROR:
                    throw new \RuntimeException('Failed to execute regex: PREG_RECURSION_LIMIT_ERROR', PREG_RECURSION_LIMIT_ERROR);
                case PREG_BAD_UTF8_ERROR:
                    throw new \RuntimeException('Failed to execute regex: PREG_BAD_UTF8_ERROR', PREG_BAD_UTF8_ERROR);
                case PREG_BAD_UTF8_OFFSET_ERROR:
                    throw new \RuntimeException('Failed to execute regex: PREG_BAD_UTF8_OFFSET_ERROR', PREG_BAD_UTF8_OFFSET_ERROR);
                default:
                    throw new \RuntimeException('Failed to execute regex: Unknown error');
            }
        }

        return $count;
    }
}
