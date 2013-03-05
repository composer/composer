<?php

namespace Composer;

/**
* The Runtime class acts as a stub for the runtime methods
*
* @author John Stevenson <john-stevenson@blueyonder.co.uk>
*/
class Runtime
{
    /**
    * Allows updated methodsClass to be used
    *
    * @var Runtime\MethodsClass
    */
    public $methodsClass;

    public function __call($name, $arguments)
    {
        if (null === $this->methodsClass) {
            $this->methodsClass = new Runtime\MethodsClass($this, __DIR__);
        }

        if (method_exists($this->methodsClass, $name)) {
            return call_user_func_array(array($this->methodsClass, $name), $arguments);
        }
    }
}
