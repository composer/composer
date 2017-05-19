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

namespace Composer\Util;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Json\JsonValidationException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Spdx\SpdxLicenses;

/**
 * Validates a composer configuration.
 *
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConfigValidator
{
    private $io;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * Validates the config, and returns the result.
     *
     * @param string $file                       The path to the file
     * @param int    $arrayLoaderValidationFlags Flags for ArrayLoader validation
     *
     * @return array a triple containing the errors, publishable errors, and warnings
     */
    public function validate($file, $arrayLoaderValidationFlags = ValidatingArrayLoader::CHECK_ALL)
    {
        $errors = array();
        $publishErrors = array();
        $warnings = array();

        // validate json schema
        $laxValid = false;
        try {
            $json = new JsonFile($file, null, $this->io);
            $manifest = $json->read();

            $json->validateSchema(JsonFile::LAX_SCHEMA);
            $laxValid = true;
            $json->validateSchema();
        } catch (JsonValidationException $e) {
            foreach ($e->getErrors() as $message) {
                if ($laxValid) {
                    $publishErrors[] = $message;
                } else {
                    $errors[] = $message;
                }
            }
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();

            return array($errors, $publishErrors, $warnings);
        }

        // validate actual data
        if (!empty($manifest['license'])) {
            // strip proprietary since it's not a valid SPDX identifier, but is accepted by composer
            if (is_array($manifest['license'])) {
                foreach ($manifest['license'] as $key => $license) {
                    if ('proprietary' === $license) {
                        unset($manifest['license'][$key]);
                    }
                }
            }

            $licenseValidator = new SpdxLicenses();
            if ('proprietary' !== $manifest['license'] && array() !== $manifest['license'] && !$licenseValidator->validate($manifest['license']) && $licenseValidator->validate(trim($manifest['license']))) {
                $warnings[] = sprintf(
                    'License %s must not contain extra spaces, make sure to trim it.',
                    json_encode($manifest['license'])
                );
            } elseif ('proprietary' !== $manifest['license'] && array() !== $manifest['license'] && !$licenseValidator->validate($manifest['license'])) {
                $warnings[] = sprintf(
                    'License %s is not a valid SPDX license identifier, see https://spdx.org/licenses/ if you use an open license.'
                    . PHP_EOL .
                    'If the software is closed-source, you may use "proprietary" as license.',
                    json_encode($manifest['license'])
                );
            }
        } else {
            $warnings[] = 'No license specified, it is recommended to do so. For closed-source software you may use "proprietary" as license.';
        }

        if (isset($manifest['version'])) {
            $warnings[] = 'The version field is present, it is recommended to leave it out if the package is published on Packagist.';
        }

        if (!empty($manifest['name']) && preg_match('{[A-Z]}', $manifest['name'])) {
            $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $manifest['name']);
            $suggestName = strtolower($suggestName);

            $publishErrors[] = sprintf(
                'Name "%s" does not match the best practice (e.g. lower-cased/with-dashes). We suggest using "%s" instead. As such you will not be able to submit it to Packagist.',
                $manifest['name'],
                $suggestName
            );
        }

        if (!empty($manifest['type']) && $manifest['type'] == 'composer-installer') {
            $warnings[] = "The package type 'composer-installer' is deprecated. Please distribute your custom installers as plugins from now on. See https://getcomposer.org/doc/articles/plugins.md for plugin documentation.";
        }

        // check for require-dev overrides
        if (isset($manifest['require']) && isset($manifest['require-dev'])) {
            $requireOverrides = array_intersect_key($manifest['require'], $manifest['require-dev']);

            if (!empty($requireOverrides)) {
                $plural = (count($requireOverrides) > 1) ? 'are' : 'is';
                $warnings[] = implode(', ', array_keys($requireOverrides)). " {$plural} required both in require and require-dev, this can lead to unexpected behavior";
            }
        }

        // check for commit references
        $require = isset($manifest['require']) ? $manifest['require'] : array();
        $requireDev = isset($manifest['require-dev']) ? $manifest['require-dev'] : array();
        $packages = array_merge($require, $requireDev);
        foreach ($packages as $package => $version) {
            if (preg_match('/#/', $version) === 1) {
                $warnings[] = sprintf(
                    'The package "%s" is pointing to a commit-ref, this is bad practice and can cause unforeseen issues.',
                    $package
                );
            }
        }

        // check for empty psr-0/psr-4 namespace prefixes
        if (isset($manifest['autoload']['psr-0'][''])) {
            $warnings[] = "Defining autoload.psr-0 with an empty namespace prefix is a bad idea for performance";
        }
        if (isset($manifest['autoload']['psr-4'][''])) {
            $warnings[] = "Defining autoload.psr-4 with an empty namespace prefix is a bad idea for performance";
        }

        try {
            $loader = new ValidatingArrayLoader(new ArrayLoader(), true, null, $arrayLoaderValidationFlags);
            if (!isset($manifest['version'])) {
                $manifest['version'] = '1.0.0';
            }
            if (!isset($manifest['name'])) {
                $manifest['name'] = 'dummy/dummy';
            }
            $loader->load($manifest);
        } catch (InvalidPackageException $e) {
            $errors = array_merge($errors, $e->getErrors());
        }

        $warnings = array_merge($warnings, $loader->getWarnings());

        return array($errors, $publishErrors, $warnings);
    }
}
