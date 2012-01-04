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

use Composer\Json\JsonFile;
use Composer\Command\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


if (!defined('JSON_PRETTY_PRINT')) {
    define('JSON_PRETTY_PRINT', 128);
}

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 */
class InitCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Creates a basic composer.json file in current directory.')
            ->setDefinition(array(
                new InputOption('name', null, InputOption::VALUE_NONE, 'Name of the package'),
                new InputOption('description', null, InputOption::VALUE_NONE, 'Description of package'),
                // new InputOption('version', null, InputOption::VALUE_NONE, 'Version of package'),
                new InputOption('homepage', null, InputOption::VALUE_NONE, 'Homepage of package'),
            ))
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.json file from the
current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file.

<info>php composer.phar install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();

        $options = array_filter(array_intersect_key($input->getOptions(), array_flip(array('name','description'))));

        $file = new JsonFile("composer.json");

        $indentSize = 2;
        $lines = array();

        foreach ($options as $key => $value) {
            $lines[] = sprintf('%s%s: %s', str_repeat(' ', $indentSize), json_encode($key), json_encode($value));
        }

        $json = "{\n" . implode(",\n", $lines) . "\n}\n";

        if ($input->isInteractive()) {
            $output->writeln(array(
                '',
                $json,
                ''
            ));
            if (!$dialog->askConfirmation($output, $dialog->getQuestion('Do you confirm generation', 'yes', '?'), true)) {
                $output->writeln('<error>Command aborted</error>');

                return 1;
            }
        }

        $file->write($options);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getDialogHelper();
        $dialog->writeSection($output, 'Welcome to the Composer config generator');

        // namespace
        $output->writeln(array(
            '',
            'This command will guide you through creating your composer.json config.',
            '',
        ));

        $cwd = realpath(".");

        $name = $input->getOption('name') ?: basename($cwd);
        $name = $dialog->ask(
            $output,
            $dialog->getQuestion('Package name', $name),
            $name
        );
        $input->setOption('name', $name);

        $description = $input->getOption('description') ?: false;
        $description = $dialog->ask(
            $output,
            $dialog->getQuestion('Description', $description)
        );
        $input->setOption('description', $description);
    }

    protected function getDialogHelper()
    {
        $dialog = $this->getHelperSet()->get('dialog');
        if (!$dialog || get_class($dialog) !== 'Composer\Command\Helper\DialogHelper') {
            $this->getHelperSet()->set($dialog = new DialogHelper());
        }

        return $dialog;
    }
}