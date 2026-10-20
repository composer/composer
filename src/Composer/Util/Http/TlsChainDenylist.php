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

namespace Composer\Util\Http;

use Composer\CaBundle\CaBundle;
use Composer\Downloader\TransportException;
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
    public static function findDistrustedCertificateInCurlInfo(array $certInfo): ?string
    {
        return CaBundle::findDistrustedCertificate(array_column($certInfo, 'Cert'));
    }

    /**
     * @return string|null host:port for https URLs, as that is what curl reuses connections by
     */
    public static function getHostKey(string $url): ?string
    {
        if (0 !== stripos($url, 'https://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }

        return strtolower($host).':'.(parse_url($url, PHP_URL_PORT) ?? 443);
    }

    /**
     * @param bool $requestSent whether the request (and any credentials in it) already went out before the chain could be checked
     */
    public static function createException(string $url, string $certName, bool $requestSent): TransportException
    {
        $message = 'The TLS certificate chain presented for "'.Url::sanitize($url).'" contains the distrusted certificate "'.$certName.'", this may be a man-in-the-middle attack.';
        if ($requestSent) {
            $message .= ' The request was already sent before this could be detected (upgrade to PHP 8.4+ with the curl extension to detect it earlier), if it contained authentication credentials they may have been intercepted by an attacker and should be rotated.';
        } else {
            $message .= ' The request was aborted before it was sent.';
        }

        return new TransportException($message);
    }
}
