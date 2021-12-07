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
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Semver\Constraint\MatchAllConstraint;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FundCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('fund')
            ->setDescription('Discover how to help fund the maintenance of your dependencies.')
            ->setDefinition(array(
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text'),
            ))
        ;
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $repo = $composer->getRepositoryManager()->getLocalRepository();
        $remoteRepos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $fundings = array();

        $packagesToLoad = array();
        foreach ($repo->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $packagesToLoad[$package->getName()] = new MatchAllConstraint();
        }

        // load all packages dev versions in parallel
        $result = $remoteRepos->loadPackages($packagesToLoad, array('dev' => BasePackage::STABILITY_DEV), array());

        // collect funding data from default branches
        foreach ($result['packages'] as $package) {
            if (
                !$package instanceof AliasPackage
                && $package instanceof CompletePackageInterface
                && $package->isDefaultBranch()
                && $package->getFunding()
                && isset($packagesToLoad[$package->getName()])
            ) {
                $fundings = $this->insertFundingData($fundings, $package);
                unset($packagesToLoad[$package->getName()]);
            }
        }

        // collect funding from installed packages if none was found in the default branch above
        foreach ($repo->getPackages() as $package) {
            if ($package instanceof AliasPackage || !isset($packagesToLoad[$package->getName()])) {
                continue;
            }

            if ($package instanceof CompletePackageInterface && $package->getFunding()) {
                $fundings = $this->insertFundingData($fundings, $package);
            }
        }

        ksort($fundings);

        $io = $this->getIO();

        $format = $input->getOption('format');
        if (!in_array($format, array('text', 'json'))) {
            $io->writeError(sprintf('Unsupported format "%s". See help for supported formats.', $format));

            return 1;
        }

        if ($fundings && $format === 'text') {
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
        } elseif ($format === 'json') {
            $io->write(JsonFile::encode($fundings));
        } else {
            $io->write("No funding links were found in your package dependencies. This doesn't mean they don't need your support!");
        }

        return 0;
    }

    /**
     * @param mixed[] $fundings
     * @return mixed[]
     */
    private function insertFundingData(array $fundings, CompletePackageInterface $package)
    {
        foreach ($package->getFunding() as $fundingOption) {
            list($vendor, $packageName) = explode('/', $package->getPrettyName());
            // ignore malformed funding entries
            if (empty($fundingOption['url'])) {
                continue;
            }
            $url = $fundingOption['url'];
            if (!empty($fundingOption['type']) && $fundingOption['type'] === 'github' && Preg::isMatch('{^https://github.com/([^/]+)$}', $url, $match)) {
                $url = 'https://github.com/sponsors/'.$match[1];
            }
            $fundings[$vendor][$url][] = $packageName;
        }

        return $fundings;
    }
}
