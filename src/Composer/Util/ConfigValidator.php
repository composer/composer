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
use Composer\Json\JsonValidationException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

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
     * @param string $file The path to the file
     *
     * @return array a triple containing the errors, publishable errors, and warnings
     */
    public function validate($file)
    {
        $errors = array();
        $publishErrors = array();
        $warnings = array();

        // validate json schema
        $laxValid = false;
        $valid = false;
        try {
            $json = new JsonFile($file, new RemoteFilesystem($this->io));
            $manifest = $json->read();

            $json->validateSchema(JsonFile::LAX_SCHEMA);
            $laxValid = true;
            $json->validateSchema();
            $valid = true;
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
            $licenseValidator = new SpdxLicenseIdentifier();
            if (!$licenseValidator->validate($manifest['license'])) {
                $warnings[] = sprintf(
                    'License %s is not a valid SPDX license identifier, see http://www.spdx.org/licenses/ if you use an open license',
                    json_encode($manifest['license'])
                );
            }
        } else {
            $warnings[] = 'No license specified, it is recommended to do so';
        }

        if (!empty($manifest['name']) && preg_match('{[A-Z]}', $manifest['name'])) {
            $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $manifest['name']);
            $suggestName = strtolower($suggestName);

            $warnings[] = sprintf(
                'Name "%s" does not match the best practice (e.g. lower-cased/with-dashes). We suggest using "%s" instead. As such you will not be able to submit it to Packagist.',
                $manifest['name'],
                $suggestName
            );
        }

        // TODO validate package repositories' packages using the same technique as below
        try {
            $loader = new ValidatingArrayLoader(new ArrayLoader(), false);
            if (!isset($manifest['version'])) {
                $manifest['version'] = '1.0.0';
            }
            if (!isset($manifest['name'])) {
                $manifest['name'] = 'dummy/dummy';
            }
            $loader->load($manifest);
        } catch (\Exception $e) {
            $errors = array_merge($errors, explode("\n", $e->getMessage()));
        }

        return array($errors, $publishErrors, $warnings);
    }
}
