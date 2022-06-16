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

namespace Composer\Util;

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Symfony\Component\Process\Process;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 *
 * @phpstan-type RepoConfig array{unique_perforce_client_name?: string, depot?: string, branch?: string, p4user?: string, p4password?: string}
 */
class Perforce
{
    /** @var string */
    protected $path;
    /** @var ?string */
    protected $p4Depot;
    /** @var ?string */
    protected $p4Client;
    /** @var ?string */
    protected $p4User;
    /** @var ?string */
    protected $p4Password;
    /** @var string */
    protected $p4Port;
    /** @var ?string */
    protected $p4Stream;
    /** @var string */
    protected $p4ClientSpec;
    /** @var ?string */
    protected $p4DepotType;
    /** @var ?string */
    protected $p4Branch;
    /** @var ProcessExecutor */
    protected $process;
    /** @var string */
    protected $uniquePerforceClientName;
    /** @var bool */
    protected $windowsFlag;
    /** @var string */
    protected $commandResult;

    /** @var IOInterface */
    protected $io;

    /** @var ?Filesystem */
    protected $filesystem;

    /**
     * @phpstan-param RepoConfig $repoConfig
     * @param string             $port
     * @param string             $path
     * @param ProcessExecutor    $process
     * @param bool               $isWindows
     * @param IOInterface        $io
     */
    public function __construct($repoConfig, string $port, string $path, ProcessExecutor $process, bool $isWindows, IOInterface $io)
    {
        $this->windowsFlag = $isWindows;
        $this->p4Port = $port;
        $this->initializePath($path);
        $this->process = $process;
        $this->initialize($repoConfig);
        $this->io = $io;
    }

    /**
     * @phpstan-param RepoConfig $repoConfig
     * @param string             $port
     * @param string             $path
     * @param ProcessExecutor    $process
     * @param IOInterface        $io
     *
     * @return self
     */
    public static function create($repoConfig, string $port, string $path, ProcessExecutor $process, IOInterface $io): self
    {
        return new Perforce($repoConfig, $port, $path, $process, Platform::isWindows(), $io);
    }

    /**
     * @param string          $url
     * @param ProcessExecutor $processExecutor
     *
     * @return bool
     */
    public static function checkServerExists(string $url, ProcessExecutor $processExecutor): bool
    {
        return 0 === $processExecutor->execute('p4 -p ' . ProcessExecutor::escape($url) . ' info -s', $ignoredOutput);
    }

    /**
     * @phpstan-param RepoConfig $repoConfig
     *
     * @return void
     */
    public function initialize($repoConfig): void
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

    /**
     * @param string|null $depot
     * @param string|null $branch
     *
     * @return void
     */
    public function initializeDepotAndBranch(?string $depot, ?string $branch): void
    {
        if (isset($depot)) {
            $this->p4Depot = $depot;
        }
        if (isset($branch)) {
            $this->p4Branch = $branch;
        }
    }

    /**
     * @return non-empty-string
     */
    public function generateUniquePerforceClientName(): string
    {
        return gethostname() . "_" . time();
    }

    /**
     * @return void
     */
    public function cleanupClientSpec(): void
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

    /**
     * @param non-empty-string $command
     *
     * @return int
     */
    protected function executeCommand($command): int
    {
        $this->commandResult = '';

        return $this->process->execute($command, $this->commandResult);
    }

    /**
     * @return string
     */
    public function getClient(): string
    {
        if (!isset($this->p4Client)) {
            $cleanStreamName = str_replace(array('//', '/', '@'), array('', '_', ''), $this->getStream());
            $this->p4Client = 'composer_perforce_' . $this->uniquePerforceClientName . '_' . $cleanStreamName;
        }

        return $this->p4Client;
    }

    /**
     * @return string
     */
    protected function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param string $path
     *
     * @return void
     */
    public function initializePath(string $path): void
    {
        $this->path = $path;
        $fs = $this->getFilesystem();
        $fs->ensureDirectoryExists($path);
    }

    /**
     * @return string
     */
    protected function getPort(): string
    {
        return $this->p4Port;
    }

    /**
     * @param string $stream
     *
     * @return void
     */
    public function setStream(string $stream): void
    {
        $this->p4Stream = $stream;
        $index = strrpos($stream, '/');
        //Stream format is //depot/stream, while non-streaming depot is //depot
        if ($index > 2) {
            $this->p4DepotType = 'stream';
        }
    }

    /**
     * @return bool
     */
    public function isStream(): bool
    {
        return is_string($this->p4DepotType) && (strcmp($this->p4DepotType, 'stream') === 0);
    }

    /**
     * @return string
     */
    public function getStream(): string
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

    /**
     * @param string $stream
     *
     * @return string
     */
    public function getStreamWithoutLabel(string $stream): string
    {
        $index = strpos($stream, '@');
        if ($index === false) {
            return $stream;
        }

        return substr($stream, 0, $index);
    }

    /**
     * @return non-empty-string
     */
    public function getP4ClientSpec(): string
    {
        return $this->path . '/' . $this->getClient() . '.p4.spec';
    }

    /**
     * @return string|null
     */
    public function getUser(): ?string
    {
        return $this->p4User;
    }

    /**
     * @param string|null $user
     *
     * @return void
     */
    public function setUser(?string $user): void
    {
        $this->p4User = $user;
    }

    /**
     * @return void
     */
    public function queryP4User(): void
    {
        $this->getUser();
        if (strlen((string) $this->p4User) > 0) {
            return;
        }
        $this->p4User = $this->getP4variable('P4USER');
        if (strlen((string) $this->p4User) > 0) {
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

    /**
     * @param  string  $name
     * @return ?string
     */
    protected function getP4variable(string $name): ?string
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

    /**
     * @return string|null
     */
    public function queryP4Password(): ?string
    {
        if (isset($this->p4Password)) {
            return $this->p4Password;
        }
        $password = $this->getP4variable('P4PASSWD');
        if (strlen((string) $password) <= 0) {
            $password = $this->io->askAndHideAnswer('Enter password for Perforce user ' . $this->getUser() . ': ');
        }
        $this->p4Password = $password;

        return $password;
    }

    /**
     * @param string $command
     * @param bool   $useClient
     *
     * @return non-empty-string
     */
    public function generateP4Command(string $command, bool $useClient = true): string
    {
        $p4Command = 'p4 ';
        $p4Command .= '-u ' . $this->getUser() . ' ';
        if ($useClient) {
            $p4Command .= '-c ' . $this->getClient() . ' ';
        }
        $p4Command .= '-p ' . $this->getPort() . ' ' . $command;

        return $p4Command;
    }

    /**
     * @return bool
     */
    public function isLoggedIn(): bool
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

    /**
     * @return void
     */
    public function connectClient(): void
    {
        $p4CreateClientCommand = $this->generateP4Command(
            'client -i < ' . str_replace(" ", "\\ ", $this->getP4ClientSpec())
        );
        $this->executeCommand($p4CreateClientCommand);
    }

    /**
     * @param string|null $sourceReference
     *
     * @return void
     */
    public function syncCodeBase(?string $sourceReference): void
    {
        $prevDir = Platform::getCwd();
        chdir($this->path);
        $p4SyncCommand = $this->generateP4Command('sync -f ');
        if (null !== $sourceReference) {
            $p4SyncCommand .= '@' . $sourceReference;
        }
        $this->executeCommand($p4SyncCommand);
        chdir($prevDir);
    }

    /**
     * @param resource|false $spec
     *
     * @return void
     */
    public function writeClientSpecToFile($spec): void
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

    /**
     * @return void
     */
    public function writeP4ClientSpec(): void
    {
        $clientSpec = $this->getP4ClientSpec();
        $spec = fopen($clientSpec, 'wb');
        try {
            $this->writeClientSpecToFile($spec);
        } catch (\Exception $e) {
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }

    /**
     * @param resource $pipe
     * @param mixed    $name
     *
     * @return void
     */
    protected function read($pipe, $name): void
    {
        if (feof($pipe)) {
            return;
        }
        $line = fgets($pipe);
        while ($line !== false) {
            $line = fgets($pipe);
        }
    }

    /**
     * @param string|null $password
     *
     * @return int
     */
    public function windowsLogin(?string $password): int
    {
        $command = $this->generateP4Command(' login -a');

        $process = Process::fromShellCommandline($command, null, null, $password);

        return $process->run();
    }

    /**
     * @return void
     */
    public function p4Login(): void
    {
        $this->queryP4User();
        if (!$this->isLoggedIn()) {
            $password = $this->queryP4Password();
            if ($this->windowsFlag) {
                $this->windowsLogin($password);
            } else {
                $command = 'echo ' . ProcessExecutor::escape($password)  . ' | ' . $this->generateP4Command(' login -a', false);
                $exitCode = $this->executeCommand($command);
                if ($exitCode) {
                    throw new \Exception("Error logging in:" . $this->process->getErrorOutput());
                }
            }
        }
    }

    /**
     * @param string $identifier
     *
     * @return mixed[]|null
     */
    public function getComposerInformation(string $identifier): ?array
    {
        $composerFileContent = $this->getFileContent('composer.json', $identifier);

        if (!$composerFileContent) {
            return null;
        }

        return json_decode($composerFileContent, true);
    }

    /**
     * @param string $file
     * @param string $identifier
     *
     * @return string|null
     */
    public function getFileContent(string $file, string $identifier): ?string
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

    /**
     * @param string $file
     * @param string $identifier
     *
     * @return string|null
     */
    public function getFilePath(string $file, string $identifier): ?string
    {
        $index = strpos($identifier, '@');
        if ($index === false) {
            return $identifier. '/' . $file;
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

    /**
     * @return array{master: string}
     */
    public function getBranches(): array
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
                    $branch = Preg::replace('/[^A-Za-z0-9 ]/', '', $resBits[4]);
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

        return array('master' => $possibleBranches[$this->p4Branch] . '@'. $lastCommitNum);
    }

    /**
     * @return array<string, string>
     */
    public function getTags(): array
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

    /**
     * @return bool
     */
    public function checkStream(): bool
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
     * @param  string     $reference
     * @return mixed|null
     */
    protected function getChangeList(string $reference): mixed
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
     * @param  string     $fromReference
     * @param  string     $toReference
     * @return mixed|null
     */
    public function getCommitLogs(string $fromReference, string $toReference): mixed
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

    /**
     * @return Filesystem
     */
    public function getFilesystem(): Filesystem
    {
        if (null === $this->filesystem) {
            $this->filesystem = new Filesystem($this->process);
        }

        return $this->filesystem;
    }

    /**
     * @return void
     */
    public function setFilesystem(Filesystem $fs): void
    {
        $this->filesystem = $fs;
    }
}
