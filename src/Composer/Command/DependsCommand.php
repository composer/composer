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

use Composer\DependencyResolver\Pool;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Semver\VersionParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DependsCommand extends Command
{
    protected $linkTypes = array(
        'require' => array('requires', 'requires'),
        'require-dev' => array('devRequires', 'requires (dev)'),
    );

    protected function configure()
    {
        $this
            ->setName('depends')
            ->setDescription('Shows which packages depend on the given package')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::REQUIRED, 'Package to inspect'),
                new InputOption('link-type', '', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Link types to show (require, require-dev)', array_keys($this->linkTypes)),
                new InputOption('match-constraint', 'm', InputOption::VALUE_REQUIRED, 'Filters the dependencies shown using this constraint', '*'),
                new InputOption('invert-match-constraint', 'i', InputOption::VALUE_NONE, 'Turns --match-constraint around into a blacklist instead of whitelist'),
                new InputOption('with-replaces', '', InputOption::VALUE_NONE, 'Search for replaced packages as well'),
            ))
            ->setHelp(<<<EOT
Displays detailed information about where a package is referenced.

<info>php composer.phar depends composer/composer</info>

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'depends', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $platformOverrides = $composer->getConfig()->get('platform') ?: array();
        $repo = new CompositeRepository(array(
            new ArrayRepository(array($composer->getPackage())),
            $composer->getRepositoryManager()->getLocalRepository(),
            new PlatformRepository(array(), $platformOverrides),
        ));
        $needle = $input->getArgument('package');

        $pool = new Pool();
        $pool->addRepository($repo);

        $packages = $pool->whatProvides($needle);
        if (empty($packages)) {
            throw new \InvalidArgumentException('Could not find package "'.$needle.'" in your project.');
        }

        $linkTypes = $this->linkTypes;

        $types = array_map(function ($type) use ($linkTypes) {
            $type = rtrim($type, 's');
            if (!isset($linkTypes[$type])) {
                throw new \InvalidArgumentException('Unexpected link type: '.$type.', valid types: '.implode(', ', array_keys($linkTypes)));
            }

            return $type;
        }, $input->getOption('link-type'));

        $versionParser = new VersionParser();
        $constraint = $versionParser->parseConstraints($input->getOption('match-constraint'));
        $matchInvert = $input->getOption('invert-match-constraint');

        $needles = array($needle);
        if (true === $input->getOption('with-replaces')) {
            foreach ($packages as $package) {
                $needles = array_merge($needles, array_map(function (Link $link) {
                    return $link->getTarget();
                }, $package->getReplaces()));
            }
        }

        $messages = array();
        $outputPackages = array();
        $io = $this->getIO();
        /** @var PackageInterface $package */
        foreach ($repo->getPackages() as $package) {
            foreach ($types as $type) {
                /** @var Link $link */
                foreach ($package->{'get'.$linkTypes[$type][0]}() as $link) {
                    foreach ($needles as $needle) {
                        if ($link->getTarget() === $needle && ($link->getConstraint()->matches($constraint) ? !$matchInvert : $matchInvert)) {
                            if (!isset($outputPackages[$package->getName()])) {
                                $messages[] = '<info>'.$package->getPrettyName() . '</info> ' . $linkTypes[$type][1] . ' ' . $needle .' (<info>' . $link->getPrettyConstraint() . '</info>)';
                                $outputPackages[$package->getName()] = true;
                            }
                        }
                    }
                }
            }
        }

        if ($messages) {
            sort($messages);
            $io->write($messages);
        } else {
            $matchText = '';
            if ($input->getOption('match-constraint') !== '*') {
                $matchText = ' in versions '.($matchInvert ? 'not ' : '').'matching ' . $input->getOption('match-constraint');
            }
            $io->writeError('<info>There is no installed package depending on "'.$needle.'"'.$matchText.'.</info>');
        }
    }
}
