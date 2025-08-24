<?php declare(strict_types=1);

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

use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionBumper;
use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Repository\PlatformRepository;
use Composer\Util\Silencer;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
final class BumpCommand extends BaseCommand
{
    private const ERROR_GENERIC = 1;
    private const ERROR_LOCK_OUTDATED = 2;

    use CompletionTrait;

    protected function configure(): void
    {
        $this
            ->setName('bump')
            ->setDescription('Increases the lower limit of your composer.json requirements to the currently installed versions')
            ->setDefinition([
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Optional package name(s) to restrict which packages are bumped.', null, $this->suggestRootRequirement()),
                new InputOption('dev-only', 'D', InputOption::VALUE_NONE, 'Only bump requirements in "require-dev".'),
                new InputOption('no-dev-only', 'R', InputOption::VALUE_NONE, 'Only bump requirements in "require".'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the packages to bump, but will not execute anything.'),
            ])
            ->setHelp(
                <<<EOT
The <info>bump</info> command increases the lower limit of your composer.json requirements
to the currently installed versions. This helps to ensure your dependencies do not
accidentally get downgraded due to some other conflict, and can slightly improve
dependency resolution performance as it limits the amount of package versions
Composer has to look at.

Running this blindly on libraries is **NOT** recommended as it will narrow down
your allowed dependencies, which may cause dependency hell for your users.
Running it with <info>--dev-only</info> on libraries may be fine however as dev requirements
are local to the library and do not affect consumers of the package.

EOT
            )
        ;
    }

    /**
     * @throws \Seld\JsonLint\ParsingException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->doBump(
            $this->getIO(),
            $input->getOption('dev-only'),
            $input->getOption('no-dev-only'),
            $input->getOption('dry-run'),
            $input->getArgument('packages')
        );
    }

    /**
     * @param string[] $packagesFilter
     * @throws \Seld\JsonLint\ParsingException
     */
    public function doBump(
        IOInterface $io,
        bool $devOnly,
        bool $noDevOnly,
        bool $dryRun,
        array $packagesFilter
    ): int {
        /** @readonly */
        $composerJsonPath = Factory::getComposerFile();

        if (!Filesystem::isReadable($composerJsonPath)) {
            $io->writeError('<error>'.$composerJsonPath.' is not readable.</error>');

            return self::ERROR_GENERIC;
        }

        $composerJson = new JsonFile($composerJsonPath);
        $contents = file_get_contents($composerJson->getPath());
        if (false === $contents) {
            $io->writeError('<error>'.$composerJsonPath.' is not readable.</error>');

            return self::ERROR_GENERIC;
        }

        // check for writability by writing to the file as is_writable can not be trusted on network-mounts
        // see https://github.com/composer/composer/issues/8231 and https://bugs.php.net/bug.php?id=68926
        if (!is_writable($composerJsonPath) && false === Silencer::call('file_put_contents', $composerJsonPath, $contents)) {
            $io->writeError('<error>'.$composerJsonPath.' is not writable.</error>');

            return self::ERROR_GENERIC;
        }
        unset($contents);

        $composer = $this->requireComposer();
        if ($composer->getLocker()->isLocked()) {
            if (!$composer->getLocker()->isFresh()) {
                $io->writeError('<error>The lock file is not up to date with the latest changes in composer.json. Run the appropriate `update` to fix that before you use the `bump` command.</error>');

                return self::ERROR_LOCK_OUTDATED;
            }

            $repo = $composer->getLocker()->getLockedRepository(true);
        } else {
            $repo = $composer->getRepositoryManager()->getLocalRepository();
        }

        if ($composer->getPackage()->getType() !== 'project' && !$devOnly) {
            $io->writeError('<warning>Warning: Bumping dependency constraints is not recommended for libraries as it will narrow down your dependencies and may cause problems for your users.</warning>');

            $contents = $composerJson->read();
            if (!isset($contents['type'])) {
                $io->writeError('<warning>If your package is not a library, you can explicitly specify the "type" by using "composer config type project".</warning>');
                $io->writeError('<warning>Alternatively you can use --dev-only to only bump dependencies within "require-dev".</warning>');
            }
            unset($contents);
        }

        $bumper = new VersionBumper();
        $tasks = [];
        if (!$devOnly) {
            $tasks['require'] = $composer->getPackage()->getRequires();
        }
        if (!$noDevOnly) {
            $tasks['require-dev'] = $composer->getPackage()->getDevRequires();
        }

        if (count($packagesFilter) > 0) {
            // support proxied args from the update command that contain constraints together with the package names
            $packagesFilter = array_map(static function ($constraint) {
                return Preg::replace('{[:= ].+}', '', $constraint);
            }, $packagesFilter);
            $pattern = BasePackage::packageNamesToRegexp(array_unique(array_map('strtolower', $packagesFilter)));
            foreach ($tasks as $key => $reqs) {
                foreach ($reqs as $pkgName => $link) {
                    if (!Preg::isMatch($pattern, $pkgName)) {
                        unset($tasks[$key][$pkgName]);
                    }
                }
            }
        }

        $updates = [];
        foreach ($tasks as $key => $reqs) {
            foreach ($reqs as $pkgName => $link) {
                if (PlatformRepository::isPlatformPackage($pkgName)) {
                    continue;
                }
                $currentConstraint = $link->getPrettyConstraint();

                $package = $repo->findPackage($pkgName, '*');
                // name must be provided or replaced
                if (null === $package) {
                    continue;
                }
                while ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                $bumped = $bumper->bumpRequirement($link->getConstraint(), $package);

                if ($bumped === $currentConstraint) {
                    continue;
                }

                $updates[$key][$pkgName] = $bumped;
            }
        }

        if (!$dryRun && !$this->updateFileCleanly($composerJson, $updates)) {
            $composerDefinition = $composerJson->read();
            foreach ($updates as $key => $packages) {
                foreach ($packages as $package => $version) {
                    $composerDefinition[$key][$package] = $version;
                }
            }
            $composerJson->write($composerDefinition);
        }

        $changeCount = array_sum(array_map('count', $updates));
        if ($changeCount > 0) {
            if ($dryRun) {
                $io->write('<info>' . $composerJsonPath . ' would be updated with:</info>');
                foreach ($updates as $requireType => $packages) {
                    foreach ($packages as $package => $version) {
                        $io->write(sprintf('<info> - %s.%s: %s</info>', $requireType, $package, $version));
                    }
                }
            } else {
                $io->write('<info>' . $composerJsonPath . ' has been updated (' . $changeCount . ' changes).</info>');
            }
        } else {
            $io->write('<info>No requirements to update in '.$composerJsonPath.'.</info>');
        }

        if (!$dryRun && $composer->getLocker()->isLocked() && $composer->getConfig()->get('lock') && $changeCount > 0) {
            $composer->getLocker()->updateHash($composerJson);
        }

        if ($dryRun && $changeCount > 0) {
            return self::ERROR_GENERIC;
        }

        return 0;
    }

    /**
     * @param array<'require'|'require-dev', array<string, string>> $updates
     */
    private function updateFileCleanly(JsonFile $json, array $updates): bool
    {
        $contents = file_get_contents($json->getPath());
        if (false === $contents) {
            throw new \RuntimeException('Unable to read '.$json->getPath().' contents.');
        }

        $manipulator = new JsonManipulator($contents);

        foreach ($updates as $key => $packages) {
            foreach ($packages as $package => $version) {
                if (!$manipulator->addLink($key, $package, $version)) {
                    return false;
                }
            }
        }

        if (false === file_put_contents($json->getPath(), $manipulator->getContents())) {
            throw new \RuntimeException('Unable to write new '.$json->getPath().' contents.');
        }

        return true;
    }
}
