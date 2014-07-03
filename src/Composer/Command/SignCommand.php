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
class SignCommand extends Command
{

    const MANIFEST_FILE = 'manifest.json';
    const KEYS_FILE = 'keys.json';

    protected function configure()
    {
        $this
            ->setName('sign')
            ->setDescription('Sign a package using a developer private key')
            ->setDefinition(array(
                new InputArgument('private-key', InputArgument::REQUIRED, 'Path to the private key which will be used to sign the package'),
            ))
            ->setHelp(<<<EOT
The sign command uses the given private key to cryptographically sign a
package, e.g. the current git repository. When using git, you should sign
the package, commit changes to (or git add) the manifest.json file, and
immediately tag the new release. You may defer tagging if multiple signatures
are required (to meet the optional threshold requirement).

The signature is applied by signing a generated manifest.json file which
lists all of the packaged files, their checksums, and file sizes.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        if (!file_exists(self::KEYS_FILE) || !is_readable(self::KEYS_FILE)) {
            $output->writeln('<error>You must first use the add-dev-key/sign-dev-keys commands to generate a '.self::KEYS_FILE.' file.</error>');
            return 1;
        }
        $passphrase = null;
        $answer = $this->getIO()->askAndHideAnswer('Enter a passphrase if the private key is encrypted:');
        if (strlen($answer) > 0) {
            $passphrase = $answer;
        }

        /**
         * Initialise helper classes
         */
        $manifestAssembler = new Manifest(null, array(self::MANIFEST_FILE));
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
         * Verify that this private key is allowed to sign manifests, i.e.
         * its public key's ID should have been registered to keys.json
         */
        $keysData = json_decode(file_get_contents(self::KEYS_FILE), true);
        if (!in_array($publicKeyId, $keysData['signed']['roles']['manifest']['keyids'])) {
            $output->writeln('<error>The provided private key is not authorised to sign manifests in '.self::KEYS_FILE.'.</error>');
            return 1;
        }

        /**
         * Validate the keys.json file signatures (before it's added to the manifest!)
         */
        $openssl2 = new Openssl;
        $canonical = $bencode->encode($keysData['signed']);
        foreach ($keysData['signatures'] as $keysSig) {
            $pubkey = $keysData['signed']['keys'][$keysSig['keyid']]['keyval']['public'];
            $openssl2->setPublicKey($pubkey);
            if (!$openssl2->verify($canonical, $keysSig['sig'])) {
                $output->writeln('<error>The signature in '.self::KEYS_FILE.' from public key '.$keysSig['keyid'].' is incorrect.</error>');
                $output->writeln('<error>Verify that '.self::KEYS_FILE.' is correctly signed before proceeding.</error>');
                return 1;
            }
        }
        // TODO: check that keys.json threshold signatures is met

        /**
         * Assemble the manifest and sign it
         */
        try {
            $manifest = $manifestAssembler->assemble();
        } catch (\Exception $e) {
            $output->writeln('<error>Manifest assembly has failed.</error>');
            throw $e;
        }
        
        $signable = array(
            'files' => $manifest
        );
        $canonical = $bencode->encode($signable);
        $signature = $openssl->sign($canonical);

        /**
         * Process any pre-existing manifest.json
         */
        $otherValidSigs = array();
        if (file_exists(self::MANIFEST_FILE)) {
            if (!is_readable(self::MANIFEST_FILE)) {
                $output->writeln('<error>A '.self::MANIFEST_FILE.' file already exists but is not readable by this process.</error>');
                return 1;
            }
            $existing = json_decode(file_get_contents(self::MANIFEST_FILE), true);
            if (!$existing) {
                unlink(self::MANIFEST_FILE);
                $output->writeln('<warning>The existing '.self::MANIFEST_FILE.' file was invalid and will be replaced</warning>');
            }
            if (isset($existing['signatures'])
            && count($existing['signatures']) > 0
            && isset($existing['signed'])) {
                foreach ($existing['signatures'] as $sig) {
                    if ($sig['keyid'] == $publicKeyId) {
                        $canonical2 = $bencode->encode($existing['signed']);
                        if ($canonical2 == $canonical
                        && $sig['sig'] == $openssl->sign($canonical2)) {
                            $output->writeln('<info>The '.self::MANIFEST_FILE.' has not changed and has already been correctly signed with this private key.</info>');
                            return;
                        }
                    } else {
                        // TODO: Check if the other sigs are valid otherwise remove them!
                        $otherValidSigs[] = $sig;
                    }
                }
            }
        }

        /**
         * Create/Update the manifest.json file
         */
        $signedManifest = array(
            'signatures' => array(
                array(
                    'keyid' => $publicKeyId,
                    'method' => 'OPENSSL_ALGO_SHA1',
                    'sig' => $signature
                )
            ),
            'signed' => $signable
        );
        $signedManifest['signatures'] = array_merge($otherValidSigs, $signedManifest['signatures']);
        $threshold = $keysData['signed']['roles']['manifest']['threshold'];
        $output->writeln('<info>Signature calculated. '.count($otherValidSigs).' other valid signatures are currently present.</info>');
        $output->writeln('<info>'.count($signedManifest['signatures']).' signatures now exist for '.self::MANIFEST_FILE
            .', with a required threshold of '.$threshold.'</info>');
        if (count($signedManifest['signatures']) < $threshold) {
            $output->writeln('<warning>Ensure that the threshold number of signatures is reached before tagging a release!</warning>');
        }
        $flags = 0;
        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            $flags = JSON_PRETTY_PRINT;
        }
        $res = file_put_contents(self::MANIFEST_FILE, json_encode($signedManifest, $flags), LOCK_EX);
        if (!$res) {
            $output->writeln('<error>Failed to write signed manifest to '.realpath(self::MANIFEST_FILE).'.</error>');
            return 1;
        }
    }
}
