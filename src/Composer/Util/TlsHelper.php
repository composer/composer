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

use Composer\CaBundle\CaBundle;
use Composer\Pcre\Preg;

/**
 * @author Chris Smith <chris@cs278.org>
 * @deprecated Use composer/ca-bundle and composer/composer 2.2 if you still need PHP 5 compatibility, this class will be removed in Composer 3.0
 */
final class TlsHelper
{
    /**
     * Match hostname against a certificate.
     *
     * @param mixed  $certificate X.509 certificate
     * @param string $hostname    Hostname in the URL
     * @param string $cn          Set to the common name of the certificate iff match found
     */
    public static function checkCertificateHost($certificate, string $hostname, ?string &$cn = null): bool
    {
        $names = self::getCertificateNames($certificate);

        if (empty($names)) {
            return false;
        }

        $combinedNames = array_merge($names['san'], [$names['cn']]);
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
     * @return array{cn: string, san: string[]}|null
     */
    public static function getCertificateNames($certificate): ?array
    {
        if (is_array($certificate)) {
            $info = $certificate;
        } elseif (CaBundle::isOpensslParseSafe()) {
            $info = openssl_x509_parse($certificate, false);
        }

        if (!isset($info['subject']['commonName'])) {
            return null;
        }

        $commonName = strtolower($info['subject']['commonName']);
        $subjectAltNames = [];

        if (isset($info['extensions']['subjectAltName'])) {
            $subjectAltNames = Preg::split('{\s*,\s*}', $info['extensions']['subjectAltName']);
            $subjectAltNames = array_filter(array_map(static function ($name): ?string {
                if (0 === strpos($name, 'DNS:')) {
                    return strtolower(ltrim(substr($name, 4)));
                }

                return null;
            }, $subjectAltNames));
            $subjectAltNames = array_values($subjectAltNames);
        }

        return [
            'cn' => $commonName,
            'san' => $subjectAltNames,
        ];
    }

    /**
     * Get the certificate pin.
     *
     * By Kevin McArthur of StormTide Digital Studios Inc.
     * @KevinSMcArthur / https://github.com/StormTide
     *
     * See https://tools.ietf.org/html/draft-ietf-websec-key-pinning-02
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
    public static function getCertificateFingerprint(string $certificate): string
    {
        $pubkey = openssl_get_publickey($certificate);
        if ($pubkey === false) {
            throw new \RuntimeException('Failed to retrieve the public key from certificate');
        }
        $pubkeydetails = openssl_pkey_get_details($pubkey);
        $pubkeypem = $pubkeydetails['key'];
        //Convert PEM to DER before SHA1'ing
        $start = '-----BEGIN PUBLIC KEY-----';
        $end = '-----END PUBLIC KEY-----';
        $pemtrim = substr($pubkeypem, strpos($pubkeypem, $start) + strlen($start), (strlen($pubkeypem) - strpos($pubkeypem, $end)) * (-1));
        $der = base64_decode($pemtrim);

        return sha1($der);
    }

    /**
     * Test if it is safe to use the PHP function openssl_x509_parse().
     *
     * This checks if OpenSSL extensions is vulnerable to remote code execution
     * via the exploit documented as CVE-2013-6420.
     */
    public static function isOpensslParseSafe(): bool
    {
        return CaBundle::isOpensslParseSafe();
    }

    /**
     * Convert certificate name into matching function.
     *
     * @param string $certName CN/SAN
     */
    private static function certNameMatcher(string $certName): ?callable
    {
        $wildcards = substr_count($certName, '*');

        if (0 === $wildcards) {
            // Literal match.
            return static function ($hostname) use ($certName): bool {
                return $hostname === $certName;
            };
        }

        if (1 === $wildcards) {
            $components = explode('.', $certName);

            if (3 > count($components)) {
                // Must have 3+ components
                return null;
            }

            $firstComponent = $components[0];

            // Wildcard must be the last character.
            if ('*' !== $firstComponent[strlen($firstComponent) - 1]) {
                return null;
            }

            $wildcardRegex = preg_quote($certName);
            $wildcardRegex = str_replace('\\*', '[a-z0-9-]+', $wildcardRegex);
            $wildcardRegex = "{^{$wildcardRegex}$}";

            return static function ($hostname) use ($wildcardRegex): bool {
                return Preg::isMatch($wildcardRegex, $hostname);
            };
        }

        return null;
    }
}
