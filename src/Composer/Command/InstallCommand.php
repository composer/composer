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

use Composer\DependencyResolver;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Operation;
use Composer\Package\LinkConstraint\VersionConstraint;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription('Parses the composer.json file and downloads the needed dependencies.')
            ->setHelp(<<<EOT
The <info>install</info> command reads the composer.json file from the
current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file.

<info>php composer install</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        // creating repository pool
        $pool = new Pool;
        $pool->addRepository($composer->getRepositoryManager()->getLocalRepository());
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // creating requirements request
        $request = new Request($pool);
        if ($composer->getLocker()->isLocked()) {
            $output->writeln('> Found lockfile. Reading.');

            foreach ($composer->getLocker()->getLockedPackages() as $package) {
                $constraint = new VersionConstraint('=', $package->getVersion());
                $request->install($package->getName(), $constraint);
            }
        } else {
            foreach ($composer->getPackage()->getRequires() as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // prepare solver
        $installationManager = $composer->getInstallationManager();
        $localRepo           = $composer->getRepositoryManager()->getLocalRepository();
        $policy              = new DependencyResolver\DefaultPolicy();
        $solver              = new DependencyResolver\Solver($policy, $pool, $localRepo);

        // solve dependencies
        $operations = $solver->solve($request);

        // check for missing deps
        // TODO this belongs in the solver, but this will do for now to report top-level deps missing at least
        foreach ($request->getJobs() as $job) {
            if ('install' === $job['cmd']) {
                foreach ($localRepo->getPackages() as $package) {
                    if ($job['packageName'] === $package->getName()) {
                        continue 2;
                    }
                }
                foreach ($operations as $operation) {
                    if ('install' === $operation->getJobType() && $job['packageName'] === $operation->getPackage()->getName()) {
                        continue 2;
                    }
                }
                throw new \UnexpectedValueException('Package '.$job['packageName'].' could not be resolved to an installable package.');
            }
        }

        // execute operations
        foreach ($operations as $operation) {
            $installationManager->execute($operation);
        }

        if (!$composer->getLocker()->isLocked()) {
            $composer->getLocker()->lockPackages($localRepo->getPackages());
            $output->writeln('> Locked');
        }

        $localRepo->write();

        $output->writeln('> Generating autoload.php');
        $this->generateAutoload($composer, $installationManager);

        $output->writeln('> Done');
    }

    private function generateAutoload(\Composer\Composer $composer,
        \Composer\Installer\InstallationManager $installationManager)
    {
        $localRepo = new \Composer\Repository\FilesystemRepository(
            new \Composer\Json\JsonFile('.composer/installed.json'));

        $installPaths = array();
        foreach ($localRepo->getPackages() as $package) {
            $installPaths[] = array(
                $this->getFullPackage($package, $installationManager),
                $installationManager->getInstallPath($package)
            );
        }
        $installPaths[] = array($composer->getPackage(), '');

        $autoloads = array();
        foreach ($installPaths as $item) {
            list($package, $installPath) = $item;

            if (null !== $package->getInstallAs()) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getInstallAs()));
            }

            foreach ($package->getAutoload() as $type => $mapping) {
                $autoloads[$type] = isset($autoloads[$type]) ? $autoloads[$type] : array();
                $autoloads[$type][] = array(
                    'mapping'   => $mapping,
                    'path'      => $installPath,
                );
            }
        }

        $this->dumpAutoload($autoloads);
    }

    private function dumpAutoload(array $autoloads)
    {
        $file = <<<'EOF'
<?php
// autoload.php generated by composer

require_once __DIR__.'/../vendor/symfony/symfony/src/Symfony/Component/ClassLoader/UniversalClassLoader.php';

use Symfony\Component\ClassLoader\UniversalClassLoader;

$loader = new UniversalClassLoader();

EOF;

        if (isset($autoloads['psr0'])) {
            foreach ($autoloads['psr0'] as $def) {
                foreach ($def['mapping'] as $namespace => $path) {
                    $exportedNamespace = var_export($namespace, true);
                    $exportedPath = var_export(($def['path'] ? '/'.$def['path'] : '').'/'.$path, true);
                    $file .= <<<EOF
\$loader->registerNamespace($exportedNamespace, dirname(__DIR__).$exportedPath);

EOF;
                }
            }
        }

        if (isset($autoloads['pear'])) {
            foreach ($autoloads['pear'] as $def) {
                foreach ($def['mapping'] as $prefix => $path) {
                    $exportedPrefix = var_export($prefix, true);
                    $exportedPath = var_export(($def['path'] ? '/'.$def['path'] : '').'/'.$path, true);
                    $file .= <<<EOF
\$loader->registerPrefix($exportedPrefix, dirname(__DIR__).$exportedPath);

EOF;
                }
            }
        }

        $file .= <<<'EOF'
$loader->register();

EOF;

        file_put_contents('.composer/autoload.php', $file);
    }

    private function getFullPackage(\Composer\Package\PackageInterface $package,
        \Composer\Installer\InstallationManager $installationManager)
    {
        $path = $installationManager->getInstallPath($package);

        $loader  = new \Composer\Package\Loader\JsonLoader();
        $fullPackage = $loader->load(new \Composer\Json\JsonFile($path.'/composer.json'));

        return $fullPackage;
    }
}
