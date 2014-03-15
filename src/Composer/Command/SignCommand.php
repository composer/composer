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
immediately tag the new release.

The signature is applied by signing a generated manifest.json file which
lists all of the packaged files, their checksums, and file sizes.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Pre-Step TODO
        // Check if Manifest is already validly signed, and either 1) report it
        // if signed by this key or b) append the signature for this key
        $manifestAssembler = new Manifest;
        $bencode = new Bencode;
        $openssl = new Openssl;
        $openssl->importPrivateKey($input->getOption('private-key'), $input->getOption('passphrase'));
        $manifest = $manifestAssembler->assemble();
        $signable = array(
            'threshold' => 1,
            'public-keys' => array(
                array(
                    'keyid' => hash('sha256', $openssl->getPublicKey()),
                    'key' => $openssl->getPublicKey()
                )
            ),
            'files' => $manifest
        );
        $canonical = $bencode->encode($signable);
        $signature = $openssl->sign($canonical);
        $signedManifest = array(
            'signatures' => array(
                array(
                    'keyid' => hash('sha256', $openssl->getPublicKey()),
                    'sig' => $signature
                )
            ),
            'signed' => $signable
        );
        file_put_contents('manifest.json', json_encode($signedManifest));
    }
}
