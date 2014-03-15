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
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Util\Bencode;
use Composer\Util\Openssl;
use Composer\Package\Manifest;

/**
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class SignCommand extends Command
{

    const MANIFEST_FILE = 'metadata.json';

    protected function configure()
    {
        $this
            ->setName('sign')
            ->setDescription('Sign a package using a developer private key')
            ->setDefinition(array(
                new InputOption('passphrase', 'p', InputOption::VALUE_REQUIRED, 'Set a passphrase to allow encrypted private keys to be used', null),
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

        $manifestAssembler = new Manifest;
        $bencode = new Bencode;
        $openssl = new Openssl;
        try {
            $openssl->importPrivateKey($input->getOption('private-key'), $input->getOption('passphrase'));
            $publicKeyId = hash('sha256', trim($openssl->getPublicKey(), ' '));
        } catch (\Exception $e) {
            $this->writeln('<error>Invalid private key or passphrase</error>');
            throw $e;
        }
        $manifest = $manifestAssembler->assemble();
        
        // TODO: split keys out separately to enable independent key management
        $signable = array(
            'threshold' => 1,
            'public-keys' => array(
                array(
                    'keyid' => $publicKeyId,
                    'key' => $openssl->getPublicKey()
                )
            ),
            'files' => $manifest
        );
        $canonical = $bencode->encode($signable);
        $signature = $openssl->sign($canonical);

        $otherValidSigs = array();
        if (file_exists(MANIFEST_FILE)) {
            if (!is_readable(MANIFEST_FILE)) {
                $this->writeln('<error>A '.MANIFEST_FILE.' file already exists but is not readable by this process.</error>');
                return 1;
            }
            $existing = json_decode(file_get_contents(MANIFEST_FILE), true);
            if (!$existing) {
                unlink(MANIFEST_FILE);
                $this->writeln('<info>The existing '.MANIFEST_FILE.' file was invalid and will be replaced</info>');
            }
            if (isset($existing['signatures'])
            && count($existing['signatures']) > 0
            && isset($existing['signed'])) {
                foreach ($existing['signatures'] as $sig) {
                    if ($sig['key-id'] == $publicKeyId) {
                        $canonical2 = $bencode->encode($existing['signed']);
                        if ($canonical2 == $canonical
                        && $sig['signature'] == $this->openssl->sign($canonical2)) {
                            $this->writeln('<info>The '.MANIFEST_FILE.' has not changed and has already been correctly signed with this private key.</info>');
                            return; //0?
                        }
                    } else {
                        // TODO: Check if the other sigs are valid!
                        $otherValidSigs[] = $sig;
                    }
                }
            }
        }

        $signedManifest = array(
            'signatures' => array(
                array(
                    'key-id' => $publicKeyId,
                    'method' => 'OPENSSL_ALGO_SHA1',
                    'signature' => $signature
                )
            ),
            'signed' => $signable
        );
        $signedManifest['signatures'] += $otherValidSigs;

        $this->writeln('<info>Signature calculated. '.count($otherValidSigs).' other valid signatures are currently present.</info>');
        $this->writeln('<info>'.count($signedManifest['signatures']).' signatures exist for '.MANIFEST_FILE.', with a required threshold of '.$signable['threshold'].'</info>')
        if (count($signedManifest['signatures']) < $signable['threshold']) {
            $this->writeln('<warning>Ensure that the threshold number of signatures is reached before tagging a release!</warning>')
        }
        $res = file_put_contents(MANIFEST_FILE, json_encode($signedManifest), LOCK_EX);
        if (!$res) {
            $this->writeln('<error>Failed to write signed manifest to '.realpath(MANIFEST_FILE).'.</error>');
            return 1;
        }
    }
}
