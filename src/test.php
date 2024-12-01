<?php
$pointcut = new Pointcut();
$advice = new Advice(function ()
{
    echo "Advice executed!";
});

$aspect = new Aspect();
$aspect->addAdvice($pointcut, $advice);

var_dump($aspect);
