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

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * @author Davey Shafik <me@daveyshafik.com>
 */
class ExecCommand extends BaseCommand
{
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('binary')) {
            $suggestions->suggestValues($this->getBinaries(false));
        }
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('exec')
            ->setDescription('Executes a vendored binary/script.')
            ->setDefinition(array(
                new InputOption('list', 'l', InputOption::VALUE_NONE),
                new InputArgument('binary', InputArgument::OPTIONAL, 'The binary to run, e.g. phpunit'),
                new InputArgument(
                    'args',
                    InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                    'Arguments to pass to the binary. Use <info>--</info> to separate from composer arguments'
                ),
            ))
            ->setHelp(
                <<<EOT
Executes a vendored binary/script.

Read more at https://getcomposer.org/doc/03-cli.md#exec
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->requireComposer();
        if ($input->getOption('list') || !$input->getArgument('binary')) {
            $bins = $this->getBinaries(true);
            if (count($bins) > 0) {
                $binDir = $composer->getConfig()->get('bin-dir');

                throw new \RuntimeException("No binaries found in composer.json or in bin-dir ($binDir)");
            }

            $this->getIO()->write(
                <<<EOT
<comment>Available binaries:</comment>
EOT
            );

            foreach ($bins as $bin) {
                $this->getIO()->write(
                    <<<EOT
<info>- $bin</info>
EOT
                );
            }

            return 0;
        }

        $binary = $input->getArgument('binary');

        $dispatcher = $composer->getEventDispatcher();
        $dispatcher->addListener('__exec_command', $binary);

        // If the CWD was modified, we restore it to what it was initially, as it was
        // most likely modified by the global command, and we want exec to run in the local working directory
        // not the global one
        if (getcwd() !== $this->getApplication()->getInitialWorkingDirectory() && $this->getApplication()->getInitialWorkingDirectory() !== false) {
            try {
                chdir($this->getApplication()->getInitialWorkingDirectory());
            } catch (\Exception $e) {
                throw new \RuntimeException('Could not switch back to working directory "'.$this->getApplication()->getInitialWorkingDirectory().'"', 0, $e);
            }
        }

        return $dispatcher->dispatchScript('__exec_command', true, $input->getArgument('args'));
    }

    private function getBinaries(bool $forDisplay): array
    {
        $composer = $this->getComposer();
        $binDir = $composer->getConfig()->get('bin-dir');
        $bins = glob($binDir . '/*');
        $localBins = $composer->getPackage()->getBinaries();
        if ($forDisplay) {
            $localBins = array_map(function ($e) {
                return "$e (local)";
            }, $localBins);
        }

        $binaries = [];
        foreach (array_merge($bins, $localBins) as $bin) {
            // skip .bat copies
            if (isset($previousBin) && $bin === $previousBin.'.bat') {
                continue;
            }

            $previousBin = $bin;
            $binaries[] = basename($bin);
        }

        return $binaries;
    }
}
