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
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ValidatingArrayLoader implements LoaderInterface
{
    private $loader;
    private $versionParser;
    private $ignoreErrors;
    private $errors = array();

    public function __construct(LoaderInterface $loader, $ignoreErrors = true, VersionParser $parser = null)
    {
        $this->loader = $loader;
        $this->ignoreErrors = $ignoreErrors;
        if (!$parser) {
            $parser = new VersionParser();
        }
        $this->versionParser = $parser;
    }

    public function load(array $config)
    {
        $this->config = $config;

        $this->validateRegex('name', '[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*', true);

        if (!empty($config['version'])) {
            try {
                $this->versionParser->normalize($config['version']);
            } catch (\Exception $e) {
                unset($this->config['version']);
                $this->errors[] = 'version : invalid value ('.$config['version'].'): '.$e->getMessage();
            }
        }

        $this->validateRegex('type', '[a-z0-9-]+');
        $this->validateString('target-dir');
        $this->validateArray('extra');
        $this->validateFlatArray('bin');
        $this->validateArray('scripts'); // TODO validate event names & listener syntax
        $this->validateString('description');
        $this->validateUrl('homepage');
        $this->validateFlatArray('keywords', '[A-Za-z0-9 -]+');

        if (isset($config['license'])) {
            if (is_string($config['license'])) {
                $this->validateRegex('license', '[A-Za-z0-9+. ()-]+');
            } else {
                $this->validateFlatArray('license', '[A-Za-z0-9+. ()-]+');
            }
        }

        $this->validateString('time');
        if (!empty($this->config['time'])) {
            try {
                $date = new \DateTime($config['time']);
            } catch (\Exception $e) {
                $this->errors[] = 'time : invalid value ('.$this->config['time'].'): '.$e->getMessage();
                unset($this->config['time']);
            }
        }

        $this->validateArray('authors');
        if (!empty($this->config['authors'])) {
            foreach ($this->config['authors'] as $key => $author) {
                if (isset($author['homepage']) && !$this->filterUrl($author['homepage'])) {
                    $this->errors[] = 'authors.'.$key.'.homepage : invalid value, must be a valid http/https URL';
                    unset($this->config['authors'][$key]['homepage']);
                }
                if (isset($author['email']) && !filter_var($author['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->errors[] = 'authors.'.$key.'.email : invalid value, must be a valid email address';
                    unset($this->config['authors'][$key]['email']);
                }
                if (isset($author['name']) && !is_string($author['name'])) {
                    $this->errors[] = 'authors.'.$key.'.name : invalid value, must be a string';
                    unset($this->config['authors'][$key]['name']);
                }
                if (isset($author['role']) && !is_string($author['role'])) {
                    $this->errors[] = 'authors.'.$key.'.role : invalid value, must be a string';
                    unset($this->config['authors'][$key]['role']);
                }
            }
            if (empty($this->config['authors'])) {
                unset($this->config['authors']);
            }
        }

        $this->validateArray('support');
        if (!empty($this->config['support'])) {
            if (isset($this->config['support']['email']) && !filter_var($this->config['support']['email'], FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = 'support.email : invalid value, must be a valid email address';
                unset($this->config['support']['email']);
            }

            if (isset($this->config['support']['irc'])
                && (!filter_var($this->config['support']['irc'], FILTER_VALIDATE_URL) || !preg_match('{^irc://}iu', $this->config['support']['irc']))
            ) {
                $this->errors[] = 'support.irc : invalid value, must be ';
                unset($this->config['support']['irc']);
            }

            foreach (array('issues', 'forum', 'wiki', 'source') as $key) {
                if (isset($this->config['support'][$key]) && !$this->filterUrl($this->config['support'][$key])) {
                    $this->errors[] = 'support.'.$key.' : invalid value, must be a valid http/https URL';
                    unset($this->config['support'][$key]);
                }
            }
            if (empty($this->config['support'])) {
                unset($this->config['support']);
            }
        }

        // TODO validate require/require-dev/replace/provide
        // TODO validate suggest
        // TODO validate autoload
        // TODO validate minimum-stability

        // TODO validate dist
        // TODO validate source

        // TODO validate repositories

        $this->validateFlatArray('include-path');

        // branch alias validation
        if (isset($this->config['extra']['branch-alias'])) {
            if (!is_array($this->config['extra']['branch-alias'])) {
                $this->errors[] = 'extra.branch-alias : must be an array of versions => aliases';
            } else {
                foreach ($this->config['extra']['branch-alias'] as $sourceBranch => $targetBranch) {
                    // ensure it is an alias to a -dev package
                    if ('-dev' !== substr($targetBranch, -4)) {
                        $this->errors[] = 'extra.branch-alias.'.$sourceBranch.' : the target branch ('.$targetBranch.') must end in -dev';
                        unset($this->config['extra']['branch-alias'][$sourceBranch]);

                        continue;
                    }

                    // normalize without -dev and ensure it's a numeric branch that is parseable
                    $validatedTargetBranch = $this->versionParser->normalizeBranch(substr($targetBranch, 0, -4));
                    if ('-dev' !== substr($validatedTargetBranch, -4)) {
                        $this->errors[] = 'extra.branch-alias.'.$sourceBranch.' : the target branch ('.$targetBranch.') must be a parseable number like 2.0-dev';
                        unset($this->config['extra']['branch-alias'][$sourceBranch]);
                    }
                }
            }
        }

        if ($this->errors && !$this->ignoreErrors) {
            throw new \Exception(implode("\n", $this->errors));
        }

        $package = $this->loader->load($this->config);
        $this->errors = array();
        unset($this->config);

        return $package;
    }

    private function validateRegex($property, $regex, $mandatory = false)
    {
        if (!$this->validateString($property, $mandatory)) {
            return false;
        }

        if (!preg_match('{^'.$regex.'$}u', $this->config[$property])) {
            $this->errors[] = $property.' : invalid value, must match '.$regex;
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
                $this->errors[] = $property.'.'.$key.' : invalid value, must match '.$regex;
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
            $this->errors[] = $property.' : invalid value, must be a valid http/https URL';
            unset($this->config[$property]);

            return false;
        }
    }

    private function filterUrl($value)
    {
        return filter_var($value, FILTER_VALIDATE_URL) && preg_match('{^https?://}iu', $value);
    }
}
