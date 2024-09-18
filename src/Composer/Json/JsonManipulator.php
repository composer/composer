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

namespace Composer\Json;

use Composer\Pcre\Preg;
use Composer\Repository\PlatformRepository;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonManipulator
{
    /** @var string */
    private const DEFINES = '(?(DEFINE)
       (?<number>    -? (?= [1-9]|0(?!\d) ) \d++ (?:\.\d++)? (?:[eE] [+-]?+ \d++)? )
       (?<boolean>   true | false | null )
       (?<string>    " (?:[^"\\\\]*+ | \\\\ ["\\\\bfnrt\/] | \\\\ u [0-9A-Fa-f]{4} )* " )
       (?<array>     \[  (?:  (?&json) \s*+ (?: , (?&json) \s*+ )*+  )?+  \s*+ \] )
       (?<pair>      \s*+ (?&string) \s*+ : (?&json) \s*+ )
       (?<object>    \{  (?:  (?&pair)  (?: , (?&pair)  )*+  )?+  \s*+ \} )
       (?<json>      \s*+ (?: (?&number) | (?&boolean) | (?&string) | (?&array) | (?&object) ) )
    )';

    /** @var string */
    private $contents;
    /** @var string */
    private $newline;
    /** @var string */
    private $indent;

    public function __construct(string $contents)
    {
        $contents = trim($contents);
        if ($contents === '') {
            $contents = '{}';
        }
        if (!Preg::isMatch('#^\{(.*)\}$#s', $contents)) {
            throw new \InvalidArgumentException('The json file must be an object ({})');
        }
        $this->newline = false !== strpos($contents, "\r\n") ? "\r\n" : "\n";
        $this->contents = $contents === '{}' ? '{' . $this->newline . '}' : $contents;
        $this->detectIndenting();
    }

    public function getContents(): string
    {
        return $this->contents . $this->newline;
    }

    public function addLink(string $type, string $package, string $constraint, bool $sortPackages = false): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        // no link of that type yet
        if (!isset($decoded[$type])) {
            return $this->addMainKey($type, [$package => $constraint]);
        }

        $regex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?)'.
            '(?P<property>'.preg_quote(JsonFile::encode($type)).'\s*:\s*)(?P<value>(?&json))(?P<end>.*)}sx';
        if (!Preg::isMatch($regex, $this->contents, $matches)) {
            return false;
        }
        assert(is_string($matches['start']));
        assert(is_string($matches['value']));
        assert(is_string($matches['end']));

        $links = $matches['value'];

        // try to find existing link
        $packageRegex = str_replace('/', '\\\\?/', preg_quote($package));
        $regex = '{'.self::DEFINES.'"(?P<package>'.$packageRegex.')"(\s*:\s*)(?&string)}ix';
        if (Preg::isMatch($regex, $links, $packageMatches)) {
            assert(is_string($packageMatches['package']));
            // update existing link
            $existingPackage = $packageMatches['package'];
            $packageRegex = str_replace('/', '\\\\?/', preg_quote($existingPackage));
            $links = Preg::replaceCallback('{'.self::DEFINES.'"'.$packageRegex.'"(?P<separator>\s*:\s*)(?&string)}ix', static function ($m) use ($existingPackage, $constraint): string {
                return JsonFile::encode(str_replace('\\/', '/', $existingPackage)) . $m['separator'] . '"' . $constraint . '"';
            }, $links);
        } else {
            if (Preg::isMatchStrictGroups('#^\s*\{\s*\S+.*?(\s*\}\s*)$#s', $links, $match)) {
                // link missing but non empty links
                $links = Preg::replace(
                    '{'.preg_quote($match[1]).'$}',
                    // addcslashes is used to double up backslashes/$ since preg_replace resolves them as back references otherwise, see #1588
                    addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $match[1], '\\$'),
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
            $this->sortPackages($requirements);
            $links = $this->format($requirements);
        }

        $this->contents = $matches['start'] . $matches['property'] . $links . $matches['end'];

        return true;
    }

    /**
     * Sorts packages by importance (platform packages first, then PHP dependencies) and alphabetically.
     *
     * @link https://getcomposer.org/doc/02-libraries.md#platform-packages
     *
     * @param array<string> $packages
     */
    private function sortPackages(array &$packages = []): void
    {
        $prefix = static function ($requirement): string {
            if (PlatformRepository::isPlatformPackage($requirement)) {
                return Preg::replace(
                    [
                        '/^php/',
                        '/^hhvm/',
                        '/^ext/',
                        '/^lib/',
                        '/^\D/',
                    ],
                    [
                        '0-$0',
                        '1-$0',
                        '2-$0',
                        '3-$0',
                        '4-$0',
                    ],
                    $requirement
                );
            }

            return '5-'.$requirement;
        };

        uksort($packages, static function ($a, $b) use ($prefix): int {
            return strnatcmp($prefix($a), $prefix($b));
        });
    }

    /**
     * @param array<string, mixed>|false $config
     */
    public function addRepository(string $name, $config, bool $append = true): bool
    {
        return $this->addSubNode('repositories', $name, $config, $append);
    }

    public function removeRepository(string $name): bool
    {
        return $this->removeSubNode('repositories', $name);
    }

    /**
     * @param mixed  $value
     */
    public function addConfigSetting(string $name, $value): bool
    {
        return $this->addSubNode('config', $name, $value);
    }

    public function removeConfigSetting(string $name): bool
    {
        return $this->removeSubNode('config', $name);
    }

    /**
     * @param mixed $value
     */
    public function addProperty(string $name, $value): bool
    {
        if (strpos($name, 'suggest.') === 0) {
            return $this->addSubNode('suggest', substr($name, 8), $value);
        }

        if (strpos($name, 'extra.') === 0) {
            return $this->addSubNode('extra', substr($name, 6), $value);
        }

        if (strpos($name, 'scripts.') === 0) {
            return $this->addSubNode('scripts', substr($name, 8), $value);
        }

        return $this->addMainKey($name, $value);
    }

    public function removeProperty(string $name): bool
    {
        if (strpos($name, 'suggest.') === 0) {
            return $this->removeSubNode('suggest', substr($name, 8));
        }

        if (strpos($name, 'extra.') === 0) {
            return $this->removeSubNode('extra', substr($name, 6));
        }

        if (strpos($name, 'scripts.') === 0) {
            return $this->removeSubNode('scripts', substr($name, 8));
        }

        if (strpos($name, 'autoload.') === 0) {
            return $this->removeSubNode('autoload', substr($name, 9));
        }

        if (strpos($name, 'autoload-dev.') === 0) {
            return $this->removeSubNode('autoload-dev', substr($name, 13));
        }

        return $this->removeMainKey($name);
    }

    /**
     * @param mixed  $value
     */
    public function addSubNode(string $mainNode, string $name, $value, bool $append = true): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        $subName = null;
        if (in_array($mainNode, ['config', 'extra', 'scripts']) && false !== strpos($name, '.')) {
            [$name, $subName] = explode('.', $name, 2);
        }

        // no main node yet
        if (!isset($decoded[$mainNode])) {
            if ($subName !== null) {
                $this->addMainKey($mainNode, [$name => [$subName => $value]]);
            } else {
                $this->addMainKey($mainNode, [$name => $value]);
            }

            return true;
        }

        // main node content not match-able
        $nodeRegex = '{'.self::DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
            preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&object))(?P<end>.*)}sx';

        try {
            if (!Preg::isMatch($nodeRegex, $this->contents, $match)) {
                return false;
            }
        } catch (\RuntimeException $e) {
            if ($e->getCode() === PREG_BACKTRACK_LIMIT_ERROR) {
                return false;
            }
            throw $e;
        }

        assert(is_string($match['start']));
        assert(is_string($match['content']));
        assert(is_string($match['end']));

        $children = $match['content'];
        // invalid match due to un-regexable content, abort
        if (!@json_decode($children)) {
            return false;
        }

        // child exists
        $childRegex = '{'.self::DEFINES.'(?P<start>"'.preg_quote($name).'"\s*:\s*)(?P<content>(?&json))(?P<end>,?)}x';
        if (Preg::isMatch($childRegex, $children, $matches)) {
            $children = Preg::replaceCallback($childRegex, function ($matches) use ($subName, $value): string {
                if ($subName !== null && is_string($matches['content'])) {
                    $curVal = json_decode($matches['content'], true);
                    if (!is_array($curVal)) {
                        $curVal = [];
                    }
                    $curVal[$subName] = $value;
                    $value = $curVal;
                }

                return $matches['start'] . $this->format($value, 1) . $matches['end'];
            }, $children);
        } else {
            Preg::match('#^{ (?P<leadingspace>\s*?) (?P<content>\S+.*?)? (?P<trailingspace>\s*) }$#sx', $children, $match);

            $whitespace = '';
            if (!empty($match['trailingspace'])) {
                $whitespace = $match['trailingspace'];
            }

            if (!empty($match['content'])) {
                if ($subName !== null) {
                    $value = [$subName => $value];
                }

                // child missing but non empty children
                if ($append) {
                    $children = Preg::replace(
                        '#'.$whitespace.'}$#',
                        addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $whitespace . '}', '\\$'),
                        $children
                    );
                } else {
                    $whitespace = '';
                    if (!empty($match['leadingspace'])) {
                        $whitespace = $match['leadingspace'];
                    }

                    $children = Preg::replace(
                        '#^{'.$whitespace.'#',
                        addcslashes('{' . $whitespace . JsonFile::encode($name).': '.$this->format($value, 1) . ',' . $this->newline . $this->indent . $this->indent, '\\$'),
                        $children
                    );
                }
            } else {
                if ($subName !== null) {
                    $value = [$subName => $value];
                }

                // children present but empty
                $children = '{' . $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $whitespace . '}';
            }
        }

        $this->contents = Preg::replaceCallback($nodeRegex, static function ($m) use ($children): string {
            return $m['start'] . $children . $m['end'];
        }, $this->contents);

        return true;
    }

    public function removeSubNode(string $mainNode, string $name): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        // no node or empty node
        if (empty($decoded[$mainNode])) {
            return true;
        }

        // no node content match-able
        $nodeRegex = '{'.self::DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
            preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&object))(?P<end>.*)}sx';
        try {
            if (!Preg::isMatch($nodeRegex, $this->contents, $match)) {
                return false;
            }
        } catch (\RuntimeException $e) {
            if ($e->getCode() === PREG_BACKTRACK_LIMIT_ERROR) {
                return false;
            }
            throw $e;
        }

        assert(is_string($match['start']));
        assert(is_string($match['content']));
        assert(is_string($match['end']));

        $children = $match['content'];

        // invalid match due to un-regexable content, abort
        if (!@json_decode($children, true)) {
            return false;
        }

        $subName = null;
        if (in_array($mainNode, ['config', 'extra', 'scripts']) && false !== strpos($name, '.')) {
            [$name, $subName] = explode('.', $name, 2);
        }

        // no node to remove
        if (!isset($decoded[$mainNode][$name]) || ($subName && !isset($decoded[$mainNode][$name][$subName]))) {
            return true;
        }

        // try and find a match for the subkey
        $keyRegex = str_replace('/', '\\\\?/', preg_quote($name));
        if (Preg::isMatch('{"'.$keyRegex.'"\s*:}i', $children)) {
            // find best match for the value of "name"
            if (Preg::isMatchAll('{'.self::DEFINES.'"'.$keyRegex.'"\s*:\s*(?:(?&json))}x', $children, $matches)) {
                $bestMatch = '';
                foreach ($matches[0] as $match) {
                    assert(is_string($match));
                    if (strlen($bestMatch) < strlen($match)) {
                        $bestMatch = $match;
                    }
                }
                $childrenClean = Preg::replace('{,\s*'.preg_quote($bestMatch).'}i', '', $children, -1, $count);
                if (1 !== $count) {
                    $childrenClean = Preg::replace('{'.preg_quote($bestMatch).'\s*,?\s*}i', '', $childrenClean, -1, $count);
                    if (1 !== $count) {
                        return false;
                    }
                }
            }
        } else {
            $childrenClean = $children;
        }

        if (!isset($childrenClean)) {
            throw new \InvalidArgumentException("JsonManipulator: \$childrenClean is not defined. Please report at https://github.com/composer/composer/issues/new.");
        }

        // no child data left, $name was the only key in
        unset($match);
        Preg::match('#^{ \s*? (?P<content>\S+.*?)? (?P<trailingspace>\s*) }$#sx', $childrenClean, $match);
        if (empty($match['content'])) {
            $newline = $this->newline;
            $indent = $this->indent;

            $this->contents = Preg::replaceCallback($nodeRegex, static function ($matches) use ($indent, $newline): string {
                return $matches['start'] . '{' . $newline . $indent . '}' . $matches['end'];
            }, $this->contents);

            // we have a subname, so we restore the rest of $name
            if ($subName !== null) {
                $curVal = json_decode($children, true);
                unset($curVal[$name][$subName]);
                if ($curVal[$name] === []) {
                    $curVal[$name] = new \ArrayObject();
                }
                $this->addSubNode($mainNode, $name, $curVal[$name]);
            }

            return true;
        }

        $this->contents = Preg::replaceCallback($nodeRegex, function ($matches) use ($name, $subName, $childrenClean): string {
            assert(is_string($matches['content']));
            if ($subName !== null) {
                $curVal = json_decode($matches['content'], true);
                unset($curVal[$name][$subName]);
                if ($curVal[$name] === []) {
                    $curVal[$name] = new \ArrayObject();
                }
                $childrenClean = $this->format($curVal, 0, true);
            }

            return $matches['start'] . $childrenClean . $matches['end'];
        }, $this->contents);

        return true;
    }

    /**
     * @param mixed  $content
     */
    public function addMainKey(string $key, $content): bool
    {
        $decoded = JsonFile::parseJson($this->contents);
        $content = $this->format($content);

        // key exists already
        $regex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?)'.
            '(?P<key>'.preg_quote(JsonFile::encode($key)).'\s*:\s*(?&json))(?P<end>.*)}sx';
        if (isset($decoded[$key]) && Preg::isMatch($regex, $this->contents, $matches)) {
            // invalid match due to un-regexable content, abort
            if (!@json_decode('{'.$matches['key'].'}')) {
                return false;
            }

            $this->contents = $matches['start'] . JsonFile::encode($key).': '.$content . $matches['end'];

            return true;
        }

        // append at the end of the file and keep whitespace
        if (Preg::isMatch('#[^{\s](\s*)\}$#', $this->contents, $match)) {
            $this->contents = Preg::replace(
                '#'.$match[1].'\}$#',
                addcslashes(',' . $this->newline . $this->indent . JsonFile::encode($key). ': '. $content . $this->newline . '}', '\\$'),
                $this->contents
            );

            return true;
        }

        // append at the end of the file
        $this->contents = Preg::replace(
            '#\}$#',
            addcslashes($this->indent . JsonFile::encode($key). ': '.$content . $this->newline . '}', '\\$'),
            $this->contents
        );

        return true;
    }

    public function removeMainKey(string $key): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        if (!array_key_exists($key, $decoded)) {
            return true;
        }

        // key exists already
        $regex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?)'.
            '(?P<removal>'.preg_quote(JsonFile::encode($key)).'\s*:\s*(?&json))\s*,?\s*(?P<end>.*)}sx';
        if (Preg::isMatch($regex, $this->contents, $matches)) {
            assert(is_string($matches['start']));
            assert(is_string($matches['removal']));
            assert(is_string($matches['end']));

            // invalid match due to un-regexable content, abort
            if (!@json_decode('{'.$matches['removal'].'}')) {
                return false;
            }

            // check that we are not leaving a dangling comma on the previous line if the last line was removed
            if (Preg::isMatchStrictGroups('#,\s*$#', $matches['start']) && Preg::isMatch('#^\}$#', $matches['end'])) {
                $matches['start'] = rtrim(Preg::replace('#,(\s*)$#', '$1', $matches['start']), $this->indent);
            }

            $this->contents = $matches['start'] . $matches['end'];
            if (Preg::isMatch('#^\{\s*\}\s*$#', $this->contents)) {
                $this->contents = "{\n}";
            }

            return true;
        }

        return false;
    }

    public function removeMainKeyIfEmpty(string $key): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        if (!array_key_exists($key, $decoded)) {
            return true;
        }

        if (is_array($decoded[$key]) && count($decoded[$key]) === 0) {
            return $this->removeMainKey($key);
        }

        return true;
    }

    /**
     * @param mixed $data
     */
    public function format($data, int $depth = 0, bool $wasObject = false): string
    {
        if ($data instanceof \stdClass || $data instanceof \ArrayObject) {
            $data = (array) $data;
            $wasObject = true;
        }

        if (is_array($data)) {
            if (\count($data) === 0) {
                return $wasObject ? '{' . $this->newline . str_repeat($this->indent, $depth + 1) . '}' : '[]';
            }

            if (array_is_list($data)) {
                foreach ($data as $key => $val) {
                    $data[$key] = $this->format($val, $depth + 1);
                }

                return '['.implode(', ', $data).']';
            }

            $out = '{' . $this->newline;
            $elems = [];
            foreach ($data as $key => $val) {
                $elems[] = str_repeat($this->indent, $depth + 2) . JsonFile::encode((string) $key). ': '.$this->format($val, $depth + 1);
            }

            return $out . implode(','.$this->newline, $elems) . $this->newline . str_repeat($this->indent, $depth + 1) . '}';
        }

        return JsonFile::encode($data);
    }

    protected function detectIndenting(): void
    {
        $this->indent = JsonFile::detectIndenting($this->contents);
    }
}
