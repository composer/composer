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

use Symfony\Component\Process\PhpProcess;

/**
 * @author Chris Smith <chris@cs278.org>
 */
final class TlsHelper
{
    private static $useOpensslParse;

    /**
     * Match hostname against a certificate.
     *
     * @param mixed  $certificate X.509 certificate
     * @param string $hostname    Hostname in the URL
     * @param string $cn          Set to the common name of the certificate iff match found
     *
     * @return bool
     */
    public static function checkCertificateHost($certificate, $hostname, &$cn = null)
    {
        $names = self::getCertificateNames($certificate);

        if (empty($names)) {
            return false;
        }

        $combinedNames = array_merge($names['san'], array($names['cn']));
        $hostname = strtolower($hostname);

        foreach ($combinedNames as $certName) {
            $matcher = self::certNameMatcher($certName);

            if ($matcher && $matcher($hostname)) {
                $cn = $names['cn'];

                return true;
            }
        }

        return false;
    }

    /**
     * Extract DNS names out of an X.509 certificate.
     *
     * @param mixed $certificate X.509 certificate
     *
     * @return array|null
     */
    public static function getCertificateNames($certificate)
    {
        if (is_array($certificate)) {
            $info = $certificate;
        } elseif (self::isOpensslParseSafe()) {
            $info = openssl_x509_parse($certificate, false);
        }

        if (!isset($info['subject']['commonName'])) {
            return;
        }

        $commonName = strtolower($info['subject']['commonName']);
        $subjectAltNames = array();

        if (isset($info['extensions']['subjectAltName'])) {
            $subjectAltNames = preg_split('{\s*,\s*}', $info['extensions']['subjectAltName']);
            $subjectAltNames = array_filter(array_map(function ($name) {
                if (0 === strpos($name, 'DNS:')) {
                    return strtolower(ltrim(substr($name, 4)));
                }
            }, $subjectAltNames));
            $subjectAltNames = array_values($subjectAltNames);
        }

        return array(
            'cn' => $commonName,
            'san' => $subjectAltNames,
        );
    }

    /**
     * Get the certificate pin.
     *
     * By Kevin McArthur of StormTide Digital Studios Inc.
     * @KevinSMcArthur / https://github.com/StormTide
     *
     * See http://tools.ietf.org/html/draft-ietf-websec-key-pinning-02
     *
     * This method was adapted from Sslurp.
     * https://github.com/EvanDotPro/Sslurp
     *
     * (c) Evan Coury <me@evancoury.com>
     *
     * For the full copyright and license information, please see below:
     *
     * Copyright (c) 2013, Evan Coury
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without modification,
     * are permitted provided that the following conditions are met:
     *
     *     * Redistributions of source code must retain the above copyright notice,
     *       this list of conditions and the following disclaimer.
     *
     *     * Redistributions in binary form must reproduce the above copyright notice,
     *       this list of conditions and the following disclaimer in the documentation
     *       and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
     * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
     * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     */
    public static function getCertificateFingerprint($certificate)
    {
        $pubkeydetails = openssl_pkey_get_details(openssl_get_publickey($certificate));
        $pubkeypem     = $pubkeydetails['key'];
        //Convert PEM to DER before SHA1'ing
        $start         = '-----BEGIN PUBLIC KEY-----';
        $end           = '-----END PUBLIC KEY-----';
        $pemtrim       = substr($pubkeypem, (strpos($pubkeypem, $start) + strlen($start)), (strlen($pubkeypem) - strpos($pubkeypem, $end)) * (-1));
        $der           = base64_decode($pemtrim);

        return sha1($der);
    }

    /**
     * Test if it is safe to use the PHP function openssl_x509_parse().
     *
     * This checks if OpenSSL extensions is vulnerable to remote code execution
     * via the exploit documented as CVE-2013-6420.
     *
     * @return bool
     */
    public static function isOpensslParseSafe()
    {
        if (null !== self::$useOpensslParse) {
            return self::$useOpensslParse;
        }

        if (PHP_VERSION_ID >= 50600) {
            return self::$useOpensslParse = true;
        }

        // Vulnerable:
        // PHP 5.3.0 - PHP 5.3.27
        // PHP 5.4.0 - PHP 5.4.22
        // PHP 5.5.0 - PHP 5.5.6
        if (
               (PHP_VERSION_ID < 50400 && PHP_VERSION_ID >= 50328)
            || (PHP_VERSION_ID < 50500 && PHP_VERSION_ID >= 50423)
            || (PHP_VERSION_ID < 50600 && PHP_VERSION_ID >= 50507)
        ) {
            // This version of PHP has the fix for CVE-2013-6420 applied.
            return self::$useOpensslParse = true;
        }

        if (Platform::isWindows()) {
            // Windows is probably insecure in this case.
            return self::$useOpensslParse = false;
        }

        $compareDistroVersionPrefix = function ($prefix, $fixedVersion) {
            $regex = '{^'.preg_quote($prefix).'([0-9]+)$}';

            if (preg_match($regex, PHP_VERSION, $m)) {
                return ((int) $m[1]) >= $fixedVersion;
            }

            return false;
        };

        // Hard coded list of PHP distributions with the fix backported.
        if (
            $compareDistroVersionPrefix('5.3.3-7+squeeze', 18) // Debian 6 (Squeeze)
            || $compareDistroVersionPrefix('5.4.4-14+deb7u', 7) // Debian 7 (Wheezy)
            || $compareDistroVersionPrefix('5.3.10-1ubuntu3.', 9) // Ubuntu 12.04 (Precise)
        ) {
            return self::$useOpensslParse = true;
        }

        // This is where things get crazy, because distros backport security
        // fixes the chances are on NIX systems the fix has been applied but
        // it's not possible to verify that from the PHP version.
        //
        // To verify exec a new PHP process and run the issue testcase with
        // known safe input that replicates the bug.

        // Based on testcase in https://github.com/php/php-src/commit/c1224573c773b6845e83505f717fbf820fc18415
        // changes in https://github.com/php/php-src/commit/76a7fd893b7d6101300cc656058704a73254d593
        $cert = 'LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0tCk1JSUVwRENDQTR5Z0F3SUJBZ0lKQUp6dThyNnU2ZUJjTUEwR0NTcUdTSWIzRFFFQkJRVUFNSUhETVFzd0NRWUQKVlFRR0V3SkVSVEVjTUJvR0ExVUVDQXdUVG05eVpISm9aV2x1TFZkbGMzUm1ZV3hsYmpFUU1BNEdBMVVFQnd3SApTOE9Ed3Jac2JqRVVNQklHQTFVRUNnd0xVMlZyZEdsdmJrVnBibk14SHpBZEJnTlZCQXNNRmsxaGJHbGphVzkxCmN5QkRaWEowSUZObFkzUnBiMjR4SVRBZkJnTlZCQU1NR0cxaGJHbGphVzkxY3k1elpXdDBhVzl1WldsdWN5NWsKWlRFcU1DZ0dDU3FHU0liM0RRRUpBUlliYzNSbFptRnVMbVZ6YzJWeVFITmxhM1JwYjI1bGFXNXpMbVJsTUhVWQpaREU1TnpBd01UQXhNREF3TURBd1dnQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBCkFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUEKQUFBQUFBQVhEVEUwTVRFeU9ERXhNemt6TlZvd2djTXhDekFKQmdOVkJBWVRBa1JGTVJ3d0dnWURWUVFJREJOTwpiM0prY21obGFXNHRWMlZ6ZEdaaGJHVnVNUkF3RGdZRFZRUUhEQWRMdzRQQ3RteHVNUlF3RWdZRFZRUUtEQXRUClpXdDBhVzl1UldsdWN6RWZNQjBHQTFVRUN3d1dUV0ZzYVdOcGIzVnpJRU5sY25RZ1UyVmpkR2x2YmpFaE1COEcKQTFVRUF3d1liV0ZzYVdOcGIzVnpMbk5sYTNScGIyNWxhVzV6TG1SbE1Tb3dLQVlKS29aSWh2Y05BUWtCRmh0egpkR1ZtWVc0dVpYTnpaWEpBYzJWcmRHbHZibVZwYm5NdVpHVXdnZ0VpTUEwR0NTcUdTSWIzRFFFQkFRVUFBNElCCkR3QXdnZ0VLQW9JQkFRRERBZjNobDdKWTBYY0ZuaXlFSnBTU0RxbjBPcUJyNlFQNjV1c0pQUnQvOFBhRG9xQnUKd0VZVC9OYSs2ZnNnUGpDMHVLOURaZ1dnMnRIV1dvYW5TYmxBTW96NVBINlorUzRTSFJaN2UyZERJalBqZGhqaAowbUxnMlVNTzV5cDBWNzk3R2dzOWxOdDZKUmZIODFNTjJvYlhXczROdHp0TE11RDZlZ3FwcjhkRGJyMzRhT3M4CnBrZHVpNVVhd1Raa3N5NXBMUEhxNWNNaEZHbTA2djY1Q0xvMFYyUGQ5K0tBb2tQclBjTjVLTEtlYno3bUxwazYKU01lRVhPS1A0aWRFcXh5UTdPN2ZCdUhNZWRzUWh1K3ByWTNzaTNCVXlLZlF0UDVDWm5YMmJwMHdLSHhYMTJEWAoxbmZGSXQ5RGJHdkhUY3lPdU4rblpMUEJtM3ZXeG50eUlJdlZBZ01CQUFHalFqQkFNQWtHQTFVZEV3UUNNQUF3CkVRWUpZSVpJQVliNFFnRUJCQVFEQWdlQU1Bc0dBMVVkRHdRRUF3SUZvREFUQmdOVkhTVUVEREFLQmdnckJnRUYKQlFjREFqQU5CZ2txaGtpRzl3MEJBUVVGQUFPQ0FRRUFHMGZaWVlDVGJkajFYWWMrMVNub2FQUit2SThDOENhRAo4KzBVWWhkbnlVNGdnYTBCQWNEclk5ZTk0ZUVBdTZacXljRjZGakxxWFhkQWJvcHBXb2NyNlQ2R0QxeDMzQ2tsClZBcnpHL0t4UW9oR0QySmVxa2hJTWxEb214SE83a2EzOStPYThpMnZXTFZ5alU4QVp2V01BcnVIYTRFRU55RzcKbFcyQWFnYUZLRkNyOVRuWFRmcmR4R1ZFYnY3S1ZRNmJkaGc1cDVTanBXSDErTXEwM3VSM1pYUEJZZHlWODMxOQpvMGxWajFLRkkyRENML2xpV2lzSlJvb2YrMWNSMzVDdGQwd1lCY3BCNlRac2xNY09QbDc2ZHdLd0pnZUpvMlFnClpzZm1jMnZDMS9xT2xOdU5xLzBUenprVkd2OEVUVDNDZ2FVK1VYZTRYT1Z2a2NjZWJKbjJkZz09Ci0tLS0tRU5EIENFUlRJRklDQVRFLS0tLS0K';
        $script = <<<'EOT'

error_reporting(-1);
$info = openssl_x509_parse(base64_decode('%s'));
var_dump(PHP_VERSION, $info['issuer']['emailAddress'], $info['validFrom_time_t']);

EOT;
        $script = '<'."?php\n".sprintf($script, $cert);

        try {
            $process = new PhpProcess($script);
            $process->mustRun();
        } catch (\Exception $e) {
            // In the case of any exceptions just accept it is not possible to
            // determine the safety of openssl_x509_parse and bail out.
            return self::$useOpensslParse = false;
        }

        $output = preg_split('{\r?\n}', trim($process->getOutput()));
        $errorOutput = trim($process->getErrorOutput());

        if (
            count($output) === 3
            && $output[0] === sprintf('string(%d) "%s"', strlen(PHP_VERSION), PHP_VERSION)
            && $output[1] === 'string(27) "stefan.esser@sektioneins.de"'
            && $output[2] === 'int(-1)'
            && preg_match('{openssl_x509_parse\(\): illegal (?:ASN1 data type for|length in) timestamp in - on line \d+}', $errorOutput)
        ) {
            // This PHP has the fix backported probably by a distro security team.
            return self::$useOpensslParse = true;
        }

        return self::$useOpensslParse = false;
    }

    /**
     * Convert certificate name into matching function.
     *
     * @param string $certName CN/SAN
     *
     * @return callable|null
     */
    private static function certNameMatcher($certName)
    {
        $wildcards = substr_count($certName, '*');

        if (0 === $wildcards) {
            // Literal match.
            return function ($hostname) use ($certName) {
                return $hostname === $certName;
            };
        }

        if (1 === $wildcards) {
            $components = explode('.', $certName);

            if (3 > count($components)) {
                // Must have 3+ components
                return;
            }

            $firstComponent = $components[0];

            // Wildcard must be the last character.
            if ('*' !== $firstComponent[strlen($firstComponent) - 1]) {
                return;
            }

            $wildcardRegex = preg_quote($certName);
            $wildcardRegex = str_replace('\\*', '[a-z0-9-]+', $wildcardRegex);
            $wildcardRegex = "{^{$wildcardRegex}$}";

            return function ($hostname) use ($wildcardRegex) {
                return 1 === preg_match($wildcardRegex, $hostname);
            };
        }
    }
}
