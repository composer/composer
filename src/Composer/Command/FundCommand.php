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

use Composer\Package\CompletePackageInterface;
use Composer\Package\AliasPackage;
use Composer\Repository\CompositeRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FundCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('fund')
            ->setDescription('Discover how to help fund the maintenance of your dependencies.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $repo = $composer->getRepositoryManager()->getLocalRepository();
        $remoteRepos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $fundings = array();
        foreach ($repo->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $latest = $remoteRepos->findPackage($package->getName(), 'dev-master');
            if ($latest instanceof CompletePackageInterface && $latest->getFunding()) {
                $fundings = $this->insertFundingData($fundings, $latest);
                continue;
            }
            if ($package instanceof CompletePackageInterface && $package->getFunding()) {
                $fundings = $this->insertFundingData($fundings, $package);
            }
        }

        ksort($fundings);

        $io = $this->getIO();

        if ($fundings) {
            $prev = null;

            $io->write('The following packages were found in your dependencies which publish funding information:');

            foreach ($fundings as $vendor => $links) {
                $io->write('');
                $io->write(sprintf("<comment>%s</comment>", $vendor));
                foreach ($links as $url => $packages) {
                    $line = sprintf('  <info>%s</info>', implode(', ', $packages));

                    if ($prev !== $line) {
                        $io->write($line);
                        $prev = $line;
                    }

                    $io->write(sprintf('    %s', $url));
                }
            }

            $io->write("");
            $io->write("Please consider following these links and sponsoring the work of package authors!");
            $io->write("Thank you!");
        } else {
            $io->write("No funding links were found in your package dependencies. This doesn't mean they don't need your support!");
        }

        return 0;
    }

    private function insertFundingData(array $fundings, CompletePackageInterface $package)
    {
        foreach ($package->getFunding() as $fundingOption) {
            list($vendor, $packageName) = explode('/', $package->getPrettyName());
            // ignore malformed funding entries
            if (empty($fundingOption['url'])) {
                continue;
            }
            $url = $fundingOption['url'];
            if (!empty($fundingOption['type']) && $fundingOption['type'] === 'github' && preg_match('{^https://github.com/([^/]+)$}', $url, $match)) {
                $url = 'https://github.com/sponsors/'.$match[1];
            }
            $fundings[$vendor][$url][] = $packageName;
        }

        return $fundings;
    }
}
