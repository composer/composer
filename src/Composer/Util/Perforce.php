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

namespace Composer\Util;

use Composer\IO\IOInterface;
use Symfony\Component\Process\Process;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class Perforce
{
    protected $path;
    protected $p4Depot;
    protected $p4Client;
    protected $p4User;
    protected $p4Password;
    protected $p4Port;
    protected $p4Stream;
    protected $p4ClientSpec;
    protected $p4DepotType;
    protected $p4Branch;
    protected $process;
    protected $uniquePerforceClientName;
    protected $windowsFlag;
    protected $commandResult;

    protected $io;

    protected $filesystem;

    public function __construct($repoConfig, $port, $path, ProcessExecutor $process, $isWindows, IOInterface $io)
    {
        $this->windowsFlag = $isWindows;
        $this->p4Port = $port;
        $this->initializePath($path);
        $this->process = $process;
        $this->initialize($repoConfig);
        $this->io = $io;
    }

    public static function create($repoConfig, $port, $path, ProcessExecutor $process, IOInterface $io)
    {
        return new Perforce($repoConfig, $port, $path, $process, Platform::isWindows(), $io);
    }

    public static function checkServerExists($url, ProcessExecutor $processExecutor)
    {
        $output = null;

        return  0 === $processExecutor->execute('p4 -p ' . ProcessExecutor::escape($url) . ' info -s', $output);
    }

    public function initialize($repoConfig)
    {
        $this->uniquePerforceClientName = $this->generateUniquePerforceClientName();
        if (!$repoConfig) {
            return;
        }
        if (isset($repoConfig['unique_perforce_client_name'])) {
            $this->uniquePerforceClientName = $repoConfig['unique_perforce_client_name'];
        }

        if (isset($repoConfig['depot'])) {
            $this->p4Depot = $repoConfig['depot'];
        }
        if (isset($repoConfig['branch'])) {
            $this->p4Branch = $repoConfig['branch'];
        }
        if (isset($repoConfig['p4user'])) {
            $this->p4User = $repoConfig['p4user'];
        } else {
            $this->p4User = $this->getP4variable('P4USER');
        }
        if (isset($repoConfig['p4password'])) {
            $this->p4Password = $repoConfig['p4password'];
        }
    }

    public function initializeDepotAndBranch($depot, $branch)
    {
        if (isset($depot)) {
            $this->p4Depot = $depot;
        }
        if (isset($branch)) {
            $this->p4Branch = $branch;
        }
    }

    public function generateUniquePerforceClientName()
    {
        return gethostname() . "_" . time();
    }

    public function cleanupClientSpec()
    {
        $client = $this->getClient();
        $task = 'client -d ' . ProcessExecutor::escape($client);
        $useP4Client = false;
        $command = $this->generateP4Command($task, $useP4Client);
        $this->executeCommand($command);
        $clientSpec = $this->getP4ClientSpec();
        $fileSystem = $this->getFilesystem();
        $fileSystem->remove($clientSpec);
    }

    protected function executeCommand($command)
    {
        $this->commandResult = '';

        return $this->process->execute($command, $this->commandResult);
    }

    public function getClient()
    {
        if (!isset($this->p4Client)) {
            $cleanStreamName = str_replace(array('//', '/', '@'), array('', '_', ''), $this->getStream());
            $this->p4Client = 'composer_perforce_' . $this->uniquePerforceClientName . '_' . $cleanStreamName;
        }

        return $this->p4Client;
    }

    protected function getPath()
    {
        return $this->path;
    }

    public function initializePath($path)
    {
        $this->path = $path;
        $fs = $this->getFilesystem();
        $fs->ensureDirectoryExists($path);
    }

    protected function getPort()
    {
        return $this->p4Port;
    }

    public function setStream($stream)
    {
        $this->p4Stream = $stream;
        $index = strrpos($stream, '/');
        //Stream format is //depot/stream, while non-streaming depot is //depot
        if ($index > 2) {
            $this->p4DepotType = 'stream';
        }
    }

    public function isStream()
    {
        return (strcmp($this->p4DepotType, 'stream') === 0);
    }

    public function getStream()
    {
        if (!isset($this->p4Stream)) {
            if ($this->isStream()) {
                $this->p4Stream = '//' . $this->p4Depot . '/' . $this->p4Branch;
            } else {
                $this->p4Stream = '//' . $this->p4Depot;
            }
        }

        return $this->p4Stream;
    }

    public function getStreamWithoutLabel($stream)
    {
        $index = strpos($stream, '@');
        if ($index === false) {
            return $stream;
        }

        return substr($stream, 0, $index);
    }

    public function getP4ClientSpec()
    {
        return $this->path . '/' . $this->getClient() . '.p4.spec';
    }

    public function getUser()
    {
        return $this->p4User;
    }

    public function setUser($user)
    {
        $this->p4User = $user;
    }

    public function queryP4User()
    {
        $this->getUser();
        if (strlen($this->p4User) > 0) {
            return;
        }
        $this->p4User = $this->getP4variable('P4USER');
        if (strlen($this->p4User) > 0) {
            return;
        }
        $this->p4User = $this->io->ask('Enter P4 User:');
        if ($this->windowsFlag) {
            $command = 'p4 set P4USER=' . $this->p4User;
        } else {
            $command = 'export P4USER=' . $this->p4User;
        }
        $this->executeCommand($command);
    }

    protected function getP4variable($name)
    {
        if ($this->windowsFlag) {
            $command = 'p4 set';
            $this->executeCommand($command);
            $result = trim($this->commandResult);
            $resArray = explode(PHP_EOL, $result);
            foreach ($resArray as $line) {
                $fields = explode('=', $line);
                if (strcmp($name, $fields[0]) == 0) {
                    $index = strpos($fields[1], ' ');
                    if ($index === false) {
                        $value = $fields[1];
                    } else {
                        $value = substr($fields[1], 0, $index);
                    }
                    $value = trim($value);

                    return $value;
                }
            }

            return null;
        }

        $command = 'echo $' . $name;
        $this->executeCommand($command);
        $result = trim($this->commandResult);

        return $result;
    }

    public function queryP4Password()
    {
        if (isset($this->p4Password)) {
            return $this->p4Password;
        }
        $password = $this->getP4variable('P4PASSWD');
        if (strlen($password) <= 0) {
            $password = $this->io->askAndHideAnswer('Enter password for Perforce user ' . $this->getUser() . ': ');
        }
        $this->p4Password = $password;

        return $password;
    }

    public function generateP4Command($command, $useClient = true)
    {
        $p4Command = 'p4 ';
        $p4Command .= '-u ' . $this->getUser() . ' ';
        if ($useClient) {
            $p4Command .= '-c ' . $this->getClient() . ' ';
        }
        $p4Command = $p4Command . '-p ' . $this->getPort() . ' ' . $command;

        return $p4Command;
    }

    public function isLoggedIn()
    {
        $command = $this->generateP4Command('login -s', false);
        $exitCode = $this->executeCommand($command);
        if ($exitCode) {
            $errorOutput = $this->process->getErrorOutput();
            $index = strpos($errorOutput, $this->getUser());
            if ($index === false) {
                $index = strpos($errorOutput, 'p4');
                if ($index === false) {
                    return false;
                }
                throw new \Exception('p4 command not found in path: ' . $errorOutput);
            }
            throw new \Exception('Invalid user name: ' . $this->getUser());
        }

        return true;
    }

    public function connectClient()
    {
        $p4CreateClientCommand = $this->generateP4Command(
            'client -i < ' . str_replace(" ", "\\ ", $this->getP4ClientSpec())
        );
        $this->executeCommand($p4CreateClientCommand);
    }

    public function syncCodeBase($sourceReference)
    {
        $prevDir = getcwd();
        chdir($this->path);
        $p4SyncCommand = $this->generateP4Command('sync -f ');
        if (null !== $sourceReference) {
            $p4SyncCommand .= '@' . $sourceReference;
        }
        $this->executeCommand($p4SyncCommand);
        chdir($prevDir);
    }

    public function writeClientSpecToFile($spec)
    {
        fwrite($spec, 'Client: ' . $this->getClient() . PHP_EOL . PHP_EOL);
        fwrite($spec, 'Update: ' . date('Y/m/d H:i:s') . PHP_EOL . PHP_EOL);
        fwrite($spec, 'Access: ' . date('Y/m/d H:i:s') . PHP_EOL);
        fwrite($spec, 'Owner:  ' . $this->getUser() . PHP_EOL . PHP_EOL);
        fwrite($spec, 'Description:' . PHP_EOL);
        fwrite($spec, '  Created by ' . $this->getUser() . ' from composer.' . PHP_EOL . PHP_EOL);
        fwrite($spec, 'Root: ' . $this->getPath() . PHP_EOL . PHP_EOL);
        fwrite($spec, 'Options:  noallwrite noclobber nocompress unlocked modtime rmdir' . PHP_EOL . PHP_EOL);
        fwrite($spec, 'SubmitOptions:  revertunchanged' . PHP_EOL . PHP_EOL);
        fwrite($spec, 'LineEnd:  local' . PHP_EOL . PHP_EOL);
        if ($this->isStream()) {
            fwrite($spec, 'Stream:' . PHP_EOL);
            fwrite($spec, '  ' . $this->getStreamWithoutLabel($this->p4Stream) . PHP_EOL);
        } else {
            fwrite(
                $spec,
                'View:  ' . $this->getStream() . '/...  //' . $this->getClient() . '/... ' . PHP_EOL
            );
        }
    }

    public function writeP4ClientSpec()
    {
        $clientSpec = $this->getP4ClientSpec();
        $spec = fopen($clientSpec, 'w');
        try {
            $this->writeClientSpecToFile($spec);
        } catch (\Exception $e) {
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }

    protected function read($pipe, $name)
    {
        if (feof($pipe)) {
            return;
        }
        $line = fgets($pipe);
        while ($line !== false) {
            $line = fgets($pipe);
        }
    }

    public function windowsLogin($password)
    {
        $command = $this->generateP4Command(' login -a');

        // TODO in v3 generate command as an array
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command, null, null, $password);
        } else {
            $process = new Process($command, null, null, $password);
        }

        return $process->run();
    }

    public function p4Login()
    {
        $this->queryP4User();
        if (!$this->isLoggedIn()) {
            $password = $this->queryP4Password();
            if ($this->windowsFlag) {
                $this->windowsLogin($password);
            } else {
                $command = 'echo ' . ProcessExecutor::escape($password)  . ' | ' . $this->generateP4Command(' login -a', false);
                $exitCode = $this->executeCommand($command);
                $result = trim($this->commandResult);
                if ($exitCode) {
                    throw new \Exception("Error logging in:" . $this->process->getErrorOutput());
                }
            }
        }
    }

    public function getComposerInformation($identifier)
    {
        $composerFileContent = $this->getFileContent('composer.json', $identifier);

        if (!$composerFileContent) {
            return;
        }

        return json_decode($composerFileContent, true);
    }

    public function getFileContent($file, $identifier)
    {
        $path = $this->getFilePath($file, $identifier);

        $command = $this->generateP4Command(' print ' . ProcessExecutor::escape($path));
        $this->executeCommand($command);
        $result = $this->commandResult;

        if (!trim($result)) {
            return null;
        }

        return $result;
    }

    public function getFilePath($file, $identifier)
    {
        $index = strpos($identifier, '@');
        if ($index === false) {
            $path = $identifier. '/' . $file;

            return $path;
        }

        $path = substr($identifier, 0, $index) . '/' . $file . substr($identifier, $index);
        $command = $this->generateP4Command(' files ' . ProcessExecutor::escape($path), false);
        $this->executeCommand($command);
        $result = $this->commandResult;
        $index2 = strpos($result, 'no such file(s).');
        if ($index2 === false) {
            $index3 = strpos($result, 'change');
            if ($index3 !== false) {
                $phrase = trim(substr($result, $index3));
                $fields = explode(' ', $phrase);

                return substr($identifier, 0, $index) . '/' . $file . '@' . $fields[1];
            }
        }

        return null;
    }

    public function getBranches()
    {
        $possibleBranches = array();
        if (!$this->isStream()) {
            $possibleBranches[$this->p4Branch] = $this->getStream();
        } else {
            $command = $this->generateP4Command('streams '.ProcessExecutor::escape('//' . $this->p4Depot . '/...'));
            $this->executeCommand($command);
            $result = $this->commandResult;
            $resArray = explode(PHP_EOL, $result);
            foreach ($resArray as $line) {
                $resBits = explode(' ', $line);
                if (count($resBits) > 4) {
                    $branch = preg_replace('/[^A-Za-z0-9 ]/', '', $resBits[4]);
                    $possibleBranches[$branch] = $resBits[1];
                }
            }
        }
        $command = $this->generateP4Command('changes '. ProcessExecutor::escape($this->getStream() . '/...'), false);
        $this->executeCommand($command);
        $result = $this->commandResult;
        $resArray = explode(PHP_EOL, $result);
        $lastCommit = $resArray[0];
        $lastCommitArr = explode(' ', $lastCommit);
        $lastCommitNum = $lastCommitArr[1];

        $branches = array('master' => $possibleBranches[$this->p4Branch] . '@'. $lastCommitNum);

        return $branches;
    }

    public function getTags()
    {
        $command = $this->generateP4Command('labels');
        $this->executeCommand($command);
        $result = $this->commandResult;
        $resArray = explode(PHP_EOL, $result);
        $tags = array();
        foreach ($resArray as $line) {
            if (strpos($line, 'Label') !== false) {
                $fields = explode(' ', $line);
                $tags[$fields[1]] = $this->getStream() . '@' . $fields[1];
            }
        }

        return $tags;
    }

    public function checkStream()
    {
        $command = $this->generateP4Command('depots', false);
        $this->executeCommand($command);
        $result = $this->commandResult;
        $resArray = explode(PHP_EOL, $result);
        foreach ($resArray as $line) {
            if (strpos($line, 'Depot') !== false) {
                $fields = explode(' ', $line);
                if (strcmp($this->p4Depot, $fields[1]) === 0) {
                    $this->p4DepotType = $fields[3];

                    return $this->isStream();
                }
            }
        }

        return false;
    }

    /**
     * @param string $reference
     * @return mixed|null
     */
    protected function getChangeList($reference)
    {
        $index = strpos($reference, '@');
        if ($index === false) {
            return null;
        }
        $label = substr($reference, $index);
        $command = $this->generateP4Command(' changes -m1 ' . ProcessExecutor::escape($label));
        $this->executeCommand($command);
        $changes = $this->commandResult;
        if (strpos($changes, 'Change') !== 0) {
            return null;
        }
        $fields = explode(' ', $changes);

        return $fields[1];
    }

    /**
     * @param string $fromReference
     * @param string $toReference
     * @return mixed|null
     */
    public function getCommitLogs($fromReference, $toReference)
    {
        $fromChangeList = $this->getChangeList($fromReference);
        if ($fromChangeList === null) {
            return null;
        }
        $toChangeList = $this->getChangeList($toReference);
        if ($toChangeList === null) {
            return null;
        }
        $index = strpos($fromReference, '@');
        $main = substr($fromReference, 0, $index) . '/...';
        $command = $this->generateP4Command('filelog ' . ProcessExecutor::escape($main . '@' . $fromChangeList. ',' . $toChangeList));
        $this->executeCommand($command);

        return $this->commandResult;
    }

    public function getFilesystem()
    {
        if (empty($this->filesystem)) {
            $this->filesystem = new Filesystem($this->process);
        }

        return $this->filesystem;
    }

    public function setFilesystem(Filesystem $fs)
    {
        $this->filesystem = $fs;
    }
}
