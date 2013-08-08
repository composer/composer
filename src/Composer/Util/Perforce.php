<?php
/**
 * Created by JetBrains PhpStorm.
 * User: matt.whittom
 * Date: 7/23/13
 * Time: 3:22 PM
 * To change this template use File | Settings | File Templates.
 */

namespace Composer\Util;

use Composer\IO\IOInterface;


class Perforce {

    protected $path;
    protected $p4client;
    protected $p4user;
    protected $p4password;
    protected $p4port;
    protected $p4stream;
    protected $p4clientSpec;
    protected $p4depotType;
    protected $p4branch;
    protected $process;

    public function __construct($depot, $branch, $port, $path, ProcessExecutor $process = null, $p4user = null, $p4password = null) {
        $this->p4depot = $depot;
        $this->p4branch = $branch;
        $this->p4port = $port;
        $this->path = $path;
        $this->process = $process ? : new ProcessExecutor;
        $fs = new Filesystem();
        $fs->ensureDirectoryExists($path);
        if (isset($p4user)){
            $this->p4user = $p4user;
        } else {
            $this->p4user = $this->getP4variable("P4USER");
        }
        if (isset($p4password)){
            $this->p4password = $p4password;
        }
    }

    protected function getRandomValue() {
        return mt_rand(1000, 9999);
    }

    protected function executeCommand($command) {
        $result = "";
        $this->process->execute($command, $result);

        return $result;
    }

    protected function getClient() {
        if (!isset($this->p4client)) {
            $random_value = $this->getRandomValue();
            $clean_stream_name = str_replace("@", "", str_replace("/", "_", str_replace("//", "", $this->getStream())));
            $this->p4client = "composer_perforce_" . $random_value . "_" . $clean_stream_name;
        }

        return $this->p4client;
    }

    protected function getPath() {
        return $this->path;
    }

    protected function getPort() {
        return $this->p4port;
    }

    protected function getStream() {
        if (!isset($this->p4stream)) {
            if ($this->isStream()) {
                $this->p4stream = "//$this->p4depot/$this->p4branch";
            }
            else {
                $this->p4stream = "//$this->p4depot";
            }
        }

        return $this->p4stream;
    }

    protected function getStreamWithoutLabel() {
        $stream = $this->getStream();
        $index = strpos($stream, "@");
        if ($index === FALSE) {
            return $stream;
        }

        return substr($stream, 0, $index);
    }

    protected function getP4ClientSpec() {
        $p4clientSpec = $this->path . "/" . $this->getClient() . ".p4.spec";

        return $p4clientSpec;
    }

    public function getUser() {
        return $this->p4user;
    }

    public function queryP4User(IOInterface $io) {
        $this->getUser();
        if (strlen($this->p4user) <= 0) {
            $this->p4user = $io->ask("Enter P4 User:");
            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                $command = "p4 set P4USER=$this->p4user";
            } else {
                $command = "export P4USER=$this->p4user";
            }
            $result = $this->executeCommand($command);
        }
    }

    protected function getP4variable($name){
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $command = "p4 set";
            $result = $this->executeCommand($command);
            $resArray = explode("\n", $result);
            foreach ($resArray as $line) {
                $fields = explode("=", $line);
                if (strcmp($name, $fields[0]) == 0){
                    $index = strpos($fields[1], " ");
                    if ($index === false){
                        $value = $fields[1];
                    } else {
                        $value = substr($fields[1], 0, $index);
                    }
                    $value = trim($value);
                    return $value;
                }
            }
        } else {
            $command = 'echo $' . $name;
            $result = trim($this->executeCommand($command));
            return $result;
        }

    }

    protected function queryP4Password(IOInterface $io) {
        if (isset($this->p4password)){
            return $this->p4password;
        }
        $password = $this->getP4variable("P4PASSWD");
        if (strlen($password) <= 0) {
            $password = $io->askAndHideAnswer("Enter password for Perforce user " . $this->getUser() . ": ");
        }
        $this->p4password = $password;
        return $password;
    }

    protected function isStream() {
        return (strcmp($this->p4depotType, "stream") === 0);
    }

    protected function generateP4Command($command, $useClient = TRUE) {
        $p4Command = "p4 ";
        $p4Command = $p4Command . "-u " . $this->getUser() . " ";
        if ($useClient) {
            $p4Command = $p4Command . "-c " . $this->getClient() . " ";
        }
        $p4Command = $p4Command . "-p " . $this->getPort() . " ";
        $p4Command = $p4Command . $command;

        return $p4Command;
    }

    protected function isLoggedIn() {
        $command = $this->generateP4Command("login -s", FALSE);
        $result = trim($this->executeCommand($command));
        $index = strpos($result, $this->getUser());
        if ($index === FALSE) {
            return FALSE;
        }
        return TRUE;
    }

    public function setStream($stream) {
        $this->p4stream = $stream;
        $this->p4depotType = "stream";
    }

    public function connectClient() {
        $p4CreateClientCommand = $this->generateP4Command("client -i < " . $this->getP4ClientSpec());
        $this->executeCommand($p4CreateClientCommand);
    }

    public function syncCodeBase($label) {
        $prevDir = getcwd();
        chdir($this->path);

        $this->executeCommand("pwd");

        $p4SyncCommand = $this->generateP4Command("sync -f ");
        if (isset($label)) {
            if (strcmp($label, "dev-master") != 0) {
                $p4SyncCommand = $p4SyncCommand . "@" . $label;
            }
        }
        $this->executeCommand($p4SyncCommand);

        chdir($prevDir);
    }

    protected function writeClientSpecToFile($spec) {
        fwrite($spec, "Client: " . $this->getClient() . "\n\n");
        fwrite($spec, "Update: " . date("Y/m/d H:i:s") . "\n\n");
        fwrite($spec, "Access: " . date("Y/m/d H:i:s") . "\n");
        fwrite($spec, "Owner:  " . $this->getUser() . "\n\n");
        fwrite($spec, "Description:\n");
        fwrite($spec, "  Created by " . $this->getUser() . " from composer.\n\n");
        fwrite($spec, "Root: " . $this->getPath() . "\n\n");
        fwrite($spec, "Options:  noallwrite noclobber nocompress unlocked modtime rmdir\n\n");
        fwrite($spec, "SubmitOptions:  revertunchanged\n\n");
        fwrite($spec, "LineEnd:  local\n\n");
        if ($this->isStream()) {
            fwrite($spec, "Stream:\n");
            fwrite($spec, "  " . $this->getStreamWithoutLabel() . "\n");
        }
        else {
            fwrite(
                $spec, "View:  " . $this->getStream() . "/...  //" . $this->getClient() . "/" . str_replace(
                         "//", "", $this->getStream()
                     ) . "/... \n"
            );
        }
    }

    public function writeP4ClientSpec() {
        $spec = fopen($this->getP4ClientSpec(), 'w');
        try {
            $this->writeClientSpecToFile($spec);
        } catch (Exception $e) {
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }


    protected function read($pipe, $name){
        if (feof($pipe)) {
            return;
        }
        $line = fgets($pipe);
        while ($line != false){
            $line = fgets($pipe);
        }
        return;
    }

    public function windowsLogin($password){
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "a")
        );
        $command = $this->generateP4Command(" login -a");
        $process = proc_open($command, $descriptorspec, $pipes);
        if (!is_resource($process)){
            return false;
        }
        fwrite($pipes[0], $password);
        fclose($pipes[0]);

        $this->read($pipes[1], "Output");
        $this->read($pipes[2], "Error");

        fclose($pipes[1]);
        fclose($pipes[2]);

        $return_code = proc_close($process);

        return $return_code;
    }


    public function p4Login(IOInterface $io) {
        $this->queryP4User($io);
        if (!$this->isLoggedIn()) {
            $password = $this->queryP4Password($io);
            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                $this->windowsLogin($password);
            } else {
                $command = "echo $password | ".$this->generateP4Command(" login -a", false);
                $this->executeCommand($command);
            }
        }
    }

    public static function checkServerExists($url, ProcessExecutor $process_executor) {
        $process = $process_executor ? : new ProcessExecutor;
        $result = "";
        $process->execute("p4 -p $url info -s", $result);
        $index = strpos($result, "error");
        if ($index === FALSE) {
            return TRUE;
        }

        return FALSE;
    }

    public function getComposerInformation($identifier) {
        $index = strpos($identifier, "@");
        if ($index === FALSE) {
            $composer_json = "$identifier/composer.json";

            return $this->getComposerInformationFromPath($composer_json);
        }
        else {
            return $this->getComposerInformationFromLabel($identifier, $index);
        }
    }

    public function getComposerInformationFromPath($composer_json) {
        $command = $this->generateP4Command(" print $composer_json");
        $result = $this->executeCommand($command);
        $index = strpos($result, "{");
        if ($index === FALSE) {
            return "";
        }
        if ($index >= 0) {
            $rawData = substr($result, $index);
            $composer_info = json_decode($rawData, TRUE);

            return $composer_info;
        }

        return "";
    }

    public function getComposerInformationFromLabel($identifier, $index) {
        $composer_json_path = substr($identifier, 0, $index) . "/composer.json" . substr($identifier, $index);
        $command = $this->generateP4Command(" files $composer_json_path", FALSE);
        $result = $this->executeCommand($command);
        $index2 = strpos($result, "no such file(s).");
        if ($index2 === FALSE) {
            $index3 = strpos($result, "change");
            if (!($index3 === FALSE)) {
                $phrase = trim(substr($result, $index3));
                $fields = explode(" ", $phrase);
                $id = $fields[1];
                $composer_json = substr($identifier, 0, $index) . "/composer.json@" . $id;

                return $this->getComposerInformationFromPath($composer_json);
            }
        }

        return "";
    }

    public function getBranches() {
        $possible_branches = array();
        if (!$this->isStream()) {
            $possible_branches[$this->p4branch] = $this->getStream();
        }
        else {
            $command = $this->generateP4Command("streams //$this->p4depot/...");
            $result = "";
            $this->process->execute($command, $result);
            $resArray = explode("\n", $result);
            foreach ($resArray as $line) {
                $resBits = explode(" ", $line);
                if (count($resBits) > 4) {
                    $branch = preg_replace("/[^A-Za-z0-9 ]/", '', $resBits[4]);
                    $possible_branches[$branch] = $resBits[1];
                }
            }
        }
        $branches = array();
        $branches['master'] = $possible_branches[$this->p4branch];

        return $branches;
    }

    public function getTags() {
        $command = $this->generateP4Command("labels");
        $result = $this->executeCommand($command);
        $resArray = explode("\n", $result);
        $tags = array();
        foreach ($resArray as $line) {
            $index = strpos($line, "Label");
            if (!($index === FALSE)) {
                $fields = explode(" ", $line);
                $tags[$fields[1]] = $this->getStream() . "@" . $fields[1];
            }
        }
        return $tags;
    }

    public function checkStream() {
        $command = $this->generateP4Command("depots", FALSE);
        $result = $this->executeCommand($command);
        $resArray = explode("\n", $result);
        foreach ($resArray as $line) {
            $index = strpos($line, "Depot");
            if (!($index === FALSE)) {
                $fields = explode(" ", $line);
                if (strcmp($this->p4depot, $fields[1]) === 0) {
                    $this->p4depotType = $fields[3];

                    return $this->isStream();
                }
            }
        }

        return FALSE;
    }
}