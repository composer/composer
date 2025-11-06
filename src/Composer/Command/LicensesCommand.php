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
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\RepositoryUtils;
use Composer\Util\PackageInfo;
use Composer\Util\PackageSorter;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Benoît Merlet <benoit.merlet@gmail.com>
 */
class LicensesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('licenses')
            ->setDescription('Shows information about licenses of dependencies')
            ->setDefinition([
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text, json or summary', 'text', ['text', 'json', 'summary']),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
                new InputOption('locked', null, InputOption::VALUE_NONE, 'Shows licenses from the lock file instead of installed packages.'),
            ])
            ->setHelp(
                <<<EOT
The license command displays detailed information about the licenses of
the installed dependencies.

Use --locked to show licenses from composer.lock instead of what's currently
installed in the vendor directory.

Read more at https://getcomposer.org/doc/03-cli.md#licenses
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'licenses', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $root = $composer->getPackage();

        if ($input->getOption('locked')) {
            if (!$composer->getLocker()->isLocked()) {
                throw new \UnexpectedValueException('Valid composer.json and composer.lock files are required to run this command with --locked');
            }
            $locker = $composer->getLocker();
            $repo = $locker->getLockedRepository(!$input->getOption('no-dev'));
            $packages = $repo->getPackages();
        } else {
            $repo = $composer->getRepositoryManager()->getLocalRepository();

            if ($input->getOption('no-dev')) {
                $packages = RepositoryUtils::filterRequiredPackages($repo->getPackages(), $root);
            } else {
                $packages = $repo->getPackages();
            }
        }

        $packages = PackageSorter::sortPackagesAlphabetically($packages);
        $io = $this->getIO();

        switch ($format = $input->getOption('format')) {
            case 'text':
                $io->write('Name: <comment>'.$root->getPrettyName().'</comment>');
                $io->write('Version: <comment>'.$root->getFullPrettyVersion().'</comment>');
                $io->write('Licenses: <comment>'.(implode(', ', $root->getLicense()) ?: 'none').'</comment>');
                $io->write('Dependencies:');
                $io->write('');

                $table = new Table($output);
                $table->setStyle('compact');
                $table->setHeaders(['Name', 'Version', 'Licenses']);
                foreach ($packages as $package) {
                    $link = PackageInfo::getViewSourceOrHomepageUrl($package);
                    if ($link !== null) {
                        $name = '<href='.OutputFormatter::escape($link).'>'.$package->getPrettyName().'</>';
                    } else {
                        $name = $package->getPrettyName();
                    }

                    $table->addRow([
                        $name,
                        $package->getFullPrettyVersion(),
                        implode(', ', $package instanceof CompletePackageInterface ? $package->getLicense() : []) ?: 'none',
                    ]);
                }
                $table->render();
                break;

            case 'json':
                $dependencies = [];
                foreach ($packages as $package) {
                    $dependencies[$package->getPrettyName()] = [
                        'version' => $package->getFullPrettyVersion(),
                        'license' => $package instanceof CompletePackageInterface ? $package->getLicense() : [],
                    ];
                }

                $io->write(JsonFile::encode([
                    'name' => $root->getPrettyName(),
                    'version' => $root->getFullPrettyVersion(),
                    'license' => $root->getLicense(),
                    'dependencies' => $dependencies,
                ]));
                break;

            case 'summary':
                $usedLicenses = [];
                foreach ($packages as $package) {
                    $licenses = $package instanceof CompletePackageInterface ? $package->getLicense() : [];
                    if (count($licenses) === 0) {
                        $licenses[] = 'none';
                    }
                    foreach ($licenses as $licenseName) {
                        if (!isset($usedLicenses[$licenseName])) {
                            $usedLicenses[$licenseName] = 0;
                        }
                        $usedLicenses[$licenseName]++;
                    }
                }

                // Sort licenses so that the most used license will appear first
                arsort($usedLicenses, SORT_NUMERIC);

                $rows = [];
                foreach ($usedLicenses as $usedLicense => $numberOfDependencies) {
                    $rows[] = [$usedLicense, $numberOfDependencies];
                }

                $symfonyIo = new SymfonyStyle($input, $output);
                $symfonyIo->table(
                    ['License', 'Number of dependencies'],
                    $rows
                );
                break;
            default:
                throw new \RuntimeException(sprintf('Unsupported format "%s".  See help for supported formats.', $format));
        }

        return 0;
    }
}
