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
use Composer\Package\Manifest;

/**
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class AddDevKeyCommand extends Command
{

    const MANIFEST_FILE = 'manifest.json';
    const KEYS_FILE = 'keys.json';

    protected function configure()
    {
        $this
            ->setName('add-dev-key')
            ->setDescription('Add a developer public key to the keys.json register for a package')
            ->setDefinition(array(
                new InputOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Sets the threshold number of signatures required to verify this file (Default: 1)', 1),
                new InputOption('expires', 'e', InputOption::VALUE_REQUIRED, 'Set an expiry date beyond which the keys.json file will be deemed untrusted', null),
                new InputOption('root', 'r', InputOption::VALUE_NONE, 'Include the given key as a root signer, i.e. it may sign off on future key changes.'),
                new InputArgument('private-key', InputArgument::REQUIRED, 'Path to the private key which will be used to sign the package'),
            ))
            ->setHelp(<<<EOT
The add-dev-key command, imports a developer's private key, adds their
public key to the keys.json file, and registers it as being authorised to
sign manifests. You will also need to sign the file after any changes
using the sign-dev-keys command. If you want to allow the key to sign
off on future key changes, pass the --root option to include it as a root
key.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        
        $passphrase = null;
        $answer = $this->getIO()->askAndHideAnswer('Enter a passphrase if the private key is encrypted:');
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
         * Initialise the keys array
         */
        $keys = array();
        $data = array();
        if (file_exists(self::KEYS_FILE)) {
            if (!is_readable(self::KEYS_FILE)) {
                $output->writeln('<error>The existing '.self::KEYS_FILE.' file is not readable.</error>');
                return 1;
            }
            $data = json_decode(file_get_contents(self::KEYS_FILE), true);
            $keys = $data['signed'];
        } else {
            $data['signatures'] = array();
            $data['signed'] = $keys = array(
                'expires' => '',
                'keys' => array(),
                'roles' => array(
                    'manifest' => array(
                        'keyids' => array(),
                        'threshold' => 1
                    ),
                    'root' => array(
                        'keyids' => array(),
                        'threshold' => 1
                    )
                )
            );
        }

        /**
         * Abort if the key has already been added.
         * If it was added, but we now want to add it to the root role, do so.
         */
        $skipKeyAddition = false;
        $keyIds = array_keys($keys['keys']);
        $rootIds = $keys['roles']['root']['keyids'];
        if (in_array($publicKeyId, $keyIds) && in_array($publicKeyId, $rootIds)) {
            $output->writeln('<warning>The matching public key to this private key is already registed in the '.self::KEYS_FILE.' file.</warning>');
            $output->writeln('<warning>Aborting...</warning>');
            return;
        } elseif (in_array($publicKeyId, $keyIds) && !in_array($publicKeyId, $rootIds) && $input->getOption('root')) {
            $keys['roles']['root']['threshold'] = (int) $input->getOption('threshold');
            $keys['roles']['root']['keyids'][] = $publicKeyId;
            $skipKeyAddition = true;
        }

        /**
         * Assemble the key data & delete all signatures.
         * The file needs to be re-signed for any change.
         * No need to add the key if it existed already and we wanted to assign it to the root role.
         */
        $data['signatures'] = array();
        if (false === $skipKeyAddition) {
            $keys['keys'][$publicKeyId] = array(
                'keytype' => 'OPENSSL_KEYTYPE_RSA',
                'keyval' => array(
                    'public' => trim($openssl->getPublicKey())
                )
            );
            $keys['roles']['manifest']['threshold'] = (int) $input->getOption('threshold');
            $keys['roles']['manifest']['keyids'][] = $publicKeyId;
        }
        
        /**
         * Check if we also wanted this key to become a root key. We add it
         * automatically if it's the first key being registered. We'd have
         * added it above already to root if it was registered previously
         * to the manifest role only and --root was set.
         */
        if ($input->getOption('root') && count($keys['roles']['root']['keyids']) == 0) {
            $keys['roles']['root']['threshold'] = (int) $input->getOption('threshold');
            $keys['roles']['root']['keyids'][] = $publicKeyId;
        }
        $data['signed'] = $keys;

        $flags = 0;
        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            $flags = JSON_PRETTY_PRINT;
        }
        $res = file_put_contents(self::KEYS_FILE, json_encode($data, $flags), LOCK_EX);
        if (!$res) {
            $output->writeln('<error>Failed to write data '.self::KEYS_FILE.' to '.realpath(self::KEYS_FILE).'.</error>');
            return 1;
        }
        $output->writeln('<info>'.self::KEYS_FILE.' has been created and populated with your key.</info>');
        $output->writeln('<info>Don\'t forget to sign it!</info>');
           
    }
}
