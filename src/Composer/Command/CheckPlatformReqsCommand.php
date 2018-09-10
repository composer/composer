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

use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\PlatformRepository;

class CheckPlatformReqsCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('check-platform-reqs')
            ->setDescription('Check that platform requirements are satisfied.')
            ->setDefinition(array(
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables checking of require-dev packages requirements.'),
            ))
            ->setHelp(
                <<<EOT
Checks that your PHP and extensions versions match the platform requirements of the installed packages.

<info>php composer.phar check-platform-reqs</info>

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $requires = $composer->getPackage()->getRequires();
        if ($input->getOption('no-dev')) {
            $dependencies = $composer->getLocker()->getLockedRepository(!$input->getOption('no-dev'))->getPackages();
        } else {
            $dependencies = $composer->getRepositoryManager()->getLocalRepository()->getPackages();
            $requires += $composer->getPackage()->getDevRequires();
        }
        foreach ($requires as $require => $link) {
            $requires[$require] = array($link);
        }

        foreach ($dependencies as $package) {
            foreach ($package->getRequires() as $require => $link) {
                $requires[$require][] = $link;
            }
        }

        ksort($requires);

        $platformRepo = new PlatformRepository(array(), array());
        $currentPlatformPackages = $platformRepo->getPackages();
        $currentPlatformPackageMap = array();

        /**
         * @var PackageInterface $currentPlatformPackage
         */
        foreach ($currentPlatformPackages as $currentPlatformPackage) {
            $currentPlatformPackageMap[$currentPlatformPackage->getName()] = $currentPlatformPackage;
        }

        $results = array();

        $exitCode = 0;

        /**
         * @var Link[] $links
         */
        foreach ($requires as $require => $links) {
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $require)) {
                if (isset($currentPlatformPackageMap[$require])) {
                    $pass = true;
                    $version = $currentPlatformPackageMap[$require]->getVersion();

                    foreach ($links as $link) {
                        if (!$link->getConstraint()->matches(new Constraint('=', $version))) {
                            $results[] = array(
                                $currentPlatformPackageMap[$require]->getPrettyName(),
                                $currentPlatformPackageMap[$require]->getPrettyVersion(),
                                $link,
                                '<error>failed</error>',
                            );
                            $pass = false;

                            $exitCode = max($exitCode, 1);
                        }
                    }

                    if ($pass) {
                        $results[] = array(
                            $currentPlatformPackageMap[$require]->getPrettyName(),
                            $currentPlatformPackageMap[$require]->getPrettyVersion(),
                            null,
                            '<info>success</info>',
                        );
                    }
                } else {
                    $results[] = array(
                        $require,
                        'n/a',
                        $links[0],
                        '<error>missing</error>',
                    );

                    $exitCode = max($exitCode, 2);
                }
            }
        }

        $this->printTable($output, $results);

        return $exitCode;
    }

    protected function printTable(OutputInterface $output, $results)
    {
        $table = array();
        $rows = array();
        foreach ($results as $result) {
            /**
             * @var Link|null $link
             */
            list($platformPackage, $version, $link, $status) = $result;
            $rows[] = array(
                $platformPackage,
                $version,
                $link ? sprintf('%s %s %s (%s)', $link->getSource(), $link->getDescription(), $link->getTarget(), $link->getPrettyConstraint()) : '',
                $status,
            );
        }
        $table = array_merge($rows, $table);

        // Render table
        $renderer = new Table($output);
        $renderer->setStyle('compact');
        $rendererStyle = $renderer->getStyle();
        $rendererStyle->setVerticalBorderChar('');
        $rendererStyle->setCellRowContentFormat('%s  ');
        $renderer->setRows($table)->render();
    }
}
