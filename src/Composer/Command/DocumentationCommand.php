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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * @author r408512 <>
 */
class DocumentationCommand extends BaseCommand
{
    protected $documentationUri = 'https://raw.githubusercontent.com/composer/composer/master/doc/03-cli.md';

    protected function configure()
    {
        $this
            ->setName('documentation')
            ->setAliases(array('docs'))
            ->setDescription('Get documentation for composer command.')
            ->setDefinition(array(
                new InputArgument('name', InputArgument::REQUIRED, 'Command name to get documentation for.'),
            ))
            ->setHelp(
                <<<EOT
<info>php composer.phar about</info>
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $docs = [];
        $aliases = [];

        $lines = explode("\n", file_get_contents($this->documentationUri) );

        for ($i=0; $i < sizeof($lines); $i++) 
        { 
            if( substr($lines[$i], 0, 3) == '## '  &&  $i < (sizeof($lines) - 1) )
            {
                $command = trim( substr($lines[$i], 3) );

                // get alias separated by "/"
                if( strpos($command, '/') )
                {
                    $alias = explode('/', $command);

                    $command = trim($alias[0]);

                    $aliases[ trim($alias[1]) ] = $command;
                }

                // get alias in ()
                if( false !== $pos = stripos($command, "(") )
                {
                    $alias[0] = trim(substr($command, 0, $pos));
                    $alias[1] = trim(substr($command, $pos), '()');
                    $command = $alias[0];
                    $aliases[$alias[1]] = $command;
                }

                $docs[$command] = $lines[$i].PHP_EOL;

                $i++;

                while( $i < sizeof($lines)  && substr($lines[$i], 0, 3) != '## ' )
                {               
                    $docs[$command] .= isset($lines[$i])  ?  $lines[$i].PHP_EOL  :  '';
                    $i++;
                }

                $i--;
            }
        }

        $io = $this->getIO();
        $name = $input->getArgument('name');

        if( array_key_exists($name, $docs) )
        {
            $io->write(PHP_EOL.$docs[$name].PHP_EOL);
        }
        elseif( array_key_exists($name, $aliases) )
        {
             $io->write(PHP_EOL.$docs[$aliases[$name]].PHP_EOL);
        }
        else
        {
            $io->writeError('<error>Command "'.$name.'"not found in documentation.</error>');
        }
    }

}
