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
 * SPDX License Identifier
 *
 * Supports composer array and SPDX tag notation for disjunctive/conjunctive
 * licenses.
 *
 * @author Tom Klingenberg <tklingenberg@lastflood.net>
 */
class SPDXLicenseIdentifier
{
    /**
     * @var array
     */
    private $identifiers;
    /**
     * @var array|string
     */
    private $license;

    /**
     * @param string|string[] $license
     */
    public function __construct($license)
    {
        $this->initIdentifiers();
        $this->setLicense($license);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getLicense();
    }

    /**
     * @return string
     */
    public function getLicense()
    {
        return $this->license;
    }

    /**
     * @param array|string $license
     *
     * @throws \InvalidArgumentException
     */
    public function setLicense($license)
    {
        if (is_array($license)) {
            $license = $this->getLicenseFromArray($license);
        }
        if (!is_string($license)) {
            throw new \InvalidArgumentException(sprintf(
                'Array or String expected, %s given.', gettype($license)
            ));
        }
        if (!$this->isValidLicenseString($license)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid license: "%s"', $license
            ));
        }
        $this->license = $license;
    }

    /**
     * @param array $licenses
     *
     * @return string
     */
    private function getLicenseFromArray(array $licenses)
    {
        $buffer = '';
        foreach ($licenses as $license) {
            $buffer .= ($buffer ? ' or ' : '(') . (string)$license;
        }
        $buffer .= $buffer ? ')' : '';

        return $buffer;
    }

    /**
     * init SPDX identifiers
     */
    private function initIdentifiers()
    {
        $jsonFile = __DIR__ . '/../../../res/spdx-identifier.json';
        $this->identifiers = $this->arrayFromJSONFile($jsonFile);
    }

    /**
     * @param string $file
     *
     * @return array
     * @throws \RuntimeException
     */
    private function arrayFromJSONFile($file)
    {
        $data = json_decode(file_get_contents($file));
        if (!$data || !is_array($data)) {
            throw new \RuntimeException(sprintf('Not a json array in file "%s"', $file));
        }

        return $data;
    }

    /**
     * @param string $identifier
     *
     * @return bool
     */
    private function isValidLicenseIdentifier($identifier)
    {
        return in_array($identifier, $this->identifiers);
    }

    /**
     * @param string $license
     *
     * @return bool
     * @throws \RuntimeException
     */
    private function isValidLicenseString($license)
    {
        $tokens = array(
            'po' => '\(',
            'pc' => '\)',
            'op' => '(?:or|and)',
            'lix' => '(?:NONE|NOASSERTION)',
            'lir' => 'LicenseRef-\d+',
            'lic' => '[-+_.a-zA-Z0-9]{3,}',
            'ws' => '\s+',
            '_' => '.',
        );
        $next = function () use ($license, $tokens)
        {
            static $offset = 0;
            if ($offset >= strlen($license)) {
                return null;
            }
            foreach ($tokens as $name => $token) {
                if (false === $r = preg_match("~$token~", $license, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                    throw new \RuntimeException('Pattern for token %s failed (regex error).', $name);
                }
                if ($r === 0) {
                    continue;
                }
                if ($matches[0][1] !== $offset) {
                    continue;
                }
                $offset += strlen($matches[0][0]);

                return array($name, $matches[0][0]);
            }
            throw new \RuntimeException('At least the last pattern needs to match, but it did not (dot-match-all is missing?).');
        };
        $open = 0;
        $require = 1;
        $lastop = null;
        while (list ($token, $string) = $next()) {
            switch ($token) {
                case 'po':
                    if ($open || !$require) {
                        return false;
                    }
                    $open = 1;
                    break;
                case 'pc':
                    if ($open !== 1 || $require || !$lastop) {
                        return false;
                    }
                    $open = 2;
                    break;
                case 'op':
                    if ($require || !$open) {
                        return false;
                    }
                    $lastop || $lastop = $string;
                    if ($lastop !== $string) {
                        return false;
                    }
                    $require = 1;
                    break;
                case 'lix':
                    if ($open) {
                        return false;
                    }
                    goto lir;
                case 'lic':
                    if (!$this->isValidLicenseIdentifier($string)) {
                        return false;
                    }
                    // Fall-through intended
                case 'lir':
                    lir:
                    if (!$require) {
                        return false;
                    }
                    $require = 0;
                    break;
                case 'ws':
                    break;
                case '_':
                    return false;
                default:
                    throw new \RuntimeException(sprintf('Unparsed token: %s.', print_r($token, true)));
            }
        }

        return !($open % 2 || $require);
    }
}
