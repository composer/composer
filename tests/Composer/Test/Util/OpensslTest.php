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

namespace Composer\Test\Util;

use Composer\Util\Openssl;

/**
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class OpensslTest extends \PHPUnit_Framework_TestCase
{
    
    private $privateKey = <<<PRIVATEKEY
-----BEGIN ENCRYPTED PRIVATE KEY-----
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
T/I=
-----END ENCRYPTED PRIVATE KEY-----

PRIVATEKEY;

    private $publicKey = <<<PUBLICKEY
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAtDS+gDpv5gMtN1VATjcc
59PNzHqMg1izOBeJFJ/elWb8GJyI8DTwnkaPGaBwoU6OzgEzu4ISawiDhLyJfCL4
ajs1R0weI9PPrVnMuqIdYtzLt7qdDI+31+ckJqdwsdGi546+GDaxz53h9Z7kgYZy
Sx2cAKWcIwr77DcoTNGad58RNR11au9jpWl7M+W9hIgx19IQ1CiApPctdjOPbktF
AmlcZGSeGD2e6xxYvlQPEcQfVjZW1yuq+voWToW3IeoO1YVGsAKJ+5nDTo+p/7gp
kCKLhX22M+SwBtACTUKoNAn8CFy9zX+dX5KWdde6UsGoEdH2mUhS7pfdV9V69SaQ
TYh0QV357zGtNvJLnud6HHTdzuS+8NGk02ZhmWsGgkluxBjCMdCKZ51QOajH+Jnr
fBiHLXoUHK/wpGuF4FycosBY3HiGqADWHU4W3qVnzaxYIsJ6XmKX7bTOcpz6OHeK
76BSYneg4vqDkXp0ybHXzFk/XDqc8uT49KCKxFOhij4H46gh99fYV3mpWlcA28Pm
oETXikB/6nTswY+mUJuBvC0DW7TxcUptvY6QCTzw/bLRO+gSep160Uq/CyF0LSjy
h9XIvYbiKNis9U2KtU+mxwOEGEyYstc6mcmCp1tA9t/E9slCYk1/ZHOmqn6KMN3a
gwVEf25vqg9OqUNKLCALLFcCAwEAAQ==
-----END PUBLIC KEY-----

PUBLICKEY;

    private $data = <<<DATA
Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
DATA;

    private $signature = <<<SIGNATURE
SUu2XvXjYH7xGN1GYrNa3d4qpcGayNveCAbvWggntB8fyxAhrbY5CsHRZC4VOqjkivXf8edocPAl2eo+cZJLqqd8MgwFBmh5oHNdWzHT21ETTz2EdBTWUoBus1q1vD0b1evsVUFpoa9xYnJeJ1M0aWdWBzhGmdV4I0dkEHlrt7bMVSO53Dl3otl11RjY9TegcP5wzhm6g2y1AJ89XkdvQH73cM6MwZxxTyRRUWtkpdWEa7wWGnZap8VXjdfQRJs8TiDfWDPgzXzuCmRnKmFMvh+i7ZIvkn6aejYDM7mlrVRXueL8D62+jWSxXpqvZWIjWDrnECgIE4QDx1T2t5EFCzwhQGF13zm8oNZMilV63i8Bv+UW0oQclHxrceboBbSjpG+dFJyN/I4rwRxXxXKxZdrYcLv+gReUo1NVy7azEA4LAHa6TlyIOaw+b2xLaAeBIGS/zcAB+pTGEdjtdYR+tNmWfcJsWFYuKaq+DmGV2m/X4rtk6rGxzrVYwI6Xyesy09jXxd2gHwxhc9C/rESIG8wO21BXkCEdlhRnVAjb+VLIceiQ/XRZRHEmHZNKzTUL76i7wbnA4VGfp+4EQycbcalkgMDvd0rwuN4pzOZ2Va793CS+MvM2SSSPJJyy17Ne4gspqaHZFDq2mdVNIzmKqFocfAc4ho1jiH2zaGTuFXc=
SIGNATURE;

    private $passphrase = 'password';
    
    public function setup()
    {
        $this->openssl = new Openssl;
        $tmp = sys_get_temp_dir();
        $this->pkfile = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . uniqid();
        file_put_contents($this->pkfile, $this->privateKey);
        $this->pkfile2 = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . uniqid();
        file_put_contents($this->pkfile2, $this->publicKey);
        $this->pkfile3 = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . uniqid();
        $this->pkfile4 = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . uniqid();
    }

    public function teardown()
    {
        if (file_exists($this->pkfile)) unlink($this->pkfile);
        if (file_exists($this->pkfile2)) unlink($this->pkfile2);
        if (file_exists($this->pkfile3)) unlink($this->pkfile3);
        if (file_exists($this->pkfile4)) unlink($this->pkfile4);
    }

    /**
     * @group openssl
     */
    public function testCreatedKeysShouldWork()
    {
        $this->openssl->createKeys();
        $this->assertTrue(3000 < strlen($this->openssl->getPrivateKey()));
        $this->assertEquals(800, strlen($this->openssl->getPublicKey()));
    }

    /**
     * @group openssl
     */
    public function testCanImportPrivateKey()
    {
        $this->openssl->importPrivateKey($this->pkfile, $this->passphrase);
        $this->assertEquals($this->publicKey, $this->openssl->getPublicKey());
    }

    /**
     * @group openssl
     */
    public function testCanImportPublicKey()
    {
        $this->openssl->importPublicKey($this->pkfile2);
        $this->assertEquals($this->publicKey, $this->openssl->getPublicKey());
    }

    /**
     * @group openssl
     */
    public function testCanExportPrivateKey()
    {
        $this->openssl->importPrivateKey($this->pkfile, $this->passphrase);
        $this->openssl->exportPrivateKey($this->pkfile3);
        $this->openssl->importPrivateKey($this->pkfile3, $this->passphrase);
        $this->assertEquals($this->privateKey, $this->openssl->getPrivateKey());
    }

    /**
     * @group openssl
     */
    public function testCanExportPublicKey()
    {
        $this->openssl->importPublicKey($this->pkfile2);
        $this->openssl->exportPublicKey($this->pkfile4);
        $this->openssl->importPublicKey($this->pkfile4);
        $this->assertEquals($this->publicKey, $this->openssl->getPublicKey());
    }

    /**
     * @group openssl
     */
    public function testCanSignDataWithPrivateKey()
    {
        $this->openssl->importPrivateKey($this->pkfile, $this->passphrase);
        $this->assertEquals($this->signature, $this->openssl->sign($this->data));
    }

    /**
     * @group openssl
     */
    public function testCanVerifySignedDataWithPublicKey()
    {
        $this->openssl->importPublicKey($this->pkfile2);
        $this->assertEquals(1, $this->openssl->verify($this->data, $this->signature));
    }

}
