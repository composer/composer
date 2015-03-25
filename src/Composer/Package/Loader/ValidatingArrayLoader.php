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

namespace Composer\Package\Loader;

use Composer\Package;
use Composer\Package\BasePackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Version\VersionParser;
use Composer\Repository\PlatformRepository;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ValidatingArrayLoader implements LoaderInterface
{
    const CHECK_ALL = 1;
    const CHECK_UNBOUND_CONSTRAINTS = 1;

    private $loader;
    private $versionParser;
    private $errors;
    private $warnings;
    private $config;
    private $strictName;
    private $flags;

    public function __construct(LoaderInterface $loader, $strictName = true, VersionParser $parser = null, $flags = 0)
    {
        $this->loader = $loader;
        $this->versionParser = $parser ?: new VersionParser();
        $this->strictName = $strictName;
        $this->flags = $flags;
    }

    public function load(array $config, $class = 'Composer\Package\CompletePackage')
    {
        $this->errors = array();
        $this->warnings = array();
        $this->config = $config;

        if ($this->strictName) {
            $this->validateRegex('name', '[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*', true);
        } else {
            $this->validateString('name', true);
        }

        if (!empty($this->config['version'])) {
            try {
                $this->versionParser->normalize($this->config['version']);
            } catch (\Exception $e) {
                $this->errors[] = 'version : invalid value ('.$this->config['version'].'): '.$e->getMessage();
                unset($this->config['version']);
            }
        }

        $this->validateRegex('type', '[A-Za-z0-9-]+');
        $this->validateString('target-dir');
        $this->validateArray('extra');
        $this->validateFlatArray('bin');
        $this->validateArray('scripts'); // TODO validate event names & listener syntax
        $this->validateString('description');
        $this->validateUrl('homepage');
        $this->validateFlatArray('keywords', '[A-Za-z0-9 ._-]+');

        if (isset($this->config['license'])) {
            if (is_string($this->config['license'])) {
                $this->validateRegex('license', '[A-Za-z0-9+. ()-]+');
            } else {
                $this->validateFlatArray('license', '[A-Za-z0-9+. ()-]+');
            }
        }

        $this->validateString('time');
        if (!empty($this->config['time'])) {
            try {
                $date = new \DateTime($this->config['time'], new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                $this->errors[] = 'time : invalid value ('.$this->config['time'].'): '.$e->getMessage();
                unset($this->config['time']);
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
            foreach (array('issues', 'forum', 'wiki', 'source', 'email', 'irc') as $key) {
                if (isset($this->config['support'][$key]) && !is_string($this->config['support'][$key])) {
                    $this->errors[] = 'support.'.$key.' : invalid value, must be a string';
                    unset($this->config['support'][$key]);
                }
            }

            if (isset($this->config['support']['email']) && !filter_var($this->config['support']['email'], FILTER_VALIDATE_EMAIL)) {
                $this->warnings[] = 'support.email : invalid value ('.$this->config['support']['email'].'), must be a valid email address';
                unset($this->config['support']['email']);
            }

            if (isset($this->config['support']['irc']) && !$this->filterUrl($this->config['support']['irc'], array('irc'))) {
                $this->warnings[] = 'support.irc : invalid value ('.$this->config['support']['irc'].'), must be a irc://<server>/<channel> URL';
                unset($this->config['support']['irc']);
            }

            foreach (array('issues', 'forum', 'wiki', 'source') as $key) {
                if (isset($this->config['support'][$key]) && !$this->filterUrl($this->config['support'][$key])) {
                    $this->warnings[] = 'support.'.$key.' : invalid value ('.$this->config['support'][$key].'), must be an http/https URL';
                    unset($this->config['support'][$key]);
                }
            }
            if (empty($this->config['support'])) {
                unset($this->config['support']);
            }
        }

        $unboundConstraint = new VersionConstraint('=', $this->versionParser->normalize('dev-master'));

        foreach (array_keys(BasePackage::$supportedLinkTypes) as $linkType) {
            if ($this->validateArray($linkType) && isset($this->config[$linkType])) {
                foreach ($this->config[$linkType] as $package => $constraint) {
                    if (!preg_match('{^[A-Za-z0-9_./-]+$}', $package)) {
                        $this->warnings[] = $linkType.'.'.$package.' : invalid key, package names must be strings containing only [A-Za-z0-9_./-]';
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
                            && !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $package)
                        ) {
                            $this->warnings[] = $linkType.'.'.$package.' : unbound version constraints ('.$constraint.') should be avoided';
                        }
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
            if (!isset(BasePackage::$stabilities[$this->config['minimum-stability']])) {
                $this->errors[] = 'minimum-stability : invalid value ('.$this->config['minimum-stability'].'), must be one of '.implode(', ', array_keys(BasePackage::$stabilities));
                unset($this->config['minimum-stability']);
            }
        }

        if ($this->validateArray('autoload') && !empty($this->config['autoload'])) {
            $types = array('psr-0', 'psr-4', 'classmap', 'files');
            foreach ($this->config['autoload'] as $type => $typeConfig) {
                if (!in_array($type, $types)) {
                    $this->errors[] = 'autoload : invalid value ('.$type.'), must be one of '.implode(', ', $types);
                    unset($this->config['autoload'][$type]);
                }
                if ($type === 'psr-4') {
                    foreach ($typeConfig as $namespace => $dirs) {
                        if ($namespace !== '' && '\\' !== substr($namespace, -1)) {
                            $this->errors[] = 'autoload.psr-4 : invalid value ('.$namespace.'), namespaces must end with a namespace separator, should be '.$namespace.'\\';
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

        // TODO validate dist
        // TODO validate source

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
        $this->config = null;

        return $package;
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    private function validateRegex($property, $regex, $mandatory = false)
    {
        if (!$this->validateString($property, $mandatory)) {
            return false;
        }

        if (!preg_match('{^'.$regex.'$}u', $this->config[$property])) {
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

    private function validateString($property, $mandatory = false)
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

    private function validateArray($property, $mandatory = false)
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

    private function validateFlatArray($property, $regex = null, $mandatory = false)
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

            if ($regex && !preg_match('{^'.$regex.'$}u', $value)) {
                $this->warnings[] = $property.'.'.$key.' : invalid value ('.$value.'), must match '.$regex;
                unset($this->config[$property][$key]);
                $pass = false;
            }
        }

        return $pass;
    }

    private function validateUrl($property, $mandatory = false)
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

    private function filterUrl($value, array $schemes = array('http', 'https'))
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
