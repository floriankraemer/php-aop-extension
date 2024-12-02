## JoinPoint

A JoinPoint is a specific point in the execution flow of a program where additional behavior (advice) can be applied. It represents an "opportunity" for cross-cutting logic to be inserted.

Examples of JoinPoints:

* Method execution (before, after, or around a method call)
* Constructor execution
* Field access (getter or setter)
* Exception handling
* In PHP AOP, a JoinPoint could represent:

A method being invoked.

* The arguments passed to the method.
* The object on which the method is invoked.

## Advice

An Advice is the action to be executed when a specific JoinPoint is reached. It encapsulates the behavior or logic that should be applied at the JoinPoint.

Types of Advice:

* Before Advice: Runs before the JoinPoint (e.g., logging a message before a method call).
* After Advice: Runs after the JoinPoint (e.g., cleanup after a method finishes).
* Around Advice: Wraps around the JoinPoint, allowing it to modify both before and after behavior (e.g., timing the execution of a method).
* After Returning Advice: Runs only if the JoinPoint completes successfully.
* After Throwing Advice: Runs if the JoinPoint throws an exception.

In PHP, advice could be implemented as PHP methods or closures triggered based on a defined PointCut.

## PointCut

A PointCut is a predicate or expression that specifies which JoinPoints should be intercepted by advice. It defines the "where and when" part of AOP by selecting a set of JoinPoints.

Examples of PointCuts:

* All method executions in a specific class.
* Methods with a specific name or matching a pattern (e.g., get*).
* Specific annotations or attributes (e.g., #[Log]).
* In PHP AOP, a PointCut might be defined using:

Method patterns like ClassName::methodName.
Attributes or metadata, such as #[Before] or #[After].
