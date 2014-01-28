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

use Composer\Package\AliasPackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\PlatformRepository;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dependency status command
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class DependencyStatusCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('dep-status')
            ->setDescription('Show a status of dependencies')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::OPTIONAL, 'Show concrete package status.'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Show dev requirements.'),
                new InputOption('problem', 'p', InputOption::VALUE_NONE, 'Show only problem (missed or outdated) dependencies.')
            ))
            ->setHelp(<<<EOT
The dep-status command display status of root package requirements:
what packages are missed, changed or outdated; what package versions are installed
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, $this->getName(), $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);
        $composer->getEventDispatcher()->dispatchCommandEvent(ScriptEvents::PRE_DEPENDENCY_STATUS_CMD, true);

        $dev = $input->getOption('dev');
        $packages = $this->getRequires($dev);
        if ($package = $input->getArgument('package')) {
            if (!isset($packages[$package])) {
                throw new \InvalidArgumentException(sprintf(
                    'Package "%s" is not found in requirements.%s',
                    $package,
                    $dev ? ' If it is a dev requirement add --dev flag' : ''
                ));
            }

            $packages = array($package => $packages[$package]);
        }

        $packages = $this->fetchInstalledPackages($packages);

        // Sort packages by name in keys
        ksort($packages);

        foreach ($packages as $name => $package) {
            /** @var \Composer\Package\LinkConstraint\LinkConstraintInterface $required */
            $required = $package['required'];
            /** @var \Composer\Package\PackageInterface $installed */
            $installed = !empty($package['installed']) ? $package['installed'] : null;

            $info = array(
                'name' => $name,
                'dev' => $package['dev'],
                'required' => $required->getPrettyString(),
                'path' => !empty($package['path']) ? $package['path'] : false
            );

            if ($installed) {
                $info['installed'] = $installed->getPrettyVersion();
                $info['status'] = 'outdated';
                $candidates = array($package['installed']);
                if (!empty($package['aliases'])) {
                    $candidates = array_merge($candidates, $package['aliases']);
                }
                /** @var \Composer\Package\PackageInterface $candidate */
                foreach ($candidates as $candidate) {
                    if ($required->matches(new VersionConstraint('==', $candidate->getVersion()))) {
                        $info['status'] = 'ok';
                        $info['installed'] = $candidate->getPrettyVersion();
                        break;
                    }
                }

                if ($installed->getStability() === 'dev') {
                    if ($installed->getInstallationSource() === 'source') {
                        $info['installed'] = $info['installed'] . '#' . $installed->getSourceReference();
                    } elseif ($installed->getInstallationSource() === 'dist') {
                        $info['installed'] = $info['installed'] . '#' . $installed->getDistReference();
                    }
                }
                // Skip not problem packages
                if ($input->getOption('problem') && $info['status'] !== 'outdated') {
                    continue;
                }
            } else {
                $info['installed'] = false;
                $info['status'] = 'missed';
            }

            $this->statusOutput($info, $output);
            $output->writeln('');
        }

        // Dispatch post-status-command
        $composer->getEventDispatcher()->dispatchCommandEvent(ScriptEvents::POST_DEPENDENCY_STATUS_CMD, true);
    }

    /**
     * @param bool $dev
     *
     * @return array
     */
    protected function getRequires($dev = false)
    {
        $composer = $this->getComposer();
        $root = $composer->getPackage();
        $requires = array();
        /** @var \Composer\Package\Link $link */
        foreach ($root->getRequires() as $name => $link) {
            $requires[$name]['required'] = $link->getConstraint();
            $requires[$name]['dev'] = false;
        }
        if ($dev) {
            foreach ($root->getDevRequires() as $name => $link) {
                $requires[$name]['required'] = $link->getConstraint();
                $requires[$name]['dev'] = true;
            }
        }

        return $requires;
    }

    /**
     * @param array $packages
     *
     * @return array
     */
    private function fetchInstalledPackages(array $packages)
    {
        $composer = $this->getComposer();
        $installedRepo = $composer->getRepositoryManager()->getLocalRepository();
        $platformRepo = new PlatformRepository();
        $im = $composer->getInstallationManager();

        /** @var \Composer\Package\PackageInterface $package */
        foreach ($installedRepo->getPackages() as $package) {
            // Skip not directly required packages
            if (!isset($packages[$package->getName()])) {
                continue;
            }

            // Skip aliases but save them for version processing
            if ($package instanceof AliasPackage) {
                $packages[$package->getName()]['aliases'][] = $package;
                continue;
            }

            $packages[$package->getName()]['installed'] = $package;

            $installPath = $im->getInstallPath($package);
            $packages[$package->getName()]['path'] = $installPath;
        }
        foreach ($platformRepo->getPackages() as $package) {
            // Get only required platform packages
            if (!isset($packages[$package->getName()])) {
                continue;
            }

            $packages[$package->getName()]['installed'] = $package;
        }

        return $packages;
    }

    /**
     * @param array           $info
     * @param OutputInterface $output
     */
    private function statusOutput($info, OutputInterface $output)
    {
        $title = $info['status'] !== 'ok' ? "<error>{$info['name']}</error>" : $info['name'];
        $output->writeln($title);
        $this->writeln($output, 'status', $info['status']);
        $this->writeln($output, 'dev', $info['dev'] ? 'yes' : 'no');
        $this->writeln($output, 'required', $info['required'], $info['status'] !== 'ok');
        if ($info['installed']) {
            $this->writeln($output, 'installed', $info['installed'], $info['status'] !== 'ok');
        }
        if ($info['path']) {
            $this->writeln($output, 'path', $info['path']);
        }
    }

    private function writeln(OutputInterface $output, $title, $value, $error = false)
    {
        $value = $error ? "<error>$value</error>" : $value;
        $output->writeln(sprintf('<info>%s</info> : %s', str_pad($title, 10, ' '), $value));
    }
}
