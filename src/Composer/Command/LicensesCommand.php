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
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Benoît Merlet <benoit.merlet@gmail.com>
 */
class LicensesCommand extends Command
{
    /**
     * @var list of packages to use
     */

    private $packageList = array();

    protected function configure()
    {
        $this
            ->setName('licenses')
            ->setDescription('Show information about licenses of dependencies')
            ->setDefinition(array(
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
            ))
            ->setHelp(<<<EOT
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

        $versionParser = new VersionParser;

        if ($input->getOption('no-dev')) {
            $this->findRequiresPackages($repo, $root);
        } else {
            $this->addToPackageList($repo->getPackages());
        }

        ksort($this->packageList);

        switch ($format = $input->getOption('format')) {
            case 'text':
                $output->writeln('Name: <comment>'.$root->getPrettyName().'</comment>');
                $output->writeln('Version: <comment>'.$versionParser->formatVersion($root).'</comment>');
                $output->writeln('Licenses: <comment>'.(implode(', ', $root->getLicense()) ?: 'none').'</comment>');
                $output->writeln('Dependencies:');

                $table = $this->getHelperSet()->get('table');
                $table->setLayout(TableHelper::LAYOUT_BORDERLESS);
                $table->setHorizontalBorderChar('');
                foreach ($this->packageList as $package) {
                    $table->addRow(array(
                        $package->getPrettyName(),
                        $versionParser->formatVersion($package),
                        implode(', ', $package->getLicense()) ?: 'none',
                    ));
                }
                $table->render($output);
                break;

            case 'json':
                foreach ($this->packageList as $package) {
                    $dependencies[$package->getPrettyName()] = array(
                        'version' => $versionParser->formatVersion($package),
                        'license' => $package->getLicense(),
                    );
                }

                $output->writeln(JsonFile::encode(array(
                    'name'         => $root->getPrettyName(),
                    'version'      => $versionParser->formatVersion($root),
                    'license'      => $root->getLicense(),
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
     * @param RepositoryInterface $repo
     * @param PackageInterface    $package
     */
    private function findRequiresPackages(RepositoryInterface $repo, PackageInterface $package)
    {
        $requires = array_keys($package->getRequires());

        $packageListNames = array_keys($this->packageList);
        $packages = array_filter(
            $repo->getPackages(),
            function ($package) use ($requires, $packageListNames) {
                return in_array($package->getName(), $requires) && !in_array($package->getName(), $packageListNames);
            }
        );

        $this->addToPackageList($packages);

        foreach ($packages as $package) {
            $this->findRequiresPackages($repo, $package);
        }
    }

    /**
     * Adds packages to the package list
     *
     * @param array $packages the list of packages to add
     */
    public function addToPackageList($packages)
    {
        foreach ($packages as $package) {
            $this->packageList[$package->getName()] = $package;
        }
    }
}
