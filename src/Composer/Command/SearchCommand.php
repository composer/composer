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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Package\CompletePackageInterface;
use Composer\Package\AliasPackage;
use Composer\Factory;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class SearchCommand extends Command
{
    protected $matches;
    protected $lowMatches = array();
    protected $tokens;
    protected $output;
    protected $onlyName;
    protected $orderedPackages = array();

    protected function configure()
    {
        $this
            ->setName('search')
            ->setDescription('Search for packages')
            ->setDefinition(array(
                new InputOption('only-name', 'N', InputOption::VALUE_NONE, 'Search only in name'),
                new InputOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit result to amount. Default: 50', 50),
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
            $defaultRepos = Factory::createDefaultRepositories($this->getIO());
            $output->writeln('No composer.json found in the current directory, showing packages from ' . implode(', ', array_keys($defaultRepos)));
            $installedRepo = $platformRepo;
            $repos = new CompositeRepository(array_merge(array($installedRepo), $defaultRepos));
        }

        $this->onlyName = $input->getOption('only-name');
        $limit = $input->getOption('limit');
        $this->tokens = $input->getArgument('tokens');
        $this->output = $output;
        $this->orderedPackages = array();
        $repos->filterPackages(array($this, 'processPackage'), 'Composer\Package\CompletePackage');

        usort($this->orderedPackages, function($a, $b) {
            return $a[0] == $b[0] ? 0 : $a[0] > $b[0] ? -1 : 1;
        });
        foreach ($this->orderedPackages as $ordered){
            $output->writeln($ordered[1]);
            if ( --$limit == 0 ) break;
        }

        if ($limit != 0) {
            foreach ($this->lowMatches as $details) {
                $output->writeln($details['name'] . '<comment>:</comment> '. $details['description']);
                if ( --$limit == 0 ) break;
            }
        }
    }

    public function processPackage($package)
    {
        if ($package instanceof AliasPackage || isset($this->matches[$package->getName()])) {
            return;
        }
        
        $output = array();
        foreach ($this->tokens as $token) {
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

            if ($score >= 3) {
                $this->orderedPackages []= array( $score, $name . '<comment>:</comment> '. $description );
                //$this->output->writeln('['. $score. ']'. $name . '<comment>:</comment> '. $description);
                $this->matches[$package->getName()] = true;
            } else {
                $this->lowMatches[$package->getName()] = array(
                    'name' => $name,
                    'description' => $description,
                );
            }

            return;
        }
    }

    /**
     * tries to find a token within the name/keywords/description
     *
     * @param  CompletePackageInterface $package
     * @param  string           $token
     * @return boolean
     */
    private function matchPackage(CompletePackageInterface $package, $token)
    {
        $score = 0;
        $nameLc = strtolower($package->getName());
        $tokenLc = strtolower($token);

        if (false !== ($pos = strpos($nameLc, $tokenLc))) {
            if ($pos==0) {
                if ($nameLc == $tokenLc || $nameLc == "$tokenLc/$tokenLc") {
                    $score += 50;
                }
                elseif (strpos($nameLc, $tokenLc. '/') === 0) {
                    $score += 15;
                }
                else {
                    $score += 10;
                }
            }
            else {
                $score += 5;
            }
        }

        if (!$this->onlyName && false !== stripos(join(',', $package->getKeywords() ?: array()), $token)) {
            $score += 3;
        }

        if (!$this->onlyName && false !== stripos($package->getDescription(), $token)) {
            $score += 1;
        }

        return $score;
    }
}
