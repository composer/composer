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

class UserFunc
{
    public static function withArray($func, $args) {
        $numArgs = count($args);
        
        if ($numArgs >= 1 && $numArgs <= 9) {
            // make a 0-indexed array out of params.
            // its not documented behaviour for call_user_func_array 
            // but Composer relies on it in e.g. DefaultPolicy
            $args0 = array();
            foreach($args as $val) {
                $args0[] = $val;
            }
        }
        if (is_array($func)) {
            list ($objOrClass, $method) = $func;
            
            if (is_object($objOrClass)) {
                switch ($numArgs) {
                    case 0: return $objOrClass->$method();
                    case 1: return $objOrClass->$method($args0[0]);
                    case 2: return $objOrClass->$method($args0[0], $args0[1]);
                    case 3: return $objOrClass->$method($args0[0], $args0[1], $args0[2]);
                    case 4: return $objOrClass->$method($args0[0], $args0[1], $args0[2], $args0[3]);
                    case 5: return $objOrClass->$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4]);
                    case 6: return $objOrClass->$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5]);
                    case 7: return $objOrClass->$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6]);
                    case 8: return $objOrClass->$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6], $args0[7]);
                    case 9: return $objOrClass->$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6], $args0[7], $args0[8]);
                }
            } else {
                switch ($numArgs) {
                    case 0: return $objOrClass::$method();
                    case 1: return $objOrClass::$method($args0[0]);
                    case 2: return $objOrClass::$method($args0[0], $args0[1]);
                    case 3: return $objOrClass::$method($args0[0], $args0[1], $args0[2]);
                    case 4: return $objOrClass::$method($args0[0], $args0[1], $args0[2], $args0[3]);
                    case 5: return $objOrClass::$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4]);
                    case 6: return $objOrClass::$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5]);
                    case 7: return $objOrClass::$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6]);
                    case 8: return $objOrClass::$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6], $args0[7]);
                    case 9: return $objOrClass::$method($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6], $args0[7], $args0[8]);
                }
            }            
        }
                
        switch ($numArgs) {
            case 0: return $func();
            case 1: return $func($args0[0]);
            case 2: return $func($args0[0], $args0[1]);
            case 3: return $func($args0[0], $args0[1], $args0[2]);
            case 4: return $func($args0[0], $args0[1], $args0[2], $args0[3]);
            case 5: return $func($args0[0], $args0[1], $args0[2], $args0[3], $args0[4]);
            case 6: return $func($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5]);
            case 7: return $func($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6]);
            case 8: return $func($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6], $args0[7]);
            case 9: return $func($args0[0], $args0[1], $args0[2], $args0[3], $args0[4], $args0[5], $args0[6], $args0[7], $args0[8]);
        }
        
        // call_user_func_array is faster when more arguments are in the game
        return call_user_func_array($func, $args);
    }
}