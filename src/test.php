<?php
$pointcut = new Pointcut();
$pointcut->setExpression("execution(*->methodName)");

$result = $pointcut->matches("methodName");
var_dump($result); // Expect: true

$result = $pointcut->matches("otherMethod");
var_dump($result); // Expect: false