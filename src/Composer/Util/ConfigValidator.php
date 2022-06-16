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

namespace Composer\Util;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Json\JsonValidationException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Spdx\SpdxLicenses;
use Seld\JsonLint\DuplicateKeyException;
use Seld\JsonLint\JsonParser;

/**
 * Validates a composer configuration.
 *
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConfigValidator
{
    public const CHECK_VERSION = 1;

    /** @var IOInterface */
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
     * @param int    $flags                      Flags for validation
     *
     * @return array{list<string>, list<string>, list<string>} a triple containing the errors, publishable errors, and warnings
     */
    public function validate(string $file, int $arrayLoaderValidationFlags = ValidatingArrayLoader::CHECK_ALL, int $flags = self::CHECK_VERSION): array
    {
        $errors = [];
        $publishErrors = [];
        $warnings = [];

        // validate json schema
        $laxValid = false;
        $manifest = null;
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

            return [$errors, $publishErrors, $warnings];
        }

        if (is_array($manifest)) {
            $jsonParser = new JsonParser();
            try {
                $jsonParser->parse((string) file_get_contents($file), JsonParser::DETECT_KEY_CONFLICTS);
            } catch (DuplicateKeyException $e) {
                $details = $e->getDetails();
                $warnings[] = 'Key '.$details['key'].' is a duplicate in '.$file.' at line '.$details['line'];
            }
        }

        // validate actual data
        if (empty($manifest['license'])) {
            $warnings[] = 'No license specified, it is recommended to do so. For closed-source software you may use "proprietary" as license.';
        } else {
            $licenses = (array) $manifest['license'];

            // strip proprietary since it's not a valid SPDX identifier, but is accepted by composer
            foreach ($licenses as $key => $license) {
                if ('proprietary' === $license) {
                    unset($licenses[$key]);
                }
            }

            $licenseValidator = new SpdxLicenses();
            foreach ($licenses as $license) {
                $spdxLicense = $licenseValidator->getLicenseByIdentifier($license);
                if ($spdxLicense && $spdxLicense[3]) {
                    if (Preg::isMatch('{^[AL]?GPL-[123](\.[01])?\+$}i', $license)) {
                        $warnings[] = sprintf(
                            'License "%s" is a deprecated SPDX license identifier, use "'.str_replace('+', '', $license).'-or-later" instead',
                            $license
                        );
                    } elseif (Preg::isMatch('{^[AL]?GPL-[123](\.[01])?$}i', $license)) {
                        $warnings[] = sprintf(
                            'License "%s" is a deprecated SPDX license identifier, use "'.$license.'-only" or "'.$license.'-or-later" instead',
                            $license
                        );
                    } else {
                        $warnings[] = sprintf(
                            'License "%s" is a deprecated SPDX license identifier, see https://spdx.org/licenses/',
                            $license
                        );
                    }
                }
            }
        }

        if (($flags & self::CHECK_VERSION) && isset($manifest['version'])) {
            $warnings[] = 'The version field is present, it is recommended to leave it out if the package is published on Packagist.';
        }

        if (!empty($manifest['name']) && Preg::isMatch('{[A-Z]}', $manifest['name'])) {
            $suggestName = Preg::replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $manifest['name']);
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
        if (isset($manifest['require'], $manifest['require-dev'])) {
            $requireOverrides = array_intersect_key($manifest['require'], $manifest['require-dev']);

            if (!empty($requireOverrides)) {
                $plural = (count($requireOverrides) > 1) ? 'are' : 'is';
                $warnings[] = implode(', ', array_keys($requireOverrides)). " {$plural} required both in require and require-dev, this can lead to unexpected behavior";
            }
        }

        // check for meaningless provide/replace satisfying requirements
        foreach (['provide', 'replace'] as $linkType) {
            if (isset($manifest[$linkType])) {
                foreach (['require', 'require-dev'] as $requireType) {
                    if (isset($manifest[$requireType])) {
                        foreach ($manifest[$linkType] as $provide => $constraint) {
                            if (isset($manifest[$requireType][$provide])) {
                                $warnings[] = 'The package ' . $provide . ' in '.$requireType.' is also listed in '.$linkType.' which satisfies the requirement. Remove it from '.$linkType.' if you wish to install it.';
                            }
                        }
                    }
                }
            }
        }

        // check for commit references
        $require = $manifest['require'] ?? [];
        $requireDev = $manifest['require-dev'] ?? [];
        $packages = array_merge($require, $requireDev);
        foreach ($packages as $package => $version) {
            if (Preg::isMatch('/#/', $version)) {
                $warnings[] = sprintf(
                    'The package "%s" is pointing to a commit-ref, this is bad practice and can cause unforeseen issues.',
                    $package
                );
            }
        }

        // report scripts-descriptions for non-existent scripts
        $scriptsDescriptions = $manifest['scripts-descriptions'] ?? [];
        $scripts = $manifest['scripts'] ?? [];
        foreach ($scriptsDescriptions as $scriptName => $scriptDescription) {
            if (!array_key_exists($scriptName, $scripts)) {
                $warnings[] = sprintf(
                    'Description for non-existent script "%s" found in "scripts-descriptions"',
                    $scriptName
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

        $loader = new ValidatingArrayLoader(new ArrayLoader(), true, null, $arrayLoaderValidationFlags);
        try {
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

        return [$errors, $publishErrors, $warnings];
    }
}
