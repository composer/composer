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
use Composer\Util\Openssl;

/**
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class CreateKeysCommand extends Command
{
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
signed by the threshold number of private keys.

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
        if (file_exists(self::KEYS_FILE)) {
            if (!is_readable(self::KEYS_FILE)) {
                $output->writeln('<error>The existing '.self::KEYS_FILE.' file is not readable</error>');
                return 1;
            }
            $data = json_decode(file_get_contents(self::KEYS_FILE), true);
            $keys = $data['signed'];
        }

        /**
         * Verify that this private key's public key is actually listed
         * as an authorised key - otherwise it can't sign anything!
         */
        $keyIds = array_keys($keys['keys']);
        


    }
}
