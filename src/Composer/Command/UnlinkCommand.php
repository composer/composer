<?php declare(strict_types=1);

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

use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Package\LinkedPackages;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UnlinkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('unlink')
            ->setDescription('Removes a package from the linked packages')
            ->setDefinition([
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to unlink, defaults to the package in the current directory.', null, function (CompletionInput $input): array {
                    return array_keys($this->createLinkedPackages($input->getOption('linked-repo-path'))->getLinks());
                }),
                new InputOption('linked-repo-path', null, InputOption::VALUE_REQUIRED, 'Directory holding the linked packages file, defaults to the Composer home directory.'),
            ])
            ->setHelp(
                <<<EOT
The <info>unlink</info> command removes a package from the linked packages.

<info>php composer.phar unlink acme/foo</info>

Without an argument, the package in the current directory is unlinked.

Read more at https://getcomposer.org/doc/03-cli.md#unlink
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $name = $input->getArgument('package');
        if (null === $name) {
            $composerFile = new JsonFile(Factory::getComposerFile(), null, $io);
            $composerJson = $composerFile->exists() ? $composerFile->read() : [];
            if (!isset($composerJson['name']) || !is_string($composerJson['name'])) {
                throw new \RuntimeException('Pass the name of the package to unlink, or run this command in the directory of the package.');
            }
            $name = $composerJson['name'];
        }

        $linkedPackages = $this->createLinkedPackages($input->getOption('linked-repo-path'));
        if (!$linkedPackages->unlink($name)) {
            throw new \RuntimeException($name.' is not linked in '.$linkedPackages->getFile().'.');
        }

        $io->writeError('<info>Unlinked '.$name.' from '.$linkedPackages->getFile().'</info>');

        return 0;
    }

    private function createLinkedPackages(?string $dir): LinkedPackages
    {
        return new LinkedPackages($dir ?? LinkedPackages::getDefaultDir(Factory::createConfig($this->getIO())), $this->getIO());
    }
}
