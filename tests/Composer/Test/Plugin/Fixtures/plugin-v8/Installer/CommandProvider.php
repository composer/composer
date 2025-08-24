<?php

namespace Installer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class CommandProvider implements CommandProviderCapability
{
    public function __construct(array $args)
    {
        if (!$args['composer'] instanceof \Composer\Composer) {
            throw new \RuntimeException('Expected a "composer" key');
        }
        if (!$args['io'] instanceof \Composer\IO\IOInterface) {
            throw new \RuntimeException('Expected an "io" key');
        }
        if (!$args['plugin'] instanceof Plugin8) {
            throw new \RuntimeException('Expected a "plugin" key with my own plugin');
        }
    }

    public function getCommands()
    {
        return array(new Command);
    }
}

class Command extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('custom-plugin-command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Executing');

        return 5;
    }
}
