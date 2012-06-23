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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\ComposerRepository;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Factory;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class SearchCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('search')
            ->setDescription('Search for packages')
            ->setDefinition(array(
                new InputArgument('tokens', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'tokens to search for'),
            ))
            ->setHelp(<<<EOT
The search command searches for packages by its name
<info>php composer.phar search symfony composer</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // init repos
        $platformRepo = new PlatformRepository;
        if ($composer = $this->getComposer(false)) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
            $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));
        } else {
            $defaultRepos = Factory::createComposerRepositories($this->getIO(), Factory::createConfig());
            $output->writeln('No composer.json found in the current directory, showing packages from ' . str_replace('http://', '', implode(', ', array_keys($defaultRepos))));
            $installedRepo = $platformRepo;
            $repos = new CompositeRepository(array_merge(array($installedRepo), $defaultRepos));
        }

        $tokens = $input->getArgument('tokens');
        $packages = array();

        $maxPackageLength = 0;
        foreach ($repos->getPackages() as $package) {
            if ($package instanceof AliasPackage || isset($packages[$package->getName()])) {
                continue;
            }

            foreach ($tokens as $token) {
                if (!$score = $this->matchPackage($package, $token)) {
                    continue;
                }

                if (false !== ($pos = stripos($package->getName(), $token))) {
                    $name = substr($package->getPrettyName(), 0, $pos)
                        . '<highlight>' . substr($package->getPrettyName(), $pos, strlen($token)) . '</highlight>'
                        . substr($package->getPrettyName(), $pos + strlen($token));
                } else {
                    $name = $package->getPrettyName();
                }

                $description = strtok($package->getDescription(), "\r\n");
                if (false !== ($pos = stripos($description, $token))) {
                    $description = substr($description, 0, $pos)
                        . '<highlight>' . substr($description, $pos, strlen($token)) . '</highlight>'
                        . substr($description, $pos + strlen($token));
                }

                $packages[$package->getName()] = array(
                    'name' => $name,
                    'description' => $description,
                    'length' => $length = strlen($package->getPrettyName()),
                    'score' => $score,
                );

                $maxPackageLength = max($maxPackageLength, $length);

                continue 2;
            }
        }

        usort($packages, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }

            return $a['score'] > $b['score'] ? -1 : 1;
        });

        foreach ($packages as $details) {
            $extraSpaces = $maxPackageLength - $details['length'];
            $output->writeln($details['name'] . str_repeat(' ', $extraSpaces) .' <comment>:</comment> '. $details['description']);
        }
    }

    /**
     * tries to find a token within the name/keywords/description
     *
     * @param  PackageInterface $package
     * @param  string           $token
     * @return boolean
     */
    private function matchPackage(PackageInterface $package, $token)
    {
        $score = 0;

        if (false !== stripos($package->getName(), $token)) {
            $score += 5;
        }

        if (false !== stripos(join(',', $package->getKeywords() ?: array()), $token)) {
            $score += 3;
        }

        if (false !== stripos($package->getDescription(), $token)) {
            $score += 1;
        }

        return $score;
    }
}
