<?php
/**
 * Created by JetBrains PhpStorm.
 * User: matt.whittom
 * Date: 7/23/13
 * Time: 3:22 PM
 * To change this template use File | Settings | File Templates.
 */

namespace Composer\Util;


class Perforce {
    public function syncCodeBase($clientSpec, $targetDir, $p4client){
        $p4CreateClientCommand = "p4 client -i < $clientSpec";
        print ("\nPerforceDriver create client: $p4CreateClientCommand\n");
        $result = shell_exec($p4CreateClientCommand);
        print ("result: $result\n\n");

        $prevDir = getcwd();
        chdir($targetDir);

        //write p4 config file
        $p4ConfigFileSpec = "$targetDir/p4config.config";
        $p4ConfigFile = fopen($p4ConfigFileSpec, 'w');
        fwrite($p4ConfigFile, "P4CLIENT=$p4client");
        fclose($p4ConfigFile);

        $testCommand = "pwd";
        print ("PerforceDriver test dir command: $testCommand\n");
        $result = shell_exec($testCommand);
        print ("result: $result\n\n");

        $p4SyncCommand = "p4 sync -f //$p4client/...";
        print ("PerforceDriver sync client: $p4SyncCommand\n");
        $result = shell_exec($p4SyncCommand);
        print ("result: $result\n\n");

        chdir($prevDir);
    }

    public function writeP4ClientSpec($clientSpec, $targetDir, $p4client, $stream){
        $fs = new Filesystem();
        $fs->ensureDirectoryExists(dirname($clientSpec));

        $p4user = trim(shell_exec('echo $P4USER'));
        print ("PerforceDriver: writing to client spec: $clientSpec\n\n");
        $spec = fopen($clientSpec, 'w');
        try {
            fwrite($spec, "Client: $p4client\n\n");
            fwrite($spec, "Update: " . date("Y/m/d H:i:s") . "\n\n");
            fwrite($spec, "Access: " . date("Y/m/d H:i:s") . "\n" );
            fwrite($spec, "Owner:  $p4user\n\n" );
            fwrite($spec, "Description:\n" );
            fwrite($spec, "  Created by $p4user from composer.\n\n" );
            fwrite($spec, "Root: $targetDir\n\n" );
            fwrite($spec, "Options:  noallwrite noclobber nocompress unlocked modtime rmdir\n\n" );
            fwrite($spec, "SubmitOptions:  revertunchanged\n\n" );
            fwrite($spec, "LineEnd:  local\n\n" );
            fwrite($spec, "Stream:\n" );
            fwrite($spec, "  $stream\n" );
        }  catch(Exception $e){
            fclose($spec);
            throw $e;
        }
        fclose($spec);
    }


}