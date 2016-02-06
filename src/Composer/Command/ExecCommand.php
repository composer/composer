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
use Symfony\Component\Console\Input\InputArgument;

/**
 * @author Davey Shafik <me@daveyshafik.com>
 */
class ExecCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('exec')
            ->setDescription('Execute a vendored binary/script')
            ->setDefinition(array(
                new InputOption('list', 'l', InputOption::VALUE_NONE),
                new InputArgument('script', InputArgument::OPTIONAL, 'The script to run, e.g. phpunit'),
                new InputArgument(
                    'args',
                    InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                    'Arguments to pass to the script. Use <info>--</info> to separate from composer arguments'
                ),
            ))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $binDir = $this->getComposer()->getConfig()->get('bin-dir');
        if ($input->hasArgument('list') || !$input->hasArgument('script') || !$input->getArgument('script')) {
            $bins = glob($binDir . '/*');

            if (!$bins) {
                throw new \RuntimeException("No scripts found in bin-dir ($binDir)");
            }

            $this->getIO()->write(<<<EOT
<comment>Available scripts:</comment>
EOT
            );

            foreach ($bins as $bin) {
                $bin = basename($bin);
                $this->getIO()->write(<<<EOT
<info>- $bin</info>
EOT
                );
            }

            return;
        }

        $script = $input->getArgument('script');
        if (!file_exists($binDir . '/' . $script)) {
            throw new \RuntimeException("script '$script' not found in bin-dir ($binDir)");
        }

        if ($args = $input->getArgument('args')) {
            $args = " " . implode(" ", $args);
        }

        $this->getIO()->write(<<<EOT
<comment>Executing $script$args:</comment>
EOT
        );

        passthru($binDir . '/' . $script . $args);
    }
}
