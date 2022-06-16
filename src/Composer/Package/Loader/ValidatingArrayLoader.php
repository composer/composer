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

namespace Composer\Package\Loader;

use Composer\Package\BasePackage;
use Composer\Pcre\Preg;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Version\VersionParser;
use Composer\Repository\PlatformRepository;
use Composer\Spdx\SpdxLicenses;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ValidatingArrayLoader implements LoaderInterface
{
    public const CHECK_ALL = 3;
    public const CHECK_UNBOUND_CONSTRAINTS = 1;
    public const CHECK_STRICT_CONSTRAINTS = 2;

    /** @var LoaderInterface */
    private $loader;
    /** @var VersionParser */
    private $versionParser;
    /** @var string[] */
    private $errors;
    /** @var string[] */
    private $warnings;
    /** @var mixed[] */
    private $config;
    /** @var int One or more of self::CHECK_* constants */
    private $flags;

    /**
     * @param true $strictName
     * @param int  $flags
     */
    public function __construct(LoaderInterface $loader, bool $strictName = true, ?VersionParser $parser = null, int $flags = 0)
    {
        $this->loader = $loader;
        $this->versionParser = $parser ?: new VersionParser();
        $this->flags = $flags;

        if ($strictName !== true) { // @phpstan-ignore-line
            trigger_error('$strictName must be set to true in ValidatingArrayLoader\'s constructor as of 2.2, and it will be removed in 3.0', E_USER_DEPRECATED);
        }
    }

    /**
     * @inheritDoc
     */
    public function load(array $config, string $class = 'Composer\Package\CompletePackage'): BasePackage
    {
        $this->errors = array();
        $this->warnings = array();
        $this->config = $config;

        $this->validateString('name', true);
        if ($err = self::hasPackageNamingError($config['name'])) {
            $this->errors[] = 'name : '.$err;
        }

        if (!empty($this->config['version'])) {
            if (!is_scalar($this->config['version'])) {
                $this->validateString('version');
            } else {
                if (!is_string($this->config['version'])) {
                    $this->config['version'] = (string) $this->config['version'];
                }
                try {
                    $this->versionParser->normalize($this->config['version']);
                } catch (\Exception $e) {
                    $this->errors[] = 'version : invalid value ('.$this->config['version'].'): '.$e->getMessage();
                    unset($this->config['version']);
                }
            }
        }

        if (!empty($this->config['config']['platform'])) {
            foreach ((array) $this->config['config']['platform'] as $key => $platform) {
                if (false === $platform) {
                    continue;
                }
                if (!is_string($platform)) {
                    $this->errors[] = 'config.platform.' . $key . ' : invalid value ('.gettype($platform).' '.var_export($platform, true).'): expected string or false';
                    continue;
                }
                try {
                    $this->versionParser->normalize($platform);
                } catch (\Exception $e) {
                    $this->errors[] = 'config.platform.' . $key . ' : invalid value ('.$platform.'): '.$e->getMessage();
                }
            }
        }

        $this->validateRegex('type', '[A-Za-z0-9-]+');
        $this->validateString('target-dir');
        $this->validateArray('extra');

        if (isset($this->config['bin'])) {
            if (is_string($this->config['bin'])) {
                $this->validateString('bin');
            } else {
                $this->validateFlatArray('bin');
            }
        }

        $this->validateArray('scripts'); // TODO validate event names & listener syntax
        $this->validateString('description');
        $this->validateUrl('homepage');
        $this->validateFlatArray('keywords', '[\p{N}\p{L} ._-]+');

        $releaseDate = null;
        $this->validateString('time');
        if (!empty($this->config['time'])) {
            try {
                $releaseDate = new \DateTime($this->config['time'], new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                $this->errors[] = 'time : invalid value ('.$this->config['time'].'): '.$e->getMessage();
                unset($this->config['time']);
            }
        }

        // check for license validity on newly updated branches
        if (isset($this->config['license']) && (!$releaseDate || $releaseDate->getTimestamp() >= strtotime('-8days'))) {
            if (is_array($this->config['license']) || is_string($this->config['license'])) {
                $licenses = (array) $this->config['license'];

                $licenseValidator = new SpdxLicenses();
                foreach ($licenses as $license) {
                    // replace proprietary by MIT for validation purposes since it's not a valid SPDX identifier, but is accepted by composer
                    if ('proprietary' === $license) {
                        continue;
                    }
                    $licenseToValidate = str_replace('proprietary', 'MIT', $license);
                    if (!$licenseValidator->validate($licenseToValidate)) {
                        if ($licenseValidator->validate(trim($licenseToValidate))) {
                            $this->warnings[] = sprintf(
                                'License %s must not contain extra spaces, make sure to trim it.',
                                json_encode($license)
                            );
                        } else {
                            $this->warnings[] = sprintf(
                                'License %s is not a valid SPDX license identifier, see https://spdx.org/licenses/ if you use an open license.' . PHP_EOL .
                                'If the software is closed-source, you may use "proprietary" as license.',
                                json_encode($license)
                            );
                        }
                    }
                }
            }
        }

        if ($this->validateArray('authors') && !empty($this->config['authors'])) {
            foreach ($this->config['authors'] as $key => $author) {
                if (!is_array($author)) {
                    $this->errors[] = 'authors.'.$key.' : should be an array, '.gettype($author).' given';
                    unset($this->config['authors'][$key]);
                    continue;
                }
                foreach (array('homepage', 'email', 'name', 'role') as $authorData) {
                    if (isset($author[$authorData]) && !is_string($author[$authorData])) {
                        $this->errors[] = 'authors.'.$key.'.'.$authorData.' : invalid value, must be a string';
                        unset($this->config['authors'][$key][$authorData]);
                    }
                }
                if (isset($author['homepage']) && !$this->filterUrl($author['homepage'])) {
                    $this->warnings[] = 'authors.'.$key.'.homepage : invalid value ('.$author['homepage'].'), must be an http/https URL';
                    unset($this->config['authors'][$key]['homepage']);
                }
                if (isset($author['email']) && !filter_var($author['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->warnings[] = 'authors.'.$key.'.email : invalid value ('.$author['email'].'), must be a valid email address';
                    unset($this->config['authors'][$key]['email']);
                }
                if (empty($this->config['authors'][$key])) {
                    unset($this->config['authors'][$key]);
                }
            }
            if (empty($this->config['authors'])) {
                unset($this->config['authors']);
            }
        }

        if ($this->validateArray('support') && !empty($this->config['support'])) {
            foreach (array('issues', 'forum', 'wiki', 'source', 'email', 'irc', 'docs', 'rss', 'chat') as $key) {
                if (isset($this->config['support'][$key]) && !is_string($this->config['support'][$key])) {
                    $this->errors[] = 'support.'.$key.' : invalid value, must be a string';
                    unset($this->config['support'][$key]);
                }
            }

            if (isset($this->config['support']['email']) && !filter_var($this->config['support']['email'], FILTER_VALIDATE_EMAIL)) {
                $this->warnings[] = 'support.email : invalid value ('.$this->config['support']['email'].'), must be a valid email address';
                unset($this->config['support']['email']);
            }

            if (isset($this->config['support']['irc']) && !$this->filterUrl($this->config['support']['irc'], array('irc', 'ircs'))) {
                $this->warnings[] = 'support.irc : invalid value ('.$this->config['support']['irc'].'), must be a irc://<server>/<channel> or ircs:// URL';
                unset($this->config['support']['irc']);
            }

            foreach (array('issues', 'forum', 'wiki', 'source', 'docs', 'chat') as $key) {
                if (isset($this->config['support'][$key]) && !$this->filterUrl($this->config['support'][$key])) {
                    $this->warnings[] = 'support.'.$key.' : invalid value ('.$this->config['support'][$key].'), must be an http/https URL';
                    unset($this->config['support'][$key]);
                }
            }
            if (empty($this->config['support'])) {
                unset($this->config['support']);
            }
        }

        if ($this->validateArray('funding') && !empty($this->config['funding'])) {
            foreach ($this->config['funding'] as $key => $fundingOption) {
                if (!is_array($fundingOption)) {
                    $this->errors[] = 'funding.'.$key.' : should be an array, '.gettype($fundingOption).' given';
                    unset($this->config['funding'][$key]);
                    continue;
                }
                foreach (array('type', 'url') as $fundingData) {
                    if (isset($fundingOption[$fundingData]) && !is_string($fundingOption[$fundingData])) {
                        $this->errors[] = 'funding.'.$key.'.'.$fundingData.' : invalid value, must be a string';
                        unset($this->config['funding'][$key][$fundingData]);
                    }
                }
                if (isset($fundingOption['url']) && !$this->filterUrl($fundingOption['url'])) {
                    $this->warnings[] = 'funding.'.$key.'.url : invalid value ('.$fundingOption['url'].'), must be an http/https URL';
                    unset($this->config['funding'][$key]['url']);
                }
                if (empty($this->config['funding'][$key])) {
                    unset($this->config['funding'][$key]);
                }
            }
            if (empty($this->config['funding'])) {
                unset($this->config['funding']);
            }
        }

        $unboundConstraint = new Constraint('=', '10000000-dev');
        $stableConstraint = new Constraint('=', '1.0.0');

        foreach (array_keys(BasePackage::$supportedLinkTypes) as $linkType) {
            if ($this->validateArray($linkType) && isset($this->config[$linkType])) {
                foreach ($this->config[$linkType] as $package => $constraint) {
                    if (0 === strcasecmp($package, $this->config['name'])) {
                        $this->errors[] = $linkType.'.'.$package.' : a package cannot set a '.$linkType.' on itself';
                        unset($this->config[$linkType][$package]);
                        continue;
                    }
                    if ($err = self::hasPackageNamingError($package, true)) {
                        $this->warnings[] = $linkType.'.'.$err;
                    } elseif (!Preg::isMatch('{^[A-Za-z0-9_./-]+$}', $package)) {
                        $this->errors[] = $linkType.'.'.$package.' : invalid key, package names must be strings containing only [A-Za-z0-9_./-]';
                    }
                    if (!is_string($constraint)) {
                        $this->errors[] = $linkType.'.'.$package.' : invalid value, must be a string containing a version constraint';
                        unset($this->config[$linkType][$package]);
                    } elseif ('self.version' !== $constraint) {
                        try {
                            $linkConstraint = $this->versionParser->parseConstraints($constraint);
                        } catch (\Exception $e) {
                            $this->errors[] = $linkType.'.'.$package.' : invalid version constraint ('.$e->getMessage().')';
                            unset($this->config[$linkType][$package]);
                            continue;
                        }

                        // check requires for unbound constraints on non-platform packages
                        if (
                            ($this->flags & self::CHECK_UNBOUND_CONSTRAINTS)
                            && 'require' === $linkType
                            && $linkConstraint->matches($unboundConstraint)
                            && !PlatformRepository::isPlatformPackage($package)
                        ) {
                            $this->warnings[] = $linkType.'.'.$package.' : unbound version constraints ('.$constraint.') should be avoided';
                        } elseif (
                            // check requires for exact constraints
                            ($this->flags & self::CHECK_STRICT_CONSTRAINTS)
                            && 'require' === $linkType
                            && $linkConstraint instanceof Constraint && in_array($linkConstraint->getOperator(), ['==', '='], true)
                            && (new Constraint('>=', '1.0.0.0-dev'))->matches($linkConstraint)
                        ) {
                            $this->warnings[] = $linkType.'.'.$package.' : exact version constraints ('.$constraint.') should be avoided if the package follows semantic versioning';
                        }
                    }

                    if ($linkType === 'conflict' && isset($this->config['replace']) && $keys = array_intersect_key($this->config['replace'], $this->config['conflict'])) {
                        $this->errors[] = $linkType.'.'.$package.' : you cannot conflict with a package that is also replaced, as replace already creates an implicit conflict rule';
                        unset($this->config[$linkType][$package]);
                    }
                }
            }
        }

        if ($this->validateArray('suggest') && !empty($this->config['suggest'])) {
            foreach ($this->config['suggest'] as $package => $description) {
                if (!is_string($description)) {
                    $this->errors[] = 'suggest.'.$package.' : invalid value, must be a string describing why the package is suggested';
                    unset($this->config['suggest'][$package]);
                }
            }
        }

        if ($this->validateString('minimum-stability') && !empty($this->config['minimum-stability'])) {
            if (!isset(BasePackage::$stabilities[strtolower($this->config['minimum-stability'])]) && $this->config['minimum-stability'] !== 'RC') {
                $this->errors[] = 'minimum-stability : invalid value ('.$this->config['minimum-stability'].'), must be one of '.implode(', ', array_keys(BasePackage::$stabilities));
                unset($this->config['minimum-stability']);
            }
        }

        if ($this->validateArray('autoload') && !empty($this->config['autoload'])) {
            $types = array('psr-0', 'psr-4', 'classmap', 'files', 'exclude-from-classmap');
            foreach ($this->config['autoload'] as $type => $typeConfig) {
                if (!in_array($type, $types)) {
                    $this->errors[] = 'autoload : invalid value ('.$type.'), must be one of '.implode(', ', $types);
                    unset($this->config['autoload'][$type]);
                }
                if ($type === 'psr-4') {
                    foreach ($typeConfig as $namespace => $dirs) {
                        if ($namespace !== '' && '\\' !== substr($namespace, -1)) {
                            $this->errors[] = 'autoload.psr-4 : invalid value ('.$namespace.'), namespaces must end with a namespace separator, should be '.$namespace.'\\\\';
                        }
                    }
                }
            }
        }

        if (!empty($this->config['autoload']['psr-4']) && !empty($this->config['target-dir'])) {
            $this->errors[] = 'target-dir : this can not be used together with the autoload.psr-4 setting, remove target-dir to upgrade to psr-4';
            // Unset the psr-4 setting, since unsetting target-dir might
            // interfere with other settings.
            unset($this->config['autoload']['psr-4']);
        }

        foreach (array('source', 'dist') as $srcType) {
            if ($this->validateArray($srcType) && !empty($this->config[$srcType])) {
                if (!isset($this->config[$srcType]['type'])) {
                    $this->errors[] = $srcType . '.type : must be present';
                }
                if (!isset($this->config[$srcType]['url'])) {
                    $this->errors[] = $srcType . '.url : must be present';
                }
                if ($srcType === 'source' && !isset($this->config[$srcType]['reference'])) {
                    $this->errors[] = $srcType . '.reference : must be present';
                }
                if (isset($this->config[$srcType]['type']) && !is_string($this->config[$srcType]['type'])) {
                    $this->errors[] = $srcType . '.type : should be a string, '.gettype($this->config[$srcType]['type']).' given';
                }
                if (isset($this->config[$srcType]['url']) && !is_string($this->config[$srcType]['url'])) {
                    $this->errors[] = $srcType . '.url : should be a string, '.gettype($this->config[$srcType]['url']).' given';
                }
                if (isset($this->config[$srcType]['reference']) && !is_string($this->config[$srcType]['reference']) && !is_int($this->config[$srcType]['reference'])) {
                    $this->errors[] = $srcType . '.reference : should be a string or int, '.gettype($this->config[$srcType]['reference']).' given';
                }
                if (isset($this->config[$srcType]['reference']) && Preg::isMatch('{^\s*-}', (string) $this->config[$srcType]['reference'])) {
                    $this->errors[] = $srcType . '.reference : must not start with a "-", "'.$this->config[$srcType]['reference'].'" given';
                }
                if (isset($this->config[$srcType]['url']) && Preg::isMatch('{^\s*-}', (string) $this->config[$srcType]['url'])) {
                    $this->errors[] = $srcType . '.url : must not start with a "-", "'.$this->config[$srcType]['url'].'" given';
                }
            }
        }

        // TODO validate repositories
        // TODO validate package repositories' packages using this recursively

        $this->validateFlatArray('include-path');
        $this->validateArray('transport-options');

        // branch alias validation
        if (isset($this->config['extra']['branch-alias'])) {
            if (!is_array($this->config['extra']['branch-alias'])) {
                $this->errors[] = 'extra.branch-alias : must be an array of versions => aliases';
            } else {
                foreach ($this->config['extra']['branch-alias'] as $sourceBranch => $targetBranch) {
                    if (!is_string($targetBranch)) {
                        $this->warnings[] = 'extra.branch-alias.'.$sourceBranch.' : the target branch ('.json_encode($targetBranch).') must be a string, "'.gettype($targetBranch).'" received.';
                        unset($this->config['extra']['branch-alias'][$sourceBranch]);

                        continue;
                    }

                    // ensure it is an alias to a -dev package
                    if ('-dev' !== substr($targetBranch, -4)) {
                        $this->warnings[] = 'extra.branch-alias.'.$sourceBranch.' : the target branch ('.$targetBranch.') must end in -dev';
                        unset($this->config['extra']['branch-alias'][$sourceBranch]);

                        continue;
                    }

                    // normalize without -dev and ensure it's a numeric branch that is parseable
                    $validatedTargetBranch = $this->versionParser->normalizeBranch(substr($targetBranch, 0, -4));
                    if ('-dev' !== substr($validatedTargetBranch, -4)) {
                        $this->warnings[] = 'extra.branch-alias.'.$sourceBranch.' : the target branch ('.$targetBranch.') must be a parseable number like 2.0-dev';
                        unset($this->config['extra']['branch-alias'][$sourceBranch]);

                        continue;
                    }

                    // If using numeric aliases ensure the alias is a valid subversion
                    if (($sourcePrefix = $this->versionParser->parseNumericAliasPrefix($sourceBranch))
                        && ($targetPrefix = $this->versionParser->parseNumericAliasPrefix($targetBranch))
                        && (stripos($targetPrefix, $sourcePrefix) !== 0)
                    ) {
                        $this->warnings[] = 'extra.branch-alias.'.$sourceBranch.' : the target branch ('.$targetBranch.') is not a valid numeric alias for this version';
                        unset($this->config['extra']['branch-alias'][$sourceBranch]);
                    }
                }
            }
        }

        if ($this->errors) {
            throw new InvalidPackageException($this->errors, $this->warnings, $config);
        }

        $package = $this->loader->load($this->config, $class);
        $this->config = array();

        return $package;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param string $name
     * @param bool   $isLink
     *
     * @return string|null
     */
    public static function hasPackageNamingError(string $name, bool $isLink = false): ?string
    {
        if (PlatformRepository::isPlatformPackage($name)) {
            return null;
        }

        if (!Preg::isMatch('{^[a-z0-9](?:[_.-]?[a-z0-9]++)*+/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]++)*+$}iD', $name)) {
            return $name.' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$".';
        }

        $reservedNames = array('nul', 'con', 'prn', 'aux', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9');
        $bits = explode('/', strtolower($name));
        if (in_array($bits[0], $reservedNames, true) || in_array($bits[1], $reservedNames, true)) {
            return $name.' is reserved, package and vendor names can not match any of: '.implode(', ', $reservedNames).'.';
        }

        if (Preg::isMatch('{\.json$}', $name)) {
            return $name.' is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.';
        }

        if (Preg::isMatch('{[A-Z]}', $name)) {
            if ($isLink) {
                return $name.' is invalid, it should not contain uppercase characters. Please use '.strtolower($name).' instead.';
            }

            $suggestName = Preg::replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $suggestName = strtolower($suggestName);

            return $name.' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.';
        }

        return null;
    }

    /**
     * @param string $property
     * @param string $regex
     * @param bool   $mandatory
     *
     * @return bool
     *
     * @phpstan-param non-empty-string $property
     * @phpstan-param non-empty-string $regex
     */
    private function validateRegex(string $property, string $regex, bool $mandatory = false): bool
    {
        if (!$this->validateString($property, $mandatory)) {
            return false;
        }

        if (!Preg::isMatch('{^'.$regex.'$}u', $this->config[$property])) {
            $message = $property.' : invalid value ('.$this->config[$property].'), must match '.$regex;
            if ($mandatory) {
                $this->errors[] = $message;
            } else {
                $this->warnings[] = $message;
            }
            unset($this->config[$property]);

            return false;
        }

        return true;
    }

    /**
     * @param string $property
     * @param bool   $mandatory
     *
     * @return bool
     *
     * @phpstan-param non-empty-string $property
     */
    private function validateString(string $property, bool $mandatory = false): bool
    {
        if (isset($this->config[$property]) && !is_string($this->config[$property])) {
            $this->errors[] = $property.' : should be a string, '.gettype($this->config[$property]).' given';
            unset($this->config[$property]);

            return false;
        }

        if (!isset($this->config[$property]) || trim($this->config[$property]) === '') {
            if ($mandatory) {
                $this->errors[] = $property.' : must be present';
            }
            unset($this->config[$property]);

            return false;
        }

        return true;
    }

    /**
     * @param string $property
     * @param bool   $mandatory
     *
     * @return bool
     *
     * @phpstan-param non-empty-string $property
     */
    private function validateArray(string $property, bool $mandatory = false): bool
    {
        if (isset($this->config[$property]) && !is_array($this->config[$property])) {
            $this->errors[] = $property.' : should be an array, '.gettype($this->config[$property]).' given';
            unset($this->config[$property]);

            return false;
        }

        if (!isset($this->config[$property]) || !count($this->config[$property])) {
            if ($mandatory) {
                $this->errors[] = $property.' : must be present and contain at least one element';
            }
            unset($this->config[$property]);

            return false;
        }

        return true;
    }

    /**
     * @param string      $property
     * @param string|null $regex
     * @param bool        $mandatory
     *
     * @return bool
     *
     * @phpstan-param non-empty-string      $property
     * @phpstan-param non-empty-string|null $regex
     */
    private function validateFlatArray(string $property, ?string $regex = null, bool $mandatory = false): bool
    {
        if (!$this->validateArray($property, $mandatory)) {
            return false;
        }

        $pass = true;
        foreach ($this->config[$property] as $key => $value) {
            if (!is_string($value) && !is_numeric($value)) {
                $this->errors[] = $property.'.'.$key.' : must be a string or int, '.gettype($value).' given';
                unset($this->config[$property][$key]);
                $pass = false;

                continue;
            }

            if ($regex && !Preg::isMatch('{^'.$regex.'$}u', $value)) {
                $this->warnings[] = $property.'.'.$key.' : invalid value ('.$value.'), must match '.$regex;
                unset($this->config[$property][$key]);
                $pass = false;
            }
        }

        return $pass;
    }

    /**
     * @param string $property
     * @param bool $mandatory
     *
     * @return bool
     *
     * @phpstan-param non-empty-string $property
     */
    private function validateUrl(string $property, bool $mandatory = false): bool
    {
        if (!$this->validateString($property, $mandatory)) {
            return false;
        }

        if (!$this->filterUrl($this->config[$property])) {
            $this->warnings[] = $property.' : invalid value ('.$this->config[$property].'), must be an http/https URL';
            unset($this->config[$property]);

            return false;
        }

        return true;
    }

    /**
     * @param mixed    $value
     * @param string[] $schemes
     *
     * @return bool
     */
    private function filterUrl($value, array $schemes = array('http', 'https')): bool
    {
        if ($value === '') {
            return true;
        }

        $bits = parse_url($value);
        if (empty($bits['scheme']) || empty($bits['host'])) {
            return false;
        }

        if (!in_array($bits['scheme'], $schemes, true)) {
            return false;
        }

        return true;
    }
}
