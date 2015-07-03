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

/**
 * Supports composer array and SPDX tag notation for disjunctive/conjunctive
 * licenses.
 *
 * @author Tom Klingenberg <tklingenberg@lastflood.net>
 */
class SpdxLicense
{
    /** @var array */
    private $licenses;

    /** @var array */
    private $exceptions;

    public function __construct()
    {
        $this->loadLicenses();
        $this->loadExceptions();
    }

    /**
     * Returns license metadata by license identifier.
     *
     * @param string $identifier
     *
     * @return array|null
     */
    public function getLicenseByIdentifier($identifier)
    {
        if (!isset($this->licenses[$identifier])) {
            return;
        }

        $license = $this->licenses[$identifier];
        $license[] = 'http://spdx.org/licenses/' . $identifier . '.html#licenseText';

        return $license;
    }

    /**
     * Returns license exception metadata by license exception identifier.
     *
     * @param string $identifier
     *
     * @return array|null
     */
    public function getExceptionByIdentifier($identifier)
    {
        if (!isset($this->exceptions[$identifier])) {
            return;
        }

        $license = $this->exceptions[$identifier];
        $license[] = 'http://spdx.org/licenses/' . $identifier . '.html#licenseExceptionText';

        return $license;
    }

    /**
     * Returns the short identifier of a license (exception) by full name.
     *
     * @param string $name
     *
     * @return string
     */
    public function getIdentifierByName($name)
    {
        foreach ($this->licenses as $identifier => $licenseData) {
            if ($licenseData[0] === $name) { // key 0 = fullname
                return $identifier;
            }
        }

        foreach ($this->exceptions as $identifier => $licenseData) {
            if ($licenseData[0] === $name) { // key 0 = fullname
                return $identifier;
            }
        }
    }

    /**
     * Returns the OSI Approved status for a license by identifier.
     *
     * @param string $identifier
     *
     * @return bool
     */
    public function isOsiApprovedByIdentifier($identifier)
    {
        return $this->licenses[$identifier][1]; // key 1 = osi approved
    }

    /**
     * Check, if the identifier for a license is valid.
     *
     * @param string $identifier
     *
     * @return bool
     */
    private function isValidLicenseIdentifier($identifier)
    {
        $identifiers = array_keys($this->licenses);

        return in_array($identifier, $identifiers);
    }

    /**
     * Check, if the identifier for a exception is valid.
     *
     * @param string $identifier
     *
     * @return bool
     */
    private function isValidExceptionIdentifier($identifier)
    {
        $identifiers = array_keys($this->exceptions);

        return in_array($identifier, $identifiers);
    }

    /**
     * @param array|string $license
     *
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function validate($license)
    {
        if (is_array($license)) {
            $count = count($license);
            if ($count !== count(array_filter($license, 'is_string'))) {
                throw new \InvalidArgumentException('Array of strings expected.');
            }
            $license = $count > 1  ? '('.implode(' OR ', $license).')' : (string) reset($license);
        }

        if (!is_string($license)) {
            throw new \InvalidArgumentException(sprintf(
                'Array or String expected, %s given.',
                gettype($license)
            ));
        }

        return $this->isValidLicenseString($license);
    }

    /**
     * @return array
     */
    private function loadLicenses()
    {
        if (is_array($this->licenses)) {
            return $this->licenses;
        }

        $jsonFile = file_get_contents(__DIR__ . '/../../../res/spdx-licenses.json');
        $this->licenses = json_decode($jsonFile, true);

        return $this->licenses;
    }

    /**
     * @return array
     */
    private function loadExceptions()
    {
        if (is_array($this->exceptions)) {
            return $this->exceptions;
        }

        $jsonFile = file_get_contents(__DIR__ . '/../../../res/spdx-exceptions.json');
        $this->exceptions = json_decode($jsonFile, true);

        return $this->exceptions;
    }

    /**
     * @param string $license
     *
     * @return bool
     *
     * @throws \RuntimeException
     */
    private function isValidLicenseString($license)
    {
        $licenses = array_map('preg_quote', array_keys($this->licenses));
        sort($licenses);
        $licenses = array_reverse($licenses);
        $licenses = implode('|', $licenses);

        $exceptions = array_map('preg_quote', array_keys($this->exceptions));
        sort($exceptions);
        $exceptions = array_reverse($exceptions);
        $exceptions = implode('|', $exceptions);

        $regex = "{
            (?(DEFINE)
                # idstring: 1*( ALPHA / DIGIT / - / . )
                (?<idstring>[\pL\pN\-\.]{1,})

                # license-id: taken from list
                (?<licenseid>${licenses})

                # license-exception-id: taken from list
                (?<licenseexceptionid>${exceptions})

                # license-ref: [DocumentRef-1*(idstring):]LicenseRef-1*(idstring)
                (?<licenseref>(?:DocumentRef-(?&idstring):)?LicenseRef-(?&idstring))

                # simple-expresssion: license-id / license-id+ / license-ref
                (?<simple_expression>(?&licenseid)\+? | (?&licenseid) | (?&licenseref))

                # compound expression: 1*(
                #   simple-expression /
                #   simple-expression WITH license-exception-id /
                #   compound-expression AND compound-expression /
                #   compound-expression OR compound-expression
                # ) / ( compound-expression ) )
                (?<compound_head>
                    (?&simple_expression) ( \s+ (?:with|WITH) \s+ (?&licenseexceptionid))?
                        | \( \s* (?&compound_expression) \s*\)
                )
                (?<compound_expression>
                    (?&compound_head) (?: \s+ (?:and|AND|or|OR) \s+ (?&compound_expression))?
                )

                # license-expression: 1*1(simple-expression / compound-expression)
                (?<license_expression>NONE | NOASSERTION | (?&compound_expression) | (?&simple_expression))
            ) # end of define

            ^(?&license_expression)$
        }x";

        $match = preg_match($regex, $license);

        if (0 === $match) {
            return false;
        }

        if (false === $match) {
            throw new \RuntimeException('Regex failed to compile/run.');
        }

        return true;
    }
}
