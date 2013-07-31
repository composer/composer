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
    protected $p4port;
    protected $p4stream;
    protected $p4clientSpec;
    protected $p4depotType;
    protected $p4branch;

    final public function __construct($depot, $branch, $port, $path){
        $this->p4depot = $depot;
        $this->p4branch = $branch;
        $this->p4port = $port;
        $this->path = $path;
        $fs = new Filesystem();
        $fs->ensureDirectoryExists($path);
    }

    protected function getClient()
    {
        if (!isset($this->p4client)){
            $random_value = mt_rand(1000,9999);
            $clean_stream_name = str_replace("@", "", str_replace("/", "_", str_replace("//", "", $this->p4stream)));
            $this->p4client = "composer_perforce_" . $random_value . "_".$clean_stream_name;
        }
        return $this->p4client;
    }

    protected function getUser()
    {
        if (!isset($this->p4user)){
            $this->p4user = trim(shell_exec('echo $P4USER'));
        }
        return $this->p4user;
    }

    protected function getPath()
    {
        return $this->path;
    }

    protected function getPort()
    {
        return $this->p4port;
    }

    protected function getStream()
    {
        if (!isset($this->p4stream)){
            if ($this->isStream()){
                $this->p4stream = "//$this->p4depot/$this->p4branch";
            } else {
                $this->p4stream = "//$this->p4depot";
            }
        }
        return $this->p4stream;
    }

    protected function getStreamWithoutLabel()
    {
        $stream = $this->getStream();
        $index = strpos($stream, "@");
        if ($index === false){
            return $stream;
        }
        return substr($stream, 0, $index);
    }

    protected function getP4ClientSpec()
    {
        $p4clientSpec = $this->path . "/" . $this->getClient() . ".p4.spec";
        return $p4clientSpec;
    }

    protected function queryP4User(IOInterface $io){
        $this->getUser();
        if (strlen($this->p4user) <= 0){
            $this->p4user = $io->ask("Enter P4 User:");
        }
    }

    protected function queryP4Password(IOInterface $io){
        $password = trim(shell_exec('echo $P4PASSWD'));
        if (strlen($password) <= 0){
            $password = $io->ask("Enter password for Perforce user " . $this->getUser() . ": " );
        }
        return $password;
    }

    protected function isStream(){
        return (strcmp($this->p4depotType, "stream") === 0);
    }

    protected function generateP4Command($command, $useClient = true) {
        $p4Command = "p4 ";
        $p4Command = $p4Command . "-u " . $this->getUser() . " ";
        if ($useClient){
           $p4Command = $p4Command . "-c " . $this->getClient() . " ";
        }
        $p4Command = $p4Command . "-p " . $this->getPort() . " ";
        $p4Command = $p4Command . $command;
        return $p4Command;
    }

    protected function isLoggedIn(){
        $command = $this->generateP4Command("login -s ");
        $result = trim(shell_exec($command));
        $index = strpos($result, $this->getUser());
        if ($index === false){
            return false;
        }
        return true;
    }

    public function setStream($stream){
        $this->p4stream = $stream;
        $this->p4depotType = "stream";
    }

    public function syncCodeBase($label){
        $p4CreateClientCommand = $this->generateP4Command( "client -i < " . $this->getP4ClientSpec());
        print ("Perforce: syncCodeBase - client command:$p4CreateClientCommand \n");
        $result = shell_exec($p4CreateClientCommand);

        $prevDir = getcwd();
        chdir($this->path);

        $result = shell_exec("pwd");

        $p4SyncCommand = $this->generateP4Command( "sync -f //".$this->getClient()."/...");
        if (isset($label)){
            if (strcmp($label, "dev-master") != 0){
                $p4SyncCommand = $p4SyncCommand . "@" . $label;
            }
        }
        print ("Perforce: syncCodeBase - sync command:$p4SyncCommand \n");
        $result = shell_exec($p4SyncCommand);

        chdir($prevDir);
    }

    public function writeP4ClientSpec(){
        print ("Perforce: writeP4ClientSpec\n");
        $spec = fopen($this->getP4ClientSpec(), 'w');
        try {
            fwrite($spec, "Client: " . $this->getClient() . "\n\n");
            fwrite($spec, "Update: " . date("Y/m/d H:i:s") . "\n\n");
            fwrite($spec, "Access: " . date("Y/m/d H:i:s") . "\n" );
            fwrite($spec, "Owner:  " . $this->getUser() . "\n\n" );
            fwrite($spec, "Description:\n" );
            fwrite($spec, "  Created by " . $this->getUser() . " from composer.\n\n" );
            fwrite($spec, "Root: " .$this->getPath(). "\n\n" );
            fwrite($spec, "Options:  noallwrite noclobber nocompress unlocked modtime rmdir\n\n" );
            fwrite($spec, "SubmitOptions:  revertunchanged\n\n" );
            fwrite($spec, "LineEnd:  local\n\n" );
            if ($this->isStream()){
                fwrite($spec, "Stream:\n" );
                fwrite($spec, "  " . $this->getStreamWithoutLabel()."\n" );
            } else {
                fwrite($spec, "View:  " . $this->getStream() . "/...  //" . $this->getClient() . "/" . str_replace("//", "", $this->getStream()) . "/... \n");
            }
        }  catch(Exception $e){
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }

    public function getComposerFilePath($identifier)
    {
        if ($this->isStream()){
            $composerFilePath = $this->path . "/composer.json" ;
        } else {
            $composerFilePath = $this->path . "/" . $this->p4depot . "/composer.json" ;
        }
        return $composerFilePath;
    }

    public function p4Login(IOInterface $io){
        print ("Perforce: P4Login\n");
        $this->queryP4User($io);
        if (!$this->isLoggedIn()){
            $password = $this->queryP4Password($io);
            $command = "echo $password | " . $this->generateP4Command("login -a ");
            shell_exec($command);
        }
    }

    public static function checkServerExists($url)
    {
        print ("Perforce: checkServerExists\n");
        $result = shell_exec("p4 -p $url info -s");
        $index = strpos($result, "error");
        if ($index === false){
            return true;
        }
        return false;
    }

    public function getComposerInformation($identifier)
    {
        $index = strpos($identifier, "@");
        if ($index === false){
            $composer_json =  "$identifier/composer.json";
            return $this->getComposerInformationFromPath($composer_json);
        } else {
            return $this->getComposerInformationFromLabel($identifier, $index);
        }
    }
    public function getComposerInformationFromPath($composer_json)
    {
        $command = $this->generateP4Command(" print $composer_json", false);
        print ("Perforce: getComposerInformation: command: $command\n");
        $result = shell_exec($command);
        $index = strpos($result, "{");
        if ($index === false){
            return "";
        }
        if ($index >=0){
            $rawData = substr($result, $index);
            $composer_info = json_decode($rawData, true);
            return $composer_info;
        }
        return "";
    }

    public function getComposerInformationFromLabel($identifier, $index)
    {
        $composer_json_path = substr($identifier, 0, $index) . "/composer.json" . substr($identifier, $index);
        $command = $this->generateP4Command(" files $composer_json_path", false);
        print("Perforce: getComposerInformationFromTag: $identifier, command:\n $command\n");
        $result = shell_exec($command);
        print("Perforce: getComposerInformationFromTag: result: \n $result\n");
        $index2 = strpos($result, "no such file(s).");
        if ($index2 === false){
            $index3 = strpos($result, "change");
            if (!($index3 ===false )){
                $phrase = trim(substr($result, $index3));
                $fields = explode(" ", $phrase);
                $id = $fields[1];
                $composer_json = substr($identifier, 0, $index) . "/composer.json@" . $id;
                return $this->getComposerInformationFromPath($composer_json);
            }
        }
        return "";
    }

    public function getBranches()
    {
        $possible_branches = array();
        if (!$this->isStream()){
             $branches[$this->p4branch] =  $this->p4stream;
        } else {
            $command = $this->generateP4Command("streams //$this->p4depot/...");
            $result = shell_exec($command);
            $resArray = explode("\n", $result);
            foreach ($resArray as $line){
                $resBits = explode(" ", $line);
                if (count($resBits) > 4){
                    $branch = substr($resBits[4], 1, strlen($resBits[4])-2);
                    $possible_branches[$branch] = $resBits[1];
                }
            }
        }
        $branches = array();
        $branches['master'] = $possible_branches[$this->p4branch];
        print ("Perforce: getBranches: returning: \n" . var_export($branches, true) . "\n");
        return $branches;
    }

    public function getTags()
    {
        $command = $this->generateP4Command("labels");
        $result = shell_exec($command);
        $resArray = explode("\n", $result);
        print("Perforce:getTags - result:\n$result\n");
        $tags = array();
        foreach ($resArray as $line){
            $index = strpos($line, "Label");
            if (!($index===false)){
                $fields = explode(" ", $line);
                $tags[$fields[1]] = $this->getStream()."@" . $fields[1];
            }
        }
        return $tags;
    }

    public function checkStream ()
    {
        $command = $this->generateP4Command("depots");
        $result = shell_exec($command);
        $resArray = explode("\n", $result);
        foreach ($resArray as $line){
            $index = strpos($line, "Depot");
            if (!($index===false)){
                $fields = explode(" ", $line);
                if (strcmp($this->p4depot, $fields[1]) === 0){
                    $this->p4depotType = $fields[3];
                    return $this->isStream();
                }
            }
        }
        return false;
    }
}