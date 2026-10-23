/*
 * aop extension: differences between the supported PHP versions (8.3, 8.4, 8.5).
 *
 * Checked against the php-8.3.6, php-8.4.27 and php-8.5.12 sources (Zend/zend_vm_def.h, zend_execute.[ch],
 * zend_execute_API.c, zend_compile.[ch], zend_observer.h, zend_API.h, zend_object_handlers.c,
 * Zend/Optimizer/optimize_func_calls.c, ext/opcache/jit/zend_jit.c). Everything the engine depends on and
 * that did NOT change is listed here too, so the next version bump knows what to re-check.
 *
 * Unchanged in 8.4 and 8.5 (no guard needed):
 *   - struct _zend_execute_data, every ZEND_CALL_* flag and ZEND_CALL_ARG/ZEND_CALL_VAR_NUM/ZEND_CALL_NUM_ARGS
 *   - i_init_func_execute_data() and zend_copy_extra_args(): declared args in the first CV slots, extra
 *     positional args behind the CVs and TMPs
 *   - the ZEND_CALL_TOP function branch of zend_leave_helper (8.5 only adds a TAILCALL-VM-only opline note),
 *     so aop_leave_user_frame() stays the same
 *   - ZEND_DO_FCALL / zend_call_function(): ZEND_OBSERVER_FCALL_BEGIN before zend_execute_ex(), then the
 *     caller releases $this and frees the frame (8.4 moves the deprecation check before the user/internal
 *     split, 8.5 adds #[\NoDiscard]; both run before our hook)
 *   - zend_execute_ex / zend_execute_internal signatures, zend_fcall_info(_cache), zend_call_known_fcc(),
 *     zend_call_known_function(), zend_fcc_dup()/zend_fcc_dtor()
 *   - the ZEND_ACC_* function flags we test (RETURN_REFERENCE, CALL_VIA_TRAMPOLINE, CLOSURE, FAKE_CLOSURE,
 *     STATIC, HAS_TYPE_HINTS) and the class flags we set (NO_DYNAMIC_PROPERTIES, NOT_SERIALIZABLE). 8.4
 *     renumbers PROMOTED and adds the asymmetric visibility/VIRTUAL property flags, 8.5 adds NODISCARD and
 *     moves USE_GUARDS; none of them is used here.
 *
 * Changed:
 *   - 8.4: zend_observer_fcall_end() became a static inline wrapper that checks EG(current_observed_frame)
 *     (8.3: exported function). Same call, same meaning.
 *   - 8.4: frameless internal calls (ZEND_FRAMELESS_ICALL_0..3) skip zend_execute_internal. The compiler only
 *     emits them while zend_execute_internal is NULL (find_frameless_function_info() in zend_compile.c, 8.4 and
 *     8.5), and our hook is installed in MINIT, before any script is compiled. See AOP_HAVE_FRAMELESS_CALLS.
 *   - 8.4: property hooks are user functions with common.prop_info set (named "$prop::get"/"$prop::set").
 *     zend_std_read_property() only caches the "simple get" fast path, which enters the hook without
 *     zend_execute_ex(), while zend_execute_ex == execute_ex, so with our hook installed every hook call goes
 *     through zend_call_function() and reaches aop_execute_ex(). See aop_is_property_hook().
 *   - 8.4: lazy objects. Calling a method on an uninitialized lazy ghost/proxy does not initialize it; only
 *     property access does. The engine never reads properties of $this, so advice does not initialize lazy
 *     objects by itself (ext/tests/205-lazy-objects.phpt).
 *   - 8.4: closure names in traces are "{closure:file:line}" (8.3: "{closure}").
 *   - 8.4: field join points (aop_fields.c): property hooks, asymmetric visibility (public readonly is implicitly
 *     protected(set)), lazy objects in the std property handlers and in zend_objects_clone_obj(); see the comment
 *     at the top of aop_fields.c. AOP_PROP_IS_HOOKED().
 *   - 8.5: EG(fake_scope) is const (AOP_FAKE_SCOPE_T); clone($o, [...]) writes through write_property.
 *   - 8.4: exit()/die() is an internal function; it can be advised like any internal function.
 *   - 8.4: user functions can be #[\Deprecated] (ZEND_ACC_DEPRECATED); zend_call_function() reports the
 *     deprecation again, so every OriginalCall of a deprecated function repeats it (also true for deprecated
 *     internal functions on 8.3).
 */
#ifndef AOP_COMPAT_H
#define AOP_COMPAT_H

#include "php_version.h"

#if PHP_VERSION_ID < 80300
# error "the aop extension requires PHP 8.3 or newer"
#endif

#define AOP_HAVE_PROPERTY_HOOKS   (PHP_VERSION_ID >= 80400)
#define AOP_HAVE_LAZY_OBJECTS     (PHP_VERSION_ID >= 80400)
#define AOP_HAVE_FRAMELESS_CALLS  (PHP_VERSION_ID >= 80400)

/* The type of EG(fake_scope): const since 8.5. */
#if PHP_VERSION_ID >= 80500
# define AOP_FAKE_SCOPE_T const zend_class_entry
#else
# define AOP_FAKE_SCOPE_T zend_class_entry
#endif

/* A declared property with hooks (8.4+); info may be NULL. */
#if AOP_HAVE_PROPERTY_HOOKS
# define AOP_PROP_IS_HOOKED(info) ((info) != NULL && (info)->hooks != NULL)
#else
# define AOP_PROP_IS_HOOKED(info) ((void) (info), false)
#endif

/* A property hook body ("$prop::get"/"$prop::set", 8.4+). Never a method join point: it is no method of the
 * class (ReflectionMethod cannot load it by name), and its accesses are field join points (stream G). */
static zend_always_inline bool aop_is_property_hook(const zend_function *func)
{
#if AOP_HAVE_PROPERTY_HOOKS
	return func->common.prop_info != NULL;
#else
	(void) func;
	return false;
#endif
}

/*
 * opcache inlining (optimizer pass 16, zend_try_inline_call() in Zend/Optimizer/optimize_func_calls.c,
 * same in 8.3, 8.4 and 8.5) replaces a call to a same-file function whose body is `return <const>;` by the
 * constant. It refuses callees with ZEND_ACC_HAS_TYPE_HINTS (also ABSTRACT, TRAIT_CLONE, 8.4+ DEPRECATED,
 * 8.5+ NODISCARD with unused result). Setting HAS_TYPE_HINTS on an untyped function only makes the VM run
 * its RECV opcodes (zend_verify_recv_arg_type() accepts untyped parameters), exactly like a function that
 * has one typed and one untyped parameter, so it is the flag aop.disable_inlining uses.
 */
#define AOP_ACC_NO_INLINE ZEND_ACC_HAS_TYPE_HINTS

#endif /* AOP_COMPAT_H */
