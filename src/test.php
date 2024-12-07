<?php

new JoinPoint();

// Define a sample class with a method to intercept
class MyClass
{
    public function myMethod($input)
    {
        return "Hello, " . $input;
    }
}

// Define an advice function to intercept input and output
$adviceCallback = function ($joinPoint)
{
    // Intercept input
    $methodName = $joinPoint->getMethodName();
    echo "[Intercepted] Method Name: $methodName\n";

    // Log input parameters (currently unavailable in our basic implementation but could be extended)
    echo "[Intercepted] No access to input parameters yet.\n";

    // Proceed with the original method (dummy step for now)
    echo "[Intercepted] No dynamic control of execution yet.\n";

    // Intercept output (placeholder for real functionality)
    echo "[Intercepted] Output interception not implemented yet.\n";
};

// Create the necessary objects
$pointcut = new PointCut();
$pointcut->setExpression("execution(*->myMethod)");

$advice = new Advice($adviceCallback);

$aspect = new Aspect();
$aspect->addAdvice($pointcut, $advice);

// Execute a test
$myObject = new MyClass();
$output = $myObject->myMethod("World");
echo "Original Output: $output\n";
