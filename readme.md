# Aspect Oriented Programming Extension for PHP

**THIS IS NOT PRODUCTION READY!**

> "In computing, aspect-oriented programming (AOP) is a programming paradigm that aims to increase modularity by allowing the separation of cross-cutting concerns. It does so by adding behavior to existing code (an advice) without modifying the code, instead separately specifying which code is modified via a "pointcut" specification, such as "log all function calls when the function's name begins with 'set'". This allows behaviors that are not central to the business logic (such as logging) to be added to a program without cluttering the code of core functions."

See [Wikipedia](https://en.wikipedia.org/wiki/Aspect-oriented_programming) for a more detailed explanation of Aspect Oriented Programming.

## Motivation

There are only three AOP libraries for PHP, but none of them is really good. Two of them are no longer maintained and the remaining one relies on a custom container solution and is a little hard to use. There are probably also performance issues with it.

**Disclaimer:** I've never written a PHP extension before, nor am I familiar with C. So this is a learning project for me. Therefore take it with a big grain of salt!

## End User Documentation

TBD

## Developer Documentation

To initialize an extension run

```
phpize
./configure --enable-aop
make
make install
```

## License & Copyright

Copyright (c) Florian Krämer