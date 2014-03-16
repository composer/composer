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
                new InputArgument('private-key', InputArgument::REQUIRED, 'Path to the private key which will be used to sign the package'),
            ))
            ->setHelp(<<<EOT
The add-dev-key command, imports a developer's private key, adds their
public key to the keys.json file, registers it as being authorised to
sign manifests, and then signs the keys.json file. Only the creator of
the keys.json file may add a second key if delegating releases to other
maintainers.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = array();

        /**
         * Validate inputs
         */
        // TODO
        $passphrase = null;
        $answer = $this->getIO()->askAndHideAnswer('Enter a passphrase if the private key is encrypted:');
        if (strlen($answer) > 0) {
            $passphrase = $answer;
        }

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
        } else {
            $keys = array(
                'expires' => '',
                'keys' => array(),
                'roles' => array(
                    'manifest' => array(
                        'keyids' => array(),
                        'threshold' => 1
                    )
                )
            );
        }

        /**
         * Check if the current key has already been added
         * If it has, we'll switch to re-signing keys.json for any changes or abort.
         */
        if (count($keys['keys']) > 0) {
            $keyIds = array_keys($keys['keys']);
            foreach ($keyIds as $id) {
                if ($publicKeyId == $id) {
                    $output->writeln('<warning>This key has previously been added to '.self::KEYS_FILE.'</warning>');
                    $output->writeln('<info>Checking if '.self::KEYS_FILE.' should be re-signed...</info>');
                    $canonical = $bencode->encode($keys);
                    $signature = $openssl->sign($canonical);
                    $needle = array(
                        'keyid' => $publicKeyId,
                        'method' => 'OPENSSL_ALGO_SHA1',
                        'signature' => $signature
                    );
                    if (in_array($needle, $data['signatures'])) {
                        $output->writeln('<warning>You have already correctly signed '.self::KEYS_FILE.' with this key</warning>');
                        $output->writeln('<warning>Aborting...</warning>');
                        return;
                    } else {
                        $output->writeln('<info>The '.self::KEYS_FILE.' file will be re-signed with this key as it has been changed.</info>');
                        $output->writeln('<warning>Remember to review all changes when this command completes!</warning>');
                        foreach ($data['signatures'] as $key => $sig) {
                            if ($sig['keyid'] == $publicKeyId) {
                                unset($data['signatures'][$key]);
                            }
                            $data['signatures'][] = $needle;
                            continue;
                        }
                        $flags = 0;
                        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
                            $flags = JSON_PRETTY_PRINT;
                        }
                        $res = file_put_contents(self::KEYS_FILE, json_encode($data, $flags), LOCK_EX);
                        if (!$res) {
                            $output->writeln('<error>Failed to write signed '.self::KEYS_FILE.' to '.realpath(self::KEYS_FILE).'.</error>');
                            return 1;
                        }
                        $output->writeln('<info>The '.self::KEYS_FILE.' file has been re-signed successfully.</info>');
                        return;
                    }
                }
            }
        }

        /**
         * If we have not already added a key, or need to re-sign for new keys..
         */
        $keys['keys'][$publicKeyId] = array(
            'keytype' => 'OPENSSL_KEYTYPE_RSA', // RSA is the only one we support right now.
            'keyval' => array(
                'public' => $openssl->getPublicKey()
            )
        );
        $keys['roles']['manifest']['threshold'] = (int) $input->getOption('threshold'); // TODO: do not override existing value!
        $keys['roles']['manifest']['keyids'][] = $publicKeyId;
        $canonical = $bencode->encode($keys);
        $signature = $openssl->sign($canonical);

        $signedKeys = array(
            'signatures' => array(
                array(
                    'key-id' => $publicKeyId,
                    'method' => 'OPENSSL_ALGO_SHA1',
                    'signature' => $signature
                )
            ),
            'signed' => $keys
        );
        $flags = 0;
        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            $flags = JSON_PRETTY_PRINT;
        }
        $res = file_put_contents(self::KEYS_FILE, json_encode($signedKeys, $flags), LOCK_EX);
        if (!$res) {
            $output->writeln('<error>Failed to write signed '.self::KEYS_FILE.' to '.realpath(self::KEYS_FILE).'.</error>');
            return 1;
        }
        $output->writeln('<info>'.self::KEYS_FILE.' has been created and populated with your key.</info>');
           
    }
}
