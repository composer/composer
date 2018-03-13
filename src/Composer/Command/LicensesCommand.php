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
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Benoît Merlet <benoit.merlet@gmail.com>
 */
class LicensesCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('licenses')
            ->setDescription('Shows information about licenses of dependencies.')
            ->setDefinition(array(
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
            ))
            ->setHelp(
                <<<EOT
The license command displays detailed information about the licenses of
the installed dependencies.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

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
                $tableStyle = $table->getStyle();
                $tableStyle->setVerticalBorderChar('');
                $tableStyle->setCellRowContentFormat('%s  ');
                $table->setHeaders(array('Name', 'Version', 'License'));
                foreach ($packages as $package) {
                    $table->addRow(array(
                        $package->getPrettyName(),
                        $package->getFullPrettyVersion(),
                        implode(', ', $package->getLicense()) ?: 'none',
                    ));
                }
                $table->render();
                break;

            case 'json':
                $dependencies = array();
                foreach ($packages as $package) {
                    $dependencies[$package->getPrettyName()] = array(
                        'version' => $package->getFullPrettyVersion(),
                        'license' => $package->getLicense(),
                    );
                }

                $io->write(JsonFile::encode(array(
                    'name' => $root->getPrettyName(),
                    'version' => $root->getFullPrettyVersion(),
                    'license' => $root->getLicense(),
                    'dependencies' => $dependencies,
                )));
                break;

            default:
                throw new \RuntimeException(sprintf('Unsupported format "%s".  See help for supported formats.', $format));
        }
    }

    /**
     * Find package requires and child requires
     *
     * @param  RepositoryInterface $repo
     * @param  PackageInterface    $package
     * @param  array               $bucket
     * @return array
     */
    private function filterRequiredPackages(RepositoryInterface $repo, PackageInterface $package, $bucket = array())
    {
        $requires = array_keys($package->getRequires());

        $packageListNames = array_keys($bucket);
        $packages = array_filter(
            $repo->getPackages(),
            function ($package) use ($requires, $packageListNames) {
                return in_array($package->getName(), $requires) && !in_array($package->getName(), $packageListNames);
            }
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
     * @param  array $packages the list of packages to add
     * @param  array $bucket   the list to add packages to
     * @return array
     */
    public function appendPackages(array $packages, array $bucket)
    {
        foreach ($packages as $package) {
            $bucket[$package->getName()] = $package;
        }

        return $bucket;
    }
}
