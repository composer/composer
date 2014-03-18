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

namespace Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Util\Bencode;
use Composer\Util\Openssl;

/**
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class SignDevKeysCommand extends Command
{

    const KEYS_FILE = 'keys.json';

    protected function configure()
    {
        $this
            ->setName('sign-dev-keys')
            ->setDescription('Apply a private key\'s signature to the keys.json public key registry')
            ->setDefinition(array(
                new InputArgument('private-key', InputArgument::REQUIRED, 'Path to the private key which will be used to sign the package'),
            ))
            ->setHelp(<<<EOT
The sign-dev-keys command is used to sign a keys.json file which contains
a list of the public keys authorised to sign a package. Any change in public
keys for this file (e.g. after using add-dev-key) will require that it be
signed by the threshold number of private keys so that clients can verify the
changes.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $passphrase = null;
        $answer = $this->getIO()->askAndHideAnswer('Enter a passphrase to encrypt the private key:');
        if (strlen($answer) > 0) {
            $passphrase = $answer;
        }

        /**
         * Initialise helper classes
         */
        $bencode = new Bencode;
        $openssl = new Openssl;
        try {
            $openssl->importPrivateKey($input->getArgument('private-key'), $passphrase);
            $publicKeyId = hash('sha256', trim($openssl->getPublicKey()));
        } catch (\Exception $e) {
            $output->writeln('<error>Invalid private key or passphrase.</error>');
            throw $e;
        }

        /**
         * Initialise the keys array to be signed
         */
        if (!file_exists(self::KEYS_FILE) || !is_readable(self::KEYS_FILE)) {
            $output->writeln('<error>The '.self::KEYS_FILE.' file does not exist or is not readable</error>');
            return 1;
        }
        $data = json_decode(file_get_contents(self::KEYS_FILE), true);
        $keys = $data['signed'];

        /**
         * Verify that this private key's public key is actually listed
         * as a root authorised key - otherwise it can't sign for key changes!
         */
        $keyIds = array_keys($keys['keys']);
        $rootIds = $keys['roles']['root']['keyids'];
        if (!in_array($publicKeyId, $keyIds)) {
            $output->writeln('<error>The matching public key to this private key is not registered in the '.self::KEYS_FILE.' file.</error>');
            return 1;
        } elseif (!in_array($publicKeyId, $rootIds)) {
            $output->writeln('<error>The matching public key to this private key is not assigned to the root role in the '.self::KEYS_FILE.' file.</error>');
            $output->writeln('<error>Only keys added to the root role may sign the '.self::KEYS_FILE.' file.</error>');
            return 1;
        }

        /**
         * Create the signature
         */
        $canonical = $bencode->encode($keys);
        $signature = $openssl->sign($canonical);
        $sig = array(
            'keyid' => $publicKeyId,
            'method' => 'OPENSSL_ALGO_SHA1',
            'sig' => $signature
        );

        /**
         * Verify that we have not already correctly signed keys.json with this
         * private key
         */
        if (in_array($sig, $data['signatures'])) {
            $output->writeln('<warning>You have already correctly signed '.self::KEYS_FILE.' with this key</warning>');
            $output->writeln('<warning>Aborting...</warning>');
            return;
        }

        /**
         * Remove any old signatures for this private key
         */
        foreach ($data['signatures'] as $index => $origSig) {
            if ($publicKeyId == $origSig['keyid']) {
                unset($data['signatures'][$index]);
            }
        }

        /**
         * Add the private key's signature and write the file
         */
        $data['signatures'][] = $sig;
        $flags = 0;
        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            $flags = JSON_PRETTY_PRINT;
        }
        $res = file_put_contents(self::KEYS_FILE, json_encode($data, $flags), LOCK_EX);
        if (!$res) {
            $output->writeln('<error>Failed to write signed '.self::KEYS_FILE.' to '.realpath(self::KEYS_FILE).'.</error>');
            return 1;
        }
        $output->writeln('<info>The '.self::KEYS_FILE.' file has been successfully signed.</info>');
        return;

    }
}
