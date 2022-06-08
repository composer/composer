<?php

namespace Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Util\Auditor;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputOption;

class AuditCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('audit')
            ->setDescription('Checks for security vulnerability advisories for packages in your composer.lock.')
            ->setDefinition(array(
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables auditing of require-dev packages.'),
                new InputOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format. Must be "table", "plain", or "summary".', Auditor::FORMAT_TABLE),
            ))
            ->setHelp(
                <<<EOT
The <info>audit</info> command checks for security vulnerability advisories for packages in your composer.lock.

If you do not want to include dev dependencies in the audit you can omit them with --no-dev

Read more at https://getcomposer.org/doc/03-cli.md#audit
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lockFile = Factory::getLockFile(Factory::getComposerFile());
        if (!Filesystem::isReadable($lockFile)) {
            $this->getIO()->writeError('<error>' . $lockFile . ' is not readable.</error>');
            return 1;
        }

        $composer = $this->requireComposer($input->getOption('no-plugins'), $input->getOption('no-scripts'));
        $locker = $composer->getLocker();
        $packages = $locker->getLockedRepository(!$input->getOption('no-dev'))->getPackages();
        $httpDownloader = $composer->getLoop()->getHttpDownloader();

        if (count($packages) === 0) {
            $this->io->writeError('No packages - skipping audit.');
            return 0;
        }

        $auditor = new Auditor($httpDownloader, $input->getOption('format'));
        return $auditor->audit($this->getIO(), $packages, false);
    }
}
