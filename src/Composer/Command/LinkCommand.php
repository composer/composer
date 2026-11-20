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

use Composer\Console\Input\InputOption;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Package\LinkedPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class LinkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('link')
            ->setDescription('Links the current package so that projects can install it from its directory')
            ->setDefinition([
                new InputOption('as-version', null, InputOption::VALUE_REQUIRED, 'Version to expose the package as, e.g. 1.x-dev.'),
                new InputOption('linked-repo-path', null, InputOption::VALUE_REQUIRED, 'Directory holding the linked packages file, defaults to the Composer home directory.'),
            ])
            ->setHelp(
                <<<EOT
The <info>link</info> command links the package in the current directory, so that
projects can install it from there instead of from their repositories.

<info>php composer.phar link --as-version=1.x-dev</info>

Then run <info>php composer.phar update --with-linked-packages</info> in a project to use it.

Read more at https://getcomposer.org/doc/03-cli.md#link
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composerFile = new JsonFile(Factory::getComposerFile(), null, $io);
        if (!$composerFile->exists()) {
            throw new \RuntimeException($composerFile->getPath().' not found, run this command in the directory of the package to link.');
        }

        $composerJson = $composerFile->read();
        if (!isset($composerJson['name']) || !is_string($composerJson['name'])) {
            throw new \RuntimeException($composerFile->getPath().' must define a "name" to be linked.');
        }

        $linkedPackages = new LinkedPackages($input->getOption('linked-repo-path') ?? LinkedPackages::getDefaultDir(Factory::createConfig($io)), $io);
        $path = dirname((string) realpath($composerFile->getPath()));
        $version = $input->getOption('as-version');
        $linkedPackages->link($composerJson['name'], $path, $version);

        $io->writeError('<info>Linked '.$composerJson['name'].(null !== $version ? ' as '.$version : '').' from '.$path.' in '.$linkedPackages->getFile().'</info>');

        return 0;
    }
}
