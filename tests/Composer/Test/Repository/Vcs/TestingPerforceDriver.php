<?php
/**
 * Created by JetBrains PhpStorm.
 * User: matt.whittom
 * Date: 8/9/13
 * Time: 10:42 AM
 * To change this template use File | Settings | File Templates.
 */

namespace Composer\Test\Repository\Vcs;

use Composer\Repository\Vcs\PerforceDriver;

class TestingPerforceDriver extends PerforceDriver {

    /*
     * Test Helper functions
    */
    public function getDepot(){
        return $this->depot;
    }
    public function getBranch(){
        return $this->branch;
    }

}