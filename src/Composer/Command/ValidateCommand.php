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

use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Json\JsonFile;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ValidateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('validate')
            ->setDescription('validates a composer.json')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'path to composer.json file', getcwd().'/composer.json')
            ))
            ->setHelp(<<<EOT
The validate command validates a given composer.json
<info>php composer.phar validate</info> for current location
or
<info>php composer.phar validate /path/to/composer.json</info> for custom location

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('file');

        if (!is_readable($file)) {
            throw new \InvalidArgumentException('composer.json not found '.$file);
        }

        $result = JsonFile::parseJson(file_get_contents($file));
        $output->writeln('<info>valid</info> '.$file.' is valid');
    }
}
