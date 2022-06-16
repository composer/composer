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
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\PackageInfo;
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
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('licenses')
            ->setDescription('Shows information about licenses of dependencies.')
            ->setDefinition(array(
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text, json or summary', 'text', ['text', 'json', 'summary']),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
            ))
            ->setHelp(
                <<<EOT
The license command displays detailed information about the licenses of
the installed dependencies.

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
        $repo = $composer->getRepositoryManager()->getLocalRepository();

        if ($input->getOption('no-dev')) {
            $packages = $this->filterRequiredPackages($repo, $root);
        } else {
            $packages = $this->appendPackages($repo->getPackages(), array());
        }

        ksort($packages);
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
                $table->setHeaders(array('Name', 'Version', 'Licenses'));
                foreach ($packages as $package) {
                    $link = PackageInfo::getViewSourceOrHomepageUrl($package);
                    if ($link !== null) {
                        $name = '<href='.OutputFormatter::escape($link).'>'.$package->getPrettyName().'</>';
                    } else {
                        $name = $package->getPrettyName();
                    }

                    $table->addRow(array(
                        $name,
                        $package->getFullPrettyVersion(),
                        implode(', ', $package instanceof CompletePackageInterface ? $package->getLicense() : array()) ?: 'none',
                    ));
                }
                $table->render();
                break;

            case 'json':
                $dependencies = array();
                foreach ($packages as $package) {
                    $dependencies[$package->getPrettyName()] = array(
                        'version' => $package->getFullPrettyVersion(),
                        'license' => $package instanceof CompletePackageInterface ? $package->getLicense() : array(),
                    );
                }

                $io->write(JsonFile::encode(array(
                    'name' => $root->getPrettyName(),
                    'version' => $root->getFullPrettyVersion(),
                    'license' => $root->getLicense(),
                    'dependencies' => $dependencies,
                )));
                break;

            case 'summary':
                $usedLicenses = array();
                foreach ($packages as $package) {
                    $licenses = $package instanceof CompletePackageInterface ? $package->getLicense() : array();
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

                $rows = array();
                foreach ($usedLicenses as $usedLicense => $numberOfDependencies) {
                    $rows[] = array($usedLicense, $numberOfDependencies);
                }

                $symfonyIo = new SymfonyStyle($input, $output);
                $symfonyIo->table(
                    array('License', 'Number of dependencies'),
                    $rows
                );
                break;
            default:
                throw new \RuntimeException(sprintf('Unsupported format "%s".  See help for supported formats.', $format));
        }

        return 0;
    }

    /**
     * Find package requires and child requires
     *
     * @param  array<string, PackageInterface> $bucket
     * @return array<string, PackageInterface>
     */
    private function filterRequiredPackages(RepositoryInterface $repo, PackageInterface $package, array $bucket = array()): array
    {
        $requires = array_keys($package->getRequires());

        $packageListNames = array_keys($bucket);
        $packages = array_filter(
            $repo->getPackages(),
            fn ($package): bool => in_array($package->getName(), $requires) && !in_array($package->getName(), $packageListNames)
        );

        $bucket = $this->appendPackages($packages, $bucket);

        foreach ($packages as $package) {
            $bucket = $this->filterRequiredPackages($repo, $package, $bucket);
        }

        return $bucket;
    }

    /**
     * Adds packages to the package list
     *
     * @param  PackageInterface[]              $packages the list of packages to add
     * @param  array<string, PackageInterface> $bucket   the list to add packages to
     * @return array<string, PackageInterface>
     */
    public function appendPackages(array $packages, array $bucket): array
    {
        foreach ($packages as $package) {
            $bucket[$package->getName()] = $package;
        }

        return $bucket;
    }
}
