<?php

class FirstClass
{
    /**
     * @var int
     */
    private $firstProp = 9;

    public function funMethod()
    {
        function() {
            $this->firstProp;
        };

        call_user_func(function() {
            $this->funMethod();
        }, $this);

        $bind = 'bind';
        function() use($bind) {

        };
    }
}

function global_ok() {
    $_SERVER['REMOTE_ADDR'];
}

function global_this() {
    // not checked by our rule, it is checked with standard phpstan rule on level 0
    $this['REMOTE_ADDR'];
}
