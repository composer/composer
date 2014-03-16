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
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class Openssl
{

    private $keyArgs = array(
        'digest_alg' => 'sha512',
        'private_key_bits' => 4096,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    );

    private $privateKeyResource = null;

    private $privateKey = null;

    private $publicKey = null;

    public function __construct()
    {

    }

    public function createKeys($password = null, $keyArgs = null)
    {
        if (isset($keyArgs)) {
            $keyArgs = array_merge_recursive($this->keyArgs, $keyArgs);
        } else {
            $keyArgs = $this->keyArgs;
        }
        $this->privateKeyResource = openssl_pkey_new($keyArgs);
        openssl_pkey_export($this->privateKeyResource, $this->privateKey, $password);
        $this->publicKey = $this->extractPublicKey($this->privateKeyResource);
    }

    public function exportPrivateKey($file)
    {
        if (!isset($this->privateKey)) {
            throw new \RuntimeException(
                'A private key is not yet known to this class.'
            );
        }
        file_put_contents($file, $this->privateKey, LOCK_EX);
    }

    public function importPrivateKey($file, $password = null)
    {
        if (!file_exists($file) || !is_readable($file)) {
            throw new \RuntimeException(
                'The private key file cannot be found/read: ' . $file
            );
        }
        $key = file_get_contents($file);
        $this->setPrivateKey($key, $password);
    }

    public function exportPublicKey($file)
    {
        if (!isset($this->publicKey)) {
            throw new \RuntimeException(
                'A public key is not yet known to this class.'
            );
        }
        file_put_contents($file, $this->publicKey, LOCK_EX);
    }

    public function importPublicKey($file)
    {
        if (!file_exists($file) || !is_readable($file)) {
            throw new \RuntimeException(
                'The public key file cannot be found/read: ' . $file
            );
        }
        $this->publicKey = file_get_contents($file);
    }

    public function getPrivateKeyResource()
    {
        return $this->privateKeyResource;
    }

    public function setPrivateKey($key, $password = null)
    {
        $this->privateKeyResource = openssl_pkey_get_private($key, $password);
        $this->privateKey = $key;
        $this->publicKey = $this->extractPublicKey($this->privateKeyResource);
    }

    public function getPrivateKey()
    {
        return $this->privateKey;
    }

    public function setPublicKey($key) {
        $this->publicKey = $key;
    }

    public function getPublicKey()
    {
        return $this->publicKey;
    }

    public function sign($data, $algorithm = OPENSSL_ALGO_SHA1)
    {
        openssl_sign(
            $data,
            $signature,
            $this->privateKeyResource,
            $algorithm
        );
        return base64_encode($signature);
    }

    public function verify($data, $signature, $algorithm = OPENSSL_ALGO_SHA1)
    {
        $result = openssl_verify(
            $data,
            base64_decode($signature),
            $this->publicKey,
            $algorithm
        );
        return $result;
    }

    private function extractPublicKey($privateKeyResource)
    {
        $keys = openssl_pkey_get_details($privateKeyResource);
        return $keys["key"];
    }

}