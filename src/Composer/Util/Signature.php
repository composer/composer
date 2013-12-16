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

use Composer\Config;
use Composer\Util\RemoteFilesystem;

/**
 * Helper class for importing keys, verification and signing.
 *
 * @author Till Klampaeckel <till@php.net>
 */
class Signature
{
    /**
     * @var \Composer\Config
     */
    protected $config;

    /**
     * @var string
     */
    protected $configurationKey = 'public-keys';

    /**
     * @var resource
     */
    protected $publicKey;

    /**
     * @var RemoteFilesystem
     */
    protected $rfs;

    /**
     * @var string
     */
    protected $repository;

    /**
     * @param \Composer\Config $config An instance of the Composer configuration.
     * @param string           $url    URL of the repository we're dealing with.
     */
    public function __construct(Config $config, RemoteFilesystem $rfs, $url)
    {
        $this->config = $config;
        $this->rfs = $rfs;
        $this->repository = $this->getRepositoryName($url);
    }

    /**
     * @param string $url
     *
     * @return mixed
     */
    protected function getRepositoryName($url)
    {
        return parse_url($url, PHP_URL_HOST);
    }

    /**
     * Get the public key from the configuration in `$COMPOSER_HOME`.
     */
    protected function findPublicKey()
    {
        $msg = "No keys have been added or imported.";
        if (!$this->config->has('keys')) {
            throw new \RuntimeException($msg);
        }
        $keys = $this->config->get($this->configurationKey);
        foreach ($keys as $keyData) {
            if ($keyData['repository'] != $this->repository) {
                continue;
            }
            $this->publicKey = $this->extractPublicKey($keyData['path']);
            return;
        }

        throw new \RuntimeException($msg);
    }

    /**
     * Extract the public key from the certificate.
     *
     * @param string $path
     *
     * @return resource
     * @throws \LogicException
     * @throws \RuntimeException
     */
    protected function extractPublicKey($path)
    {
        if (!extension_loaded('openssl')) {
            throw new \LogicException("Missing PHP's OpenSSL extension.");
        }
        $certificate = @file_get_contents($path);
        if (false === $certificate) {
            throw new \RuntimeException(sprintf("Certificate not found in '%s'", $path));
        }
        $publicKey = openssl_get_publickey($certificate);
        return $publicKey;
    }

    /**
     * Find out if we already imported a key for the current repository.
     *
     * @return bool
     */
    public function hasPublicKey()
    {
        if ($this->config->has($this->configurationKey)) {
            return false;
        }
        $keys = $this->config->get($this->configurationKey);
        if (!isset($keys[$this->repository])) {
            return false;
        }
        return true;
    }

    /**
     * @param string $url The URL of the public key file.
     */
    public function importPublicKey($url)
    {
        // this is a hack and needs work
        $path = "~/.composer/{$this->configurationKey}";
        $certificate = "{$this->repository}.pem";

        $this->rfs->copy($url, sprintf('%s/%s', $path, $certificate), $certificate);

        $keys = array();
        if ($this->config->has($this->configurationKey)) {
            $keys = $this->config->get($this->configurationKey);
        }
        $keys[$this->repository] = sprintf('%s/%s', $path, $certificate);
    }

    /**
     * Sign it!
     *
     * @param string $data
     * @param mixed  $privateKey
     *
     * @return string
     * @throws \LogicException
     */
    public function sign($data, $privateKey)
    {
        if (!extension_loaded('openssl')) {
            throw new \LogicException("Missing PHP's OpenSSL extension.");
        }
        if (!is_resource($privateKey)) {
            $certificate = file_get_contents($privateKey);
            if (false === $certificate) {
                throw new \RuntimeException(sprintf(
                    "Could not read certificate file from: '%s'",
                    $privateKey
                ));
            }
            $privateKey = openssl_get_privatekey($certificate);
        }
        openssl_sign($data, $signature, $privateKey);
        openssl_free_key($privateKey);
        return $signature;
    }

    /**
     * Verify the signature of 'data'.
     *
     * @param string $data
     * @param string $signature
     *
     * @return boolean
     * @throws \RuntimeException
     */
    public function verify($data, $signature)
    {
        $this->findPublicKey();

        $status = openssl_verify($data, $signature, $this->publicKey);
        switch ($status) {
            case 1:
                return true;
            case 2:
                return false;
            default:
                throw new \RuntimeException("Internal error: Unable to verify the signature.");
        }
    }

    /**
     * Ensure the public key is removed from memory.
     */
    public function __destruct()
    {
        if ($this->publicKey) {
            openssl_free_key($this->publicKey);
        }
    }
}
