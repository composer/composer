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

use Composer\Factory;
use Composer\IO\IOInterface;

/**
 * @author Evan Coury <me@evancoury.com>
 */
class SslHelper
{
    private $io;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
        $this->config = Factory::createConfig();
    }

    public function verifySslCertificateFromServer($hostname, $port = 443, $url = false)
    {
        $chain      = $this->fetchCertificateChain($hostname, $port);
        $cert       = $chain[0];
        $certInfo   = openssl_x509_parse($cert);
        $commonName = $certInfo['subject']['CN'];
        $caCert     = $chain[count($chain)-1];
        $caCertInfo = openssl_x509_parse($caCert);

        // Check if the certificate has expired
        $expired = false;
        if ($certInfo['validTo_time_t'] < time()) {
            $expired = true;
            if (!$this->io->askConfirmation("WARNING! The SSL certificate for {$hostname} has expired. Are you sure you wish to continue? [y/N] ", false)) {
                throw new BadCryptoException('Encountered expired SSL certificate; exiting on user command.');
            }
        }
        $expires = date('Y-m-d', $certInfo['validTo_time_t']) . (($expired) ? ' (WARNING: Certificate has expired!)' : '');

        // Proper CN_Match handling -- PHP's is broken
        $alternativeNames = explode(', ', str_replace('DNS:', '', $certInfo['extensions']['subjectAltName']));
        $validDomain = true;
        if ($hostname != $commonName && !in_array($hostname, $alternativeNames)) {
            $validDomain = false;
            if (!$this->io->askConfirmation("WARNING! The SSL certificate at {$hostname} is NOT VALID for the requested hostname. " .
                "This could be an indication of a man-in-the-middle attack " .
                "or a misconfigured server. It is strongly recommended that you DO NOT continue.\n\n" .
                "Do you want to continue connecting with an invalid certificate? [y/N] ", false)) {
                throw new BadCryptoException('Encountered invalid SSL certificate; exiting on user command.');
            }
        }

        // Get a readable Certificate Authority name
        if (isset($caCertInfo['subject']['O'], $caCertInfo['subject']['CN'])) {
            $caName = "{$caCertInfo['subject']['O']} » {$caCertInfo['subject']['CN']}";
        } elseif (isset($caCertInfo['issuer']['O'], $caCertInfo['issuer']['CN'])) {
            $caName = "{$caCertInfo['issuer']['O']} » {$caCertInfo['issuer']['CN']}";
        } else {
            $caName = $caCertInfo['name'];
        }

        // Get the certificate fingerprint
        openssl_x509_export($cert, $certString);
        $fingerprint = $this->getSha1Fingerprint($certString);

        $this->io->write("\n <error>#############################################################</error>");
        $this->io->write(' <error># WARNING: Composer is trying to connect to a secure server #</error>');
        $this->io->write(' <error>#   with an untrusted public key. Please review carefully.  #</error>');
        $this->io->write(" <error>#############################################################</error>\n");

        if ($url) $this->io->write("<warning> Requested URL: {$url}</warning>");
        $this->io->write("<warning>      Hostname: {$hostname}</warning>");
        $this->io->write('<warning>   Common Name: ' . $commonName .
            ((count($alternativeNames) > 0) ? ' (' . implode(', ', $alternativeNames) . ')' : '') . '</warning>'); // show alt names
        $this->io->write("<warning>   Valid Until: {$expires}</warning>");
        $this->io->write("<warning>   Fingerprint: {$fingerprint}</warning>");
        $this->io->write("<warning>     Authority: {$caName}</warning>");

        $this->io->write("<warning>\nTo verify the certificate fingerprint, go to https://{$hostname}/ in your web browser and view the certificate details.</warning>");

        if ($this->io->askConfirmation("\nAre you sure you trust the Certificate Authority listed above and have verified the certificate fingerprint? [y/N] ", false)) {
            openssl_x509_export($caCert, $caCertString);
            file_put_contents(static::initCaBundleFile(), $caCertString, FILE_APPEND);
            $this->io->write("<warning> Added {$caName} as a trustworthy Certificate Authority.</warning>");
            return true;
        }

        throw new BadCryptoException('Encountered untrusted SSL certificate; exiting on user command.');
    }

    protected function getSha1Fingerprint($certificate)
    {
        $certificate = str_replace('-----BEGIN CERTIFICATE-----', '', $certificate);
        $certificate = str_replace('-----END CERTIFICATE-----', '', $certificate);
        $certificate = base64_decode($certificate);
        $fingerprint = strtoupper(sha1($certificate));
        $fingerprint = str_split($fingerprint, 2);
        return implode(':', $fingerprint);
    }

    protected function fetchCertificateChain($hostname, $port)
    {
        $context = stream_context_create(array('ssl' => array('capture_peer_cert_chain' => true)));
        $socket  = stream_socket_client("ssl://{$hostname}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        $params  = stream_context_get_params($socket);
        return $params['options']['ssl']['peer_certificate_chain'];
    }

    public static function initCaBundleFile()
    {
        $config = Factory::createConfig();
        $caBundleFile = $config->get('home') . '/trusted-ca-bundle.crt';

        if (file_exists($caBundleFile)) {
            return $caBundleFile;
        }
        touch($caBundleFile);

        // Improve usability for some linux users by automatically
        // importing the distro's CA bundle if it's found.
        $caBundleLocations = array(
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/share/ssl/certs/ca-bubdle.crt',
        );

        foreach ($caBundleLocations as $caBundle) {
            if (file_exists($caBundle) && is_readable($caBundle)) {
                $trustedBundle = file_get_contents($caBundle);
                break;
            }
        }

        if (isset($trustedBundle)) file_put_contents($caBundleFile, $trustedBundle);

        return $caBundleFile;
    }
}
