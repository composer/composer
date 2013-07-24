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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Util\Perforce;
#use Composer\Util\GitHub;
#use Composer\Util\Git as GitUtil;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PerforceDownloader extends VcsDownloader
{
    private $hasStashedChanges = false;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        print ("Perforce Downloader:doDownload - path:" . var_export($path, true) . "\n");

        $ref = $package->getSourceReference();
        $p4client = "composer_perforce_dl_" . str_replace("/", "_", str_replace("//", "", $ref));

        $clientSpec = "$path/$p4client.p4.spec";
        print ("PerforceDownloader:doDownload - clientSpec: $clientSpec, targetDir: $path, p4Client: $p4client\n\n");
        $perforce = new Perforce();
        $perforce->writeP4ClientSpec($clientSpec, $path, $p4client, $ref);
        $perforce->syncCodeBase($clientSpec, $path, $p4client);
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        print("PerforceDownloader:doUpdate\n");

//        $this->cleanEnv();
//        $path = $this->normalizePath($path);
//
//        $ref = $target->getSourceReference();
//        $this->io->write("    Checking out ".$ref);
//        $command = 'git remote set-url composer %s && git fetch composer && git fetch --tags composer';
//
//        // capture username/password from URL if there is one
//        $this->process->execute('git remote -v', $output, $path);
//        if (preg_match('{^(?:composer|origin)\s+https?://(.+):(.+)@([^/]+)}im', $output, $match)) {
//            $this->io->setAuthentication($match[3], urldecode($match[1]), urldecode($match[2]));
//        }
//
//        $commandCallable = function($url) use ($command) {
//            return sprintf($command, escapeshellarg($url));
//        };
//
//        $this->runCommand($commandCallable, $target->getSourceUrl(), $path);
//        $this->updateToCommit($path, $ref, $target->getPrettyVersion(), $target->getReleaseDate());
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges($path)
    {
        print("PerforceDownloader:getLocalChanges\n");
//        $this->cleanEnv();
//        $path = $this->normalizePath($path);
//        if (!is_dir($path.'/.git')) {
//            return;
//        }
//
//        $command = 'git status --porcelain --untracked-files=no';
//        if (0 !== $this->process->execute($command, $output, $path)) {
//            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
//        }
//
//        return trim($output) ?: null;
    }


    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        print("PerforceDownloader:getCommitLogs\n");
//        $path = $this->normalizePath($path);
//        $command = sprintf('git log %s..%s --pretty=format:"%%h - %%an: %%s"', $fromReference, $toReference);
//
//        if (0 !== $this->process->execute($command, $output, $path)) {
//            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
//        }
//
//        return $output;
    }

}
