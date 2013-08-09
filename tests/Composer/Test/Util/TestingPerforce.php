<?php
/**
 * Created by JetBrains PhpStorm.
 * User: matt.whittom
 * Date: 7/31/13
 * Time: 2:37 PM
 * To change this template use File | Settings | File Templates.
 */

namespace Composer\Test\Util;


use Composer\Util\Perforce;
use org\bovigo\vfs\vfsStream;


class TestingPerforce extends Perforce {
    public $windows_flag;

    public function __construct($repoConfig, $port, $path, $process=null){
        parent::__construct($repoConfig, $port, $path, $process);
        $this->windows_flag = false;
    }
    /*
     * Override functions
     */
    protected function getRandomValue() {
        return "TEST";
    }
    protected function isWindows(){
        return $this->windows_flag;
    }

//    protected function executeCommand($command) {
//        $this->previousCommand = $this->lastCommand;
//        $this->lastCommand = $command;
//        $result = $this->commandReturnValue;
//        $this->commandReturnValue = $this->nextCommandReturnValue;
//        $this->nextCommandReturnValue = null;
//        return $result;
//    }

    public function writeP4ClientSpec() {
        $spec = fopen(vfsStream::url($this->getP4ClientSpec()), 'w');
        $this->writeClientSpecToFile($spec);
        fclose($spec);
    }

    /*
     * Test Helper functions
     */
    public function setDepotType($depotType) {
        $this->p4depotType = $depotType;
        $this->p4stream    = NULL;
    }

    /*
     * Functions to expose protected methods for testing:
     */
    public function setP4User($p4user){
        $this->p4user = $p4user;
    }
    public function setP4Password($password){
        $this->p4password = $password;
    }

    public function testGetClient() {
        return $this->getClient();
    }

    public function testGetStream() {
        return $this->getStream();
    }

    public function testGetStreamWithoutLabel() {
        return $this->getStreamWithoutLabel();
    }

    public function testGetClientSpec() {
        return $this->getP4ClientSpec();
    }

    public function testGenerateP4Command($command, $useClient = TRUE) {
        return $this->generateP4Command($command, $useClient);
    }

    public function testIsLoggedIn()
    {
        return $this->isLoggedIn();
    }

    public function testIsStream()
    {
        return $this->isStream();
    }

    public function testGetP4Variable($name)
    {
        return $this->testGetP4Variable($name);
    }

    public function testQueryP4Password($io)
    {
        return $this->queryP4Password($io);
    }
}