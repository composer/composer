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
        if ("" !== $name && !$this->doRemoveRepository($name)) {
            return false;
        }

        if (!$this->doConvertRepositoriesFromAssocToList()) {
            return false;
        }

        if (is_array($config) && !is_numeric($name) && '' !== $name) {
            $config = ['name' => $name] + $config;
        } elseif ($config === false) {
            $config = [$name => $config];
        }

        return $this->addListItem('repositories', $config, $append);
    }

    private function doConvertRepositoriesFromAssocToList(): bool
    {
        $decoded = json_decode($this->contents, false);

        if (($decoded->repositories ?? null) instanceof \stdClass) {
            // delete from bottom to top, to ensure keys stay the same
            $entriesToRevert = array_reverse(array_keys((array) $decoded->repositories));

            foreach ($entriesToRevert as $entryKey) {
                if (!$this->removeSubNode('repositories', (string) $entryKey)) {
                    return false;
                }
            }

            $this->changeEmptyMainKeyFromAssocToList('repositories');

            // re-add in order
            foreach (((array) $decoded->repositories) as $repositoryName => $repository) {
                if (!$repository instanceof \stdClass) {
                    if (!$this->addListItem('repositories', [$repositoryName => $repository], true)) {
                        return false;
                    }
                } elseif (is_numeric($repositoryName)) {
                    if (!$this->addListItem('repositories', $repository, true)) {
                        return false;
                    }
                } else {
                    $repository = (array) $repository;
                    // prepend name property
                    $repository = ['name' => $repositoryName] + $repository;
                    if (!$this->addListItem('repositories', $repository, true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function setRepositoryUrl(string $name, string $url): bool
    {
        $decoded = JsonFile::parseJson($this->contents);
        $repositoryIndex = null;

        foreach ($decoded['repositories'] ?? [] as $index => $repository) {
            if ($name === $index) {
                $repositoryIndex = $index;
                break;
            }

            if ($name === ($repository['name'] ?? null)) {
                $repositoryIndex = $index;
                break;
            }
        }

        if (null === $repositoryIndex) {
            return false;
        }

        $listRegex = null;

        if (is_int($repositoryIndex)) {
            $listRegex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?"repositories"\s*:\s*\[\s*((?&json)\s*+,\s*+){' . max(0, $repositoryIndex) . '})(?P<repository>(?&object))(?P<end>.*)}sx';
        }

        $objectRegex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?"repositories"\s*:\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?' . preg_quote(JsonFile::encode($repositoryIndex)) . '\s*:\s*)(?P<repository>(?&object))(?P<end>.*)}sx';
        $matches = null;

        if (($listRegex !== null && Preg::isMatch($listRegex, $this->contents, $matches)) || Preg::isMatch($objectRegex, $this->contents, $matches)) {
            assert(isset($matches['start']) && is_string($matches['start']));
            assert(isset($matches['repository']) && is_string($matches['repository']));
            assert(isset($matches['end']) && is_string($matches['end']));

            // invalid match due to un-regexable content, abort
            if (false === @json_decode($matches['repository'])) {
                return false;
            }

            $repositoryRegex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?"url"\s*:\s*)(?P<url>(?&string))(?P<end>.*)}sx';

            $this->contents = $matches['start'] . Preg::replaceCallback($repositoryRegex, static function (array $repositoryMatches) use ($url): string {
                return $repositoryMatches['start'] . JsonFile::encode($url) . $repositoryMatches['end'];
            }, $matches['repository']) . $matches['end'];

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed>|false $config
     */
    public function insertRepository(string $name, $config, string $referenceName, int $offset = 0): bool
    {
        if ("" !== $name && !$this->doRemoveRepository($name)) {
            return false;
        }

        if (!$this->doConvertRepositoriesFromAssocToList()) {
            return false;
        }

        $indexToInsert = null;
        $decoded = JsonFile::parseJson($this->contents);

        foreach ($decoded['repositories'] as $repositoryIndex => $repository) {
            if (($repository['name'] ?? null) === $referenceName) {
                $indexToInsert = $repositoryIndex;
                break;
            }

            if ($repositoryIndex === $referenceName) {
                $indexToInsert = $repositoryIndex;
                break;
            }

            if ([$referenceName => false] === $repository) {
                $indexToInsert = $repositoryIndex;
                break;
            }
        }

        if ($indexToInsert === null) {
            return false;
        }

        if (is_array($config) && !is_numeric($name) && '' !== $name) {
            $config = ['name' => $name] + $config;
        } elseif ($config === false) {
            $config = ['name' => $config];
        }

        return $this->insertListItem('repositories', $config, $indexToInsert + $offset);
    }

    public function removeRepository(string $name): bool
    {
        return $this->doRemoveRepository($name) && $this->removeMainKeyIfEmpty('repositories');
    }

    private function doRemoveRepository(string $name): bool
    {
        $decoded = json_decode($this->contents, false);
        $isAssoc = ($decoded->repositories ?? null) instanceof \stdClass;

        foreach ((array) ($decoded->repositories ?? []) as $repositoryIndex => $repository) {
            if ($repositoryIndex === $name && $isAssoc) {
                if (!$this->removeSubNode('repositories', $repositoryIndex)) {
                    return false;
                }

                break;
            }

            if (($repository->name ?? null) === $name) {
                if ($isAssoc) {
                    if (!$this->removeSubNode('repositories', (string) $repositoryIndex)) {
                        return false;
                    }
                } else {
                    if (!$this->removeListItem('repositories', (int) $repositoryIndex)) {
                        return false;
                    }
                }

                break;
            }

            if ($isAssoc) {
                if ($name === $repositoryIndex && false === $repository) {
                    if (!$this->removeSubNode('repositories', $repositoryIndex)) {
                        return false;
                    }

                    return true;
                }
            } else {
                $repositoryAsArray = (array) $repository;

                if (false === ($repositoryAsArray[$name] ?? null) && 1 === count($repositoryAsArray)) {
                    if (!$this->removeListItem('repositories', (int) $repositoryIndex)) {
                        return false;
                    }

                    return true;
                }
            }
        }

        return true;
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
        } elseif (Preg::isMatch('#^\{(?P<leadingspace>\s*?)(?P<content>\S+.*?)?(?P<trailingspace>\s*)\}$#s', $children, $match)) {
            $whitespace = $match['trailingspace'];
            if (null !== $match['content']) {
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
                    $whitespace = $match['leadingspace'];
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
        } else {
            throw new \LogicException('Nothing matched above for: '.$children);
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
        if (Preg::isMatch('#^\{\s*?(?P<content>\S+.*?)?(?P<trailingspace>\s*)\}$#s', $childrenClean, $match)) {
            if (null === $match['content']) {
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
     * @param mixed $value
     */
    public function addListItem(string $mainNode, $value, bool $append = true): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        // no main node yet
        if (!isset($decoded[$mainNode])) {
            if (!$this->addMainKey($mainNode, [])) {
                return false;
            }
        }

        // main node content not match-able
        $nodeRegex = '{'.self::DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
            preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&array))(?P<end>.*)}sx';

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
        if (false === @json_decode($children)) {
            return false;
        }

        if (Preg::isMatch('#^\[(?P<leadingspace>\s*?)(?P<content>\S+.*?)?(?P<trailingspace>\s*)\]$#s', $children, $match)) {
            $leadingWhitespace = $match['leadingspace'];
            $whitespace = $match['trailingspace'];
            $leadingItemWhitespace = $this->newline . $this->indent . $this->indent;
            $trailingItemWhitespace = $whitespace;
            $itemDepth = 1;

            // keep oneline lists as one line
            if (!str_contains($whitespace, $this->newline)) {
                $leadingItemWhitespace = $leadingWhitespace;
                $trailingItemWhitespace = $leadingWhitespace;
                $itemDepth = 0;
            }

            if (null !== $match['content']) {
                // child missing but non empty children
                if ($append) {
                    $children = Preg::replace(
                        '#'.$whitespace.']$#',
                        addcslashes(',' . $leadingItemWhitespace . $this->format($value, $itemDepth) . $trailingItemWhitespace . ']', '\\$'),
                        $children
                    );
                } else {
                    $whitespace = $match['leadingspace'];
                    $children = Preg::replace(
                        '#^\['.$whitespace.'#',
                        addcslashes('[' . $whitespace . $this->format($value, $itemDepth) . ',' . $leadingItemWhitespace, '\\$'),
                        $children
                    );
                }
            } else {
                // children present but empty
                $children = '[' . $leadingItemWhitespace . $this->format($value, $itemDepth) . $trailingItemWhitespace . ']';
            }
        } else {
            throw new \LogicException('Nothing matched above for: '.$children);
        }

        $this->contents = Preg::replaceCallback($nodeRegex, static function ($m) use ($children): string {
            return $m['start'] . $children . $m['end'];
        }, $this->contents);

        return true;
    }

    /**
     * @param mixed $value
     */
    public function insertListItem(string $mainNode, $value, int $index): bool
    {
        if ($index < 0) {
            throw new \InvalidArgumentException('Index can only be positive integer');
        }

        if ($index === 0) {
            return $this->addListItem($mainNode, $value, false);
        }

        $decoded = JsonFile::parseJson($this->contents);

        // no main node yet
        if (!isset($decoded[$mainNode])) {
            if (!$this->addMainKey($mainNode, [])) {
                return false;
            }
        }

        if (count($decoded[$mainNode]) === $index) {
            return $this->addListItem($mainNode, $value, true);
        }

        // main node content not match-able
        $nodeRegex = '{'.self::DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
            preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&array))(?P<end>.*)}sx';

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
        if (false === @json_decode($children)) {
            return false;
        }

        $listSkipToItemRegex = '{'.self::DEFINES.'^(?P<start>\[\s*((?&json)\s*+,\s*?){' . max(0, $index) . '})(?P<space_before_item>(\s*))(?P<end>.*)}sx';

        $children = Preg::replaceCallback($listSkipToItemRegex, function ($m) use ($value): string {
            return $m['start'] . $m['space_before_item'] . $this->format($value, 1) . ',' . $m['space_before_item'] . $m['end'];
        }, $children);

        $this->contents = Preg::replaceCallback($nodeRegex, static function ($m) use ($children): string {
            return $m['start'] . $children . $m['end'];
        }, $this->contents);

        return true;
    }

    public function removeListItem(string $mainNode, int $nodeIndex): bool
    {
        // invalid index, that cannot be removed anyway
        if ($nodeIndex < 0) {
            return true;
        }

        $decoded = JsonFile::parseJson($this->contents);

        // no node or empty node
        if ([] === $decoded[$mainNode]) {
            return true;
        }

        // no node content match-able
        $nodeRegex = '{'.self::DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
            preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&array))(?P<end>.*)}sx';
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
        if (false === @json_decode($children, true)) {
            return false;
        }

        // no node to remove
        if (!isset($decoded[$mainNode][$nodeIndex])) {
            return true;
        }

        $contentRegex = '(?&json)';

        if ($nodeIndex > 1) {
            $startRegex = '(?&json)\s*+(?:,(?&json)\s*+){' . ($nodeIndex - 1) . '}';
            // remove leading array separator in case we might remove the last
            $contentRegex = '\s*+,?\s*+' . $contentRegex;
            $endRegex = '(?:(\s*+,\s*+(?&json))*(?:\s*+(?&json))?)\s*+';
        } elseif ($nodeIndex > 0) {
            $startRegex = '(?&json)\s*+';
            // remove leading array separator in case we might remove the last
            $contentRegex = '\s*+,?\s*+' . $contentRegex;
            $endRegex = '(?:(\s*+,\s*+(?&json))*(?:\s*+(?&json))?)\s*+';
        } else {
            $startRegex = '\s*+';
            // remove trailing array separator when we delete first
            $contentRegex = $contentRegex . '\s*+,?\s*+';
            $endRegex = '(?:((?&json)\s*+,\s*+)*(?:\s*+(?&json))?)\s*+';
        }

        if (Preg::isMatch('{'.self::DEFINES.'(?P<start>\[' . $startRegex . ')(?P<content>' . $contentRegex . ')(?P<end>' . $endRegex . '\])}sx', $children, $childMatch)) {
            $this->contents = $match['start'] . $childMatch['start'] . $childMatch['end'] . $match['end'];

            return true;
        }

        return false;
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

    public function changeEmptyMainKeyFromAssocToList(string $key): bool
    {
        $decoded = JsonFile::parseJson($this->contents);

        if (!array_key_exists($key, $decoded)) {
            return true;
        }

        $regex = '{'.self::DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?'.preg_quote(JsonFile::encode($key)).'\s*:\s*)(?P<removal>\{(?P<removal_space>\s*+)\})(?P<end>\s*,?\s*.*)}sx';
        if (Preg::isMatch($regex, $this->contents, $matches)) {
            assert(is_string($matches['start']));
            assert(is_string($matches['removal']));
            assert(is_string($matches['end']));

            // invalid match due to un-regexable content, abort
            if (false === @json_decode($matches['removal'])) {
                return false;
            }

            $this->contents = $matches['start'] . '[' . $matches['removal_space'] . ']' . $matches['end'];

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
