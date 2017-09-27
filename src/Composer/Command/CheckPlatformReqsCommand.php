<?php

namespace Composer\Command;

use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\PlatformRepository;

class CheckPlatformReqsCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('check-platform-reqs')
            ->setDescription('Check platform requirements of your project.')
            ->setHelp(<<<EOT
<info>php composer.phar check-platform-reqs</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $repos = $composer->getRepositoryManager()->getLocalRepository();

        $allPackages = array_merge($repos->getPackages(), array($composer->getPackage()));
        $requires    = array();

        /**
         * @var PackageInterface $package
         */
        foreach ($allPackages as $package) {
            $requires = array_merge($requires, $package->getRequires());
        }

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

        /**
         * @var Link $require
         */
        foreach ($requires as $key => $require) {
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $key)) {
                if (isset($currentPlatformPackageMap[$key])) {
                    // 检查版本
                    $version = $currentPlatformPackageMap[$key]->getVersion();
                    if (!$require->getConstraint()->matches(new Constraint('<=', $version))) {
                        $results[] = [
                            $require,
                            $currentPlatformPackageMap[$key],
                            'failed',
                        ];
                    } else {
                        $results[] = [
                            $require,
                            $currentPlatformPackageMap[$key],
                            'success',
                        ];
                    }
                } else {
                    $results[] = [
                        $require,
                        null,
                        'miss',
                    ];
                }
            }
        }

        $this->printTable($output, $results);

    }

    protected function printTable(OutputInterface $output, $results)
    {
        $table = array();
        $rows  = array();
        foreach ($results as $result) {
            /**
             * @var PackageInterface $platformPackage
             * @var Link             $require
             */
            list($require, $platformPackage, $reason) = $result;
            $version = (strpos($platformPackage->getPrettyVersion(), 'No version set') === 0) ? '-' : $platformPackage->getPrettyVersion();
            $rows[]  = [$platformPackage->getPrettyName(), $version, $require->getDescription(), sprintf('%s (%s)', $require->getTarget(), $require->getPrettyConstraint()), $reason];
        }
        $table = array_merge($rows, $table);

        // Render table
        $renderer = new Table($output);
        $renderer->setStyle('compact');
        $renderer->getStyle()->setVerticalBorderChar('');
        $renderer->getStyle()->setCellRowContentFormat('%s  ');
        $renderer->setRows($table)->render();
    }
}