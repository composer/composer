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

    final public function __construct($stream, $port, $path){
        $this->p4stream = $stream;
        $this->p4port = $port;
        $this->path = $path;
        $fs = new Filesystem();
        $fs->ensureDirectoryExists($path);
    }

    protected function getClient()
    {
        if (!isset($this->p4client)){
            $random_value = mt_rand(1000,9999);
            $this->p4client = "composer_perforce_" . $random_value . "_".str_replace("/", "_", str_replace("//", "", $this->p4stream));
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
        return $this->p4stream;
    }
    protected function getP4ClientSpec()
    {
        $p4clientSpec = $this->path . "/" . $this->getClient() . ".p4.spec";
        return $p4clientSpec;
    }

    public function syncCodeBase(){
        $p4CreateClientCommand = $this->generateP4Command( "client -i < " . $this->getP4ClientSpec());
        $result = shell_exec($p4CreateClientCommand);

        $prevDir = getcwd();
        chdir($this->path);

        $result = shell_exec("pwd");

        $p4SyncCommand = $this->generateP4Command( "sync -f //".$this->getClient()."/...");
        $result = shell_exec($p4SyncCommand);

        chdir($prevDir);
    }

    public function writeP4ClientSpec(){

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
            fwrite($spec, "Stream:\n" );
            fwrite($spec, "  " . $this->getStream()."\n" );
        }  catch(Exception $e){
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }

    public function getComposerFilePath($identifier)
    {
        $composerFilePath = $this->path . "/composer.json" ;
        print ("\nPerforceUtility - getComposerPath: $composerFilePath\n\n");
        return $composerFilePath;
    }
    protected function generateP4Command($command) {
        $p4Command = "p4 ";
        $p4Command = $p4Command . "-u " . $this->getUser() . " ";
        $p4Command = $p4Command . "-c " . $this->getClient() . " ";
        $p4Command = $p4Command . "-p " . $this->getPort() . " ";
        $p4Command = $p4Command . $command;
        return $p4Command;
    }

    public function p4Login(IOInterface $io){
        $user = $this->getUser();
        $result = trim(shell_exec("p4 login -s"));
        $index = strpos($result, $user);
        if ($index === false){
            $password = trim(shell_exec('echo $P4PASSWD'));
            if (strlen($password) <= 0){
                $password = $io->ask("Enter password for Perforce user " . $this->getUser() . ": " );
            }
            $command = "echo $password | p4 login -a ";
            shell_exec($command);
        }
    }

    public static function checkServerExists($url)
    {
        $result = shell_exec("p4 -p $url info -s");
        $index = strpos($result, "error");
        if ($index === false){
            return true;
        }
        return false;
    }

    public function getComposerInformation($identifier)
    {
        $composerFilePath =$this->getComposerFilePath($identifier);
        $contents = file_get_contents($composerFilePath);
        $composer_info = json_decode($contents, true);
        return $composer_info;
    }
}