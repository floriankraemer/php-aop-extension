# php.ini settings

Four INI settings control the extension:

| Setting | Default | Changeable | Meaning |
|---|---|---|---|
| `aop.enable` | `1` | php.ini or `-d` only | `0` turns interception off. Nothing is installed (no backend, no hooks), the JIT runs, and `Weaver::enable()` throws a `RuntimeException`. |
| `aop.interception` | `woven` | php.ini or `-d` only | The interception backend. `woven` works with the opcache JIT but has no field or `initialization()` join points. `hook` has all join points and turns the JIT off. `auto` is `hook` unless the JIT is configured, then `woven`. An invalid value raises a warning and keeps `woven`. See [limitations](limitations.md#backends). |
| `aop.disable_inlining` | `1` | php.ini or `-d` only | `1` keeps opcache from inlining calls to user functions and methods, so their advice runs. `0` lets opcache inline a function that only returns a constant, and then its advice may not run. |
| `aop.weave_autoload` | `0` | any time, also with `ini_set()` | `1` weaves class loading too. By default the autoloader and the code it runs are not woven. |

```ini
; php.ini
extension=/path/to/php-aop-extension/ext/modules/aop.so
aop.enable=1
aop.interception=woven
aop.disable_inlining=1
aop.weave_autoload=0
```

The [building page](building.md#configuration-phpini) describes the same settings for developers.
