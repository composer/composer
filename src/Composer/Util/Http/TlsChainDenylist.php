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

namespace Composer\Util\Http;

use Composer\CaBundle\CaBundle;
use Composer\Exception\SecurityException;
use Composer\Util\Url;

/**
 * Rejects TLS connections whose presented chain contains a distrusted (e.g. compromised) intermediate
 *
 * The system CA store may still trust the roots above them, so this cannot be left to the cafile alone.
 *
 * @internal
 */
final class TlsChainDenylist
{
    /**
     * @param array<array{Cert?: string}> $certInfo as returned by curl_getinfo($handle, CURLINFO_CERTINFO)
     *
     * @return string|null name of the first distrusted certificate found in the chain
     */
    public static function findDistrustedCertificateInCurlInfo(array $certInfo)
    {
        $chain = array();
        foreach ($certInfo as $cert) {
            if (isset($cert['Cert'])) {
                $chain[] = $cert['Cert'];
            }
        }

        return CaBundle::findDistrustedCertificate($chain);
    }

    /**
     * @param string $url
     *
     * @return string|null host:port for https URLs, as that is what curl reuses connections by
     */
    public static function getHostKey($url)
    {
        if (0 !== stripos($url, 'https://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return strtolower($host).':'.($port ? $port : 443);
    }

    /**
     * Not a TransportException so that callers falling back on transport errors do not swallow it
     *
     * @param string $url
     * @param string $certName
     * @param bool   $requestSent whether the request (and any credentials in it) already went out before the chain could be checked
     *
     * @return SecurityException
     */
    public static function createException($url, $certName, $requestSent)
    {
        $message = 'The TLS certificate chain presented for "'.Url::sanitize($url).'" contains the distrusted certificate "'.$certName.'", this may be a man-in-the-middle attack.';
        if ($requestSent) {
            $message .= ' The request was already sent before this could be detected (upgrade to PHP 8.4+ with the curl extension to detect it earlier), if it contained authentication credentials they may have been intercepted by an attacker and should be rotated.';
        } else {
            $message .= ' The request was aborted before it was sent.';
        }

        return new SecurityException($message);
    }
}
