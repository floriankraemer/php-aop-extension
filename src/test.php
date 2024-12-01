<?php
class TestClass
{
    public function myMethod()
    {
        echo "Original method execution.\n";
    }
}

$obj = new TestClass();
$obj->myMethod();
