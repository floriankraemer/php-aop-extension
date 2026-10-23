/*
 * aop extension, stream G: introductions (Engine::setIntroductions()), woven at compile time through a
 * zend_ast_process hook.
 *
 * setIntroductions() (run time) validates the rules, loads each interface and renders, per interface
 * method, the PHP source of a delegating method with the interface's signature: parameter types and
 * return type fully qualified (`self` replaced by the interface), defaults exported from the RECV_INIT
 * literals (constant expressions re-exported with fully qualified names), by-reference and variadic
 * parameters kept.
 *
 * zend_ast_process (after parsing, before compiling every file and eval'd string) walks the AST and,
 * for each class declaration whose fully qualified name matches a rule's type pattern:
 *   - appends `\Interface` to the implements list (unless the class lists it already),
 *   - parses `class __aop_introduction__ { <methods> }` with zend_compile_string_to_ast() and grafts
 *     the methods the class body does not declare into the class's statement list.
 * The extra arena of that parse is chained into the file's AST arena, so it is freed with it.
 *
 * A synthesized method delegates to AOP\Internal\introductionDelegate($this, 'Impl')->m(...), a lazily
 * created instance per object held in a weak map; static ones call \Impl::m(...). When the class has a
 * parent, the method first asks AOP\Internal\introductionInherited(__CLASS__, 'm') and, if the parent
 * has a concrete m(), calls parent::m(...) instead: the parent is not known at compile time.
 *
 * Nothing is looked up during compilation (with opcache, EG(class_table) is the script's own table
 * while a file compiles, and autoloading there would be unsafe), so the result is the same with and
 * without opcache; opcache caches the woven file.
 *
 * Verified against PHP-8.3.6: zend_compile() / zend_compile_string_to_ast() and the lexical state
 * (Zend/zend_language_scanner.l), class/method declaration ASTs (Zend/zend_language_parser.y,
 * Zend/zend_ast.h), zend_ast_export() (Zend/zend_ast.c), zend_weakrefs_hash_add/del (Zend/zend_weakrefs.c).
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_ast.h"
#include "zend_arena.h"
#include "zend_compile.h"
#include "zend_constants.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "zend_smart_str.h"
#include "zend_weakrefs.h"
#include "ext/standard/php_var.h"
#include "php_aop.h"
#include "aop_g.h"

#if PHP_VERSION_ID >= 80400
/* 8.4: ZEND_AST_PARAM has a sixth child (property hooks) and class declarations may carry property
 * hooks; neither matters here (the walk is generic, generated parameters have no hooks). */
#endif

typedef enum {
	AOP_RET_VALUE,
	AOP_RET_VOID,
	AOP_RET_NEVER
} aop_ret_kind;

typedef struct {
	zend_string *name;    /* as declared */
	zend_string *lcname;
	zend_string *head;    /* "public [static] function [&]name(params)[: type]" */
	zend_string *args;    /* "$a, $b, ...$c" */
	aop_ret_kind ret;
	bool is_static;
} aop_intro_method;

typedef struct {
	zend_string *pattern;    /* without leading backslash and trailing '+' */
	zend_class_entry *iface;
	zend_string *impl;       /* fully qualified, without leading backslash */
	aop_intro_method *methods;
	uint32_t num_methods;
} aop_intro_rule;

static zend_always_inline bool aop_ast_is_decl(zend_ast_kind kind)
{
	switch (kind) {
		case ZEND_AST_FUNC_DECL:
		case ZEND_AST_CLOSURE:
		case ZEND_AST_METHOD:
		case ZEND_AST_CLASS:
		case ZEND_AST_ARROW_FUNC:
#if PHP_VERSION_ID >= 80400
		case ZEND_AST_PROPERTY_HOOK:
#endif
			return true;
		default:
			return false;
	}
}

/* Special nodes other than declarations (zval, constant, znode; 8.5: op_array) have no AST children. */
static zend_always_inline bool aop_ast_is_leaf(zend_ast *ast)
{
	return zend_ast_is_special(ast) && !aop_ast_is_decl(ast->kind);
}

static void (*aop_prev_ast_process)(zend_ast *ast) = NULL;
static bool aop_ast_hooked = false;

/* {{{ rules */
static void aop_rules_free(aop_intro_rule *rules, uint32_t count)
{
	uint32_t i, j;

	for (i = 0; i < count; i++) {
		aop_intro_rule *r = &rules[i];
		if (r->pattern) {
			zend_string_release(r->pattern);
		}
		if (r->impl) {
			zend_string_release(r->impl);
		}
		for (j = 0; j < r->num_methods; j++) {
			aop_intro_method *m = &r->methods[j];
			zend_string_release(m->name);
			zend_string_release(m->lcname);
			zend_string_release(m->head);
			zend_string_release(m->args);
		}
		if (r->methods) {
			efree(r->methods);
		}
	}
	if (rules) {
		efree(rules);
	}
}

static bool aop_is_builtin_type(const char *s, size_t len)
{
	static const char *const names[] = {
		"array", "bool", "callable", "float", "int", "iterable", "mixed", "never", "null",
		"object", "static", "string", "void", "false", "true", NULL
	};
	const char *const *n;

	for (n = names; *n; n++) {
		if (strlen(*n) == len && strncasecmp(*n, s, len) == 0) {
			return true;
		}
	}
	return false;
}

/* Appends type with every class name fully qualified and `self` replaced by the interface. */
static void aop_append_type(smart_str *buf, zend_type type, zend_class_entry *iface)
{
	zend_string *str = zend_type_to_string(type);
	const char *p = ZSTR_VAL(str), *end = p + ZSTR_LEN(str);

	while (p < end) {
		const char *start;
		size_t len;

		if (*p == '?' || *p == '|' || *p == '&' || *p == '(' || *p == ')' || *p == ' ') {
			smart_str_appendc(buf, *p++);
			continue;
		}
		start = p;
		while (p < end && *p != '?' && *p != '|' && *p != '&' && *p != '(' && *p != ')' && *p != ' ') {
			p++;
		}
		len = p - start;
		if (*start == '\\') {
			start++;
			len--;
		}
		if (aop_is_builtin_type(start, len)) {
			smart_str_appendl(buf, start, len);
		} else if (len == 4 && strncasecmp(start, "self", 4) == 0) {
			smart_str_appendc(buf, '\\');
			smart_str_append(buf, iface->name);
		} else {
			smart_str_appendc(buf, '\\');
			smart_str_appendl(buf, start, len);
		}
	}
	zend_string_release(str);
}

/* Copies a compiled constant expression into the current CG(ast_arena) as an AST zend_ast_export()
 * prints with fully qualified names. *ok turns false on nodes that cannot be copied. */
static zend_ast *aop_const_ast_copy(zend_ast *ast, zend_class_entry *iface, bool class_pos, bool *ok)
{
	zend_ast *n;
	uint32_t i, count;

	if (!ast || !*ok) {
		return NULL;
	}
	switch (ast->kind) {
		case ZEND_AST_ZVAL: {
			zval *zv = zend_ast_get_zval(ast), copy;
			if (class_pos && Z_TYPE_P(zv) == IS_STRING
					&& (zend_string_equals_literal_ci(Z_STR_P(zv), "self")
						|| zend_string_equals_literal_ci(Z_STR_P(zv), "static"))) {
				n = zend_ast_create_zval_from_str(zend_string_copy(iface->name));
				n->attr = ZEND_NAME_FQ;
				return n;
			}
			ZVAL_COPY(&copy, zv);
			n = zend_ast_create_zval(&copy);
			n->attr = ast->attr;
			if (class_pos && Z_TYPE_P(zv) == IS_STRING && ast->attr == ZEND_NAME_NOT_FQ) {
				n->attr = ZEND_NAME_FQ;
			}
			return n;
		}
		case ZEND_AST_CONSTANT: {
			zend_string *name = zend_ast_get_constant_name(ast);
			zend_string *use = zend_string_copy(name);
			if (ast->attr & IS_CONSTANT_UNQUALIFIED_IN_NAMESPACE) {
				const char *sep = zend_memrchr(ZSTR_VAL(name), '\\', ZSTR_LEN(name));
				if (sep && !zend_get_constant_ex(name, NULL, ZEND_FETCH_CLASS_SILENT)) {
					zend_string_release(use);
					use = zend_string_init(sep + 1, ZSTR_VAL(name) + ZSTR_LEN(name) - sep - 1, 0);
				}
			}
			n = zend_ast_create_zval_from_str(use);
			n->attr = ZEND_NAME_FQ;
			return zend_ast_create_1(ZEND_AST_CONST, n);
		}
		case ZEND_AST_CONSTANT_CLASS:
			n = zend_ast_create_zval_from_str(zend_string_copy(iface->name));
			return n;
		case ZEND_AST_CLASS_CONST:
		case ZEND_AST_CLASS_NAME:
		case ZEND_AST_NEW:
		case ZEND_AST_STATIC_PROP:
			break;
		default:
			if (zend_ast_is_list(ast)) {
				zend_ast_list *list = zend_ast_get_list(ast);
				n = zend_ast_create_list(0, ast->kind);
				n->attr = ast->attr;
				for (i = 0; i < list->children; i++) {
					n = zend_ast_list_add(n, aop_const_ast_copy(list->child[i], iface, false, ok));
				}
				return n;
			}
			if (zend_ast_is_special(ast)) {
				*ok = false; /* declarations (closures in constant expressions, PHP 8.5), znodes */
				return NULL;
			}
			break;
	}

	count = zend_ast_get_num_children(ast);
	{
		zend_ast *c[5] = {NULL, NULL, NULL, NULL, NULL};
		bool first_is_class = ast->kind == ZEND_AST_CLASS_CONST || ast->kind == ZEND_AST_CLASS_NAME
			|| ast->kind == ZEND_AST_NEW || ast->kind == ZEND_AST_STATIC_PROP;
		if (count > 5) {
			*ok = false;
			return NULL;
		}
		for (i = 0; i < count; i++) {
			c[i] = aop_const_ast_copy(ast->child[i], iface, first_is_class && i == 0, ok);
		}
		switch (count) {
			case 0: n = zend_ast_create_0(ast->kind); break;
			case 1: n = zend_ast_create_1(ast->kind, c[0]); break;
			case 2: n = zend_ast_create_2(ast->kind, c[0], c[1]); break;
			case 3: n = zend_ast_create_3(ast->kind, c[0], c[1], c[2]); break;
			case 4: n = zend_ast_create_4(ast->kind, c[0], c[1], c[2], c[3]); break;
			default: n = zend_ast_create_5(ast->kind, c[0], c[1], c[2], c[3], c[4]); break;
		}
		n->attr = ast->attr;
		return n;
	}
}

static void aop_append_const_ast(smart_str *buf, zend_ast *ast, zend_class_entry *iface)
{
	zend_arena *saved = CG(ast_arena);
	zend_ast *copy;
	bool ok = true;

	CG(ast_arena) = zend_arena_create(4096);
	copy = aop_const_ast_copy(ast, iface, false, &ok);
	if (ok && copy) {
		zend_string *s = zend_ast_export("", copy, "");
		smart_str_append(buf, s);
		zend_string_release(s);
	} else {
		smart_str_appends(buf, "null");
	}
	zend_ast_destroy(copy);
	zend_arena_destroy(CG(ast_arena));
	CG(ast_arena) = saved;
}

static void aop_append_default(smart_str *buf, zend_function *fn, uint32_t i, zend_class_entry *iface)
{
	if (fn->type == ZEND_INTERNAL_FUNCTION) {
		const zend_internal_arg_info *ai = &fn->internal_function.arg_info[i];
		smart_str_appends(buf, ai->default_value ? ai->default_value : "null");
		return;
	} else {
		zend_op_array *op_array = &fn->op_array;
		zend_op *op = op_array->opcodes, *end = op + op_array->last;

		for (; op < end; op++) {
			if (op->opcode == ZEND_RECV_INIT && op->op1.num == i + 1) {
				zval *zv = RT_CONSTANT(op, op->op2);
				if (Z_TYPE_P(zv) == IS_CONSTANT_AST) {
					aop_append_const_ast(buf, Z_ASTVAL_P(zv), iface);
				} else {
					php_var_export_ex(zv, 1, buf);
				}
				return;
			}
		}
		smart_str_appends(buf, "null");
	}
}

static const char *aop_arg_name(zend_function *fn, uint32_t i)
{
	if (fn->type == ZEND_INTERNAL_FUNCTION) {
		return fn->internal_function.arg_info[i].name;
	}
	return ZSTR_VAL(fn->op_array.arg_info[i].name);
}

static void aop_render_method(aop_intro_method *m, zend_function *fn, zend_class_entry *iface)
{
	smart_str head = {0}, args = {0};
	uint32_t i, n = fn->common.num_args;
	bool variadic = (fn->common.fn_flags & ZEND_ACC_VARIADIC) != 0;

	m->name = zend_string_copy(fn->common.function_name);
	m->lcname = zend_string_tolower(fn->common.function_name);
	m->is_static = (fn->common.fn_flags & ZEND_ACC_STATIC) != 0;
	m->ret = AOP_RET_VALUE;

	smart_str_appends(&head, m->is_static ? "public static function " : "public function ");
	if (fn->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) {
		smart_str_appendc(&head, '&');
	}
	smart_str_append(&head, fn->common.function_name);
	smart_str_appendc(&head, '(');
	for (i = 0; i < n + (variadic ? 1 : 0); i++) {
		zend_arg_info *ai = &fn->common.arg_info[i];
		const char *name = aop_arg_name(fn, i);
		bool is_variadic = i == n;

		if (i) {
			smart_str_appends(&head, ", ");
			smart_str_appends(&args, ", ");
		}
		if (ZEND_TYPE_IS_SET(ai->type)) {
			aop_append_type(&head, ai->type, iface);
			smart_str_appendc(&head, ' ');
		}
		if (ZEND_ARG_SEND_MODE(ai)) {
			smart_str_appendc(&head, '&');
		}
		if (is_variadic) {
			smart_str_appends(&head, "...");
			smart_str_appends(&args, "...");
		}
		smart_str_appendc(&head, '$');
		smart_str_appends(&head, name);
		smart_str_appendc(&args, '$');
		smart_str_appends(&args, name);
		if (!is_variadic && i >= fn->common.required_num_args) {
			smart_str_appends(&head, " = ");
			aop_append_default(&head, fn, i, iface);
		}
	}
	smart_str_appendc(&head, ')');
	if (fn->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
		zend_type rt = fn->common.arg_info[-1].type;
		uint32_t mask = ZEND_TYPE_PURE_MASK(rt);
		if (!ZEND_TYPE_IS_COMPLEX(rt) && mask == MAY_BE_VOID) {
			m->ret = AOP_RET_VOID;
		} else if (!ZEND_TYPE_IS_COMPLEX(rt) && mask == MAY_BE_NEVER) {
			m->ret = AOP_RET_NEVER;
		}
		smart_str_appends(&head, ": ");
		aop_append_type(&head, rt, iface);
	}
	smart_str_0(&head);
	m->head = smart_str_extract(&head);
	smart_str_0(&args);
	m->args = args.s ? smart_str_extract(&args) : ZSTR_EMPTY_ALLOC();
}

static zend_string *aop_rule_string(zval *rule, const char *key, uint32_t idx)
{
	zval *v = Z_TYPE_P(rule) == IS_ARRAY ? zend_hash_str_find(Z_ARRVAL_P(rule), key, strlen(key)) : NULL;

	if (!v) {
		zend_argument_value_error(1, "must be a list of arrays with the keys \"typePattern\", \"interface\" "
			"and \"defaultImpl\" (rule %u has no \"%s\")", idx, key);
		return NULL;
	}
	ZVAL_DEREF(v);
	if (Z_TYPE_P(v) != IS_STRING || Z_STRLEN_P(v) == 0) {
		zend_argument_value_error(1, "rule %u: \"%s\" must be a non-empty string", idx, key);
		return NULL;
	}
	return Z_STR_P(v);
}

static zend_string *aop_strip_name(zend_string *s, bool strip_plus)
{
	const char *p = ZSTR_VAL(s);
	size_t len = ZSTR_LEN(s);

	while (len && *p == '\\') {
		p++;
		len--;
	}
	if (strip_plus && len && p[len - 1] == '+') {
		len--;
	}
	return zend_string_init(p, len, 0);
}

void aop_ast_set_rules(HashTable *rules)
{
	aop_intro_rule *compiled = NULL;
	uint32_t count = zend_hash_num_elements(rules), n = 0, idx = 0;
	zval *rule;

	if (count) {
		compiled = ecalloc(count, sizeof(aop_intro_rule));
	}
	ZEND_HASH_FOREACH_VAL(rules, rule) {
		zend_string *pattern, *iface_name, *impl;
		zend_class_entry *iface;
		aop_intro_rule *r;
		zend_function *fn;
		uint32_t m = 0;

		ZVAL_DEREF(rule);
		if (!(pattern = aop_rule_string(rule, "typePattern", idx))
				|| !(iface_name = aop_rule_string(rule, "interface", idx))
				|| !(impl = aop_rule_string(rule, "defaultImpl", idx))) {
			goto fail;
		}
		iface = zend_lookup_class(iface_name);
		if (EG(exception)) {
			goto fail;
		}
		if (!iface || !(iface->ce_flags & ZEND_ACC_INTERFACE)) {
			zend_argument_value_error(1, "rule %u: \"%s\" is not an interface", idx, ZSTR_VAL(iface_name));
			goto fail;
		}

		r = &compiled[n++];
		r->pattern = aop_strip_name(pattern, true);
		r->impl = aop_strip_name(impl, false);
		r->iface = iface;
		r->methods = zend_hash_num_elements(&iface->function_table)
			? ecalloc(zend_hash_num_elements(&iface->function_table), sizeof(aop_intro_method)) : NULL;
		ZEND_HASH_MAP_FOREACH_PTR(&iface->function_table, fn) {
			if (zend_string_equals_literal_ci(fn->common.function_name, "__construct")) {
				continue;
			}
			aop_render_method(&r->methods[m++], fn, iface);
			r->num_methods = m;
		} ZEND_HASH_FOREACH_END();
		idx++;
	} ZEND_HASH_FOREACH_END();

	aop_rules_free(AOP_G(intro_rules), AOP_G(intro_rule_count));
	AOP_G(intro_rules) = compiled;
	AOP_G(intro_rule_count) = n;
	return;

fail:
	aop_rules_free(compiled, n);
}
/* }}} */

/* {{{ type patterns (same syntax as AOP\Pointcut\Pattern\TypePattern, without '+') */
static bool aop_segment_match(const char *p, size_t plen, const char *s, size_t slen)
{
	/* '*' matches any run of characters within the segment. */
	while (plen) {
		if (*p == '*') {
			size_t k;
			while (plen && *p == '*') {
				p++;
				plen--;
			}
			if (!plen) {
				return true;
			}
			for (k = 0; k <= slen; k++) {
				if (aop_segment_match(p, plen, s + k, slen - k)) {
					return true;
				}
			}
			return false;
		}
		if (!slen || *p != *s) {
			return false;
		}
		p++;
		s++;
		plen--;
		slen--;
	}
	return slen == 0;
}

typedef struct {
	const char *s;
	size_t len;
} aop_seg;

static uint32_t aop_split(const char *s, size_t len, aop_seg *out, uint32_t max)
{
	uint32_t n = 0;
	const char *start = s, *end = s + len;

	while (n < max) {
		const char *sep = memchr(start, '\\', end - start);
		const char *stop = sep ? sep : end;
		out[n].s = start;
		out[n].len = stop - start;
		n++;
		if (!sep) {
			break;
		}
		start = sep + 1;
	}
	return n;
}

static bool aop_glob(const aop_seg *pat, uint32_t np, const aop_seg *name, uint32_t nn)
{
	if (np == 0) {
		return nn == 0;
	}
	if (pat[0].len == 2 && pat[0].s[0] == '*' && pat[0].s[1] == '*') {
		uint32_t k;
		if (np == 1) {
			return nn > 0;
		}
		for (k = 0; k <= nn; k++) {
			if (aop_glob(pat + 1, np - 1, name + k, nn - k)) {
				return true;
			}
		}
		return false;
	}
	if (nn == 0) {
		return false;
	}
	if (pat[0].len == 1 && pat[0].s[0] == '*') {
		if (name[0].len == 0) {
			return false;
		}
	} else if (!aop_segment_match(pat[0].s, pat[0].len, name[0].s, name[0].len)) {
		return false;
	}
	return aop_glob(pat + 1, np - 1, name + 1, nn - 1);
}

#define AOP_MAX_SEGMENTS 64

static bool aop_type_pattern_matches(zend_string *pattern, zend_string *name)
{
	aop_seg pat[AOP_MAX_SEGMENTS], nm[AOP_MAX_SEGMENTS];
	uint32_t np = aop_split(ZSTR_VAL(pattern), ZSTR_LEN(pattern), pat, AOP_MAX_SEGMENTS);
	uint32_t nn = aop_split(ZSTR_VAL(name), ZSTR_LEN(name), nm, AOP_MAX_SEGMENTS);

	return aop_glob(pat, np, nm, nn);
}
/* }}} */

/* {{{ AST weaving */
typedef struct {
	zend_string *ns;     /* current namespace, NULL for the global one */
	HashTable imports;   /* lower-case alias -> imported class name */
} aop_ast_ctx;



static void aop_ast_set_lineno(zend_ast *ast, uint32_t line)
{
	uint32_t i, n;

	if (!ast) {
		return;
	}
	if (ast->kind == ZEND_AST_ZVAL || ast->kind == ZEND_AST_CONSTANT) {
		Z_LINENO(((zend_ast_zval *) ast)->val) = line;
		return;
	}
	if (aop_ast_is_leaf(ast)) {
		return;
	}
	if (aop_ast_is_decl(ast->kind)) {
		zend_ast_decl *decl = (zend_ast_decl *) ast;
		decl->start_lineno = line;
		decl->end_lineno = line;
		for (i = 0; i < 5; i++) {
			aop_ast_set_lineno(decl->child[i], line);
		}
		return;
	}
	ast->lineno = line;
	if (zend_ast_is_list(ast)) {
		zend_ast_list *list = zend_ast_get_list(ast);
		for (i = 0; i < list->children; i++) {
			aop_ast_set_lineno(list->child[i], line);
		}
		return;
	}
	n = zend_ast_get_num_children(ast);
	for (i = 0; i < n; i++) {
		aop_ast_set_lineno(ast->child[i], line);
	}
}

static zend_string *aop_ns_join(zend_string *ns, const char *name, size_t len)
{
	if (!ns) {
		return zend_string_init(name, len, 0);
	}
	return zend_string_concat3(ZSTR_VAL(ns), ZSTR_LEN(ns), "\\", 1, name, len);
}

/* Resolves a class name of the implements list like the compiler would (imports, namespace). */
static zend_string *aop_resolve_class_name(zend_ast *name_ast, aop_ast_ctx *ctx)
{
	zend_string *name;
	const char *sep;
	zend_string *lc_first, *imported;

	if (name_ast->kind != ZEND_AST_ZVAL || Z_TYPE_P(zend_ast_get_zval(name_ast)) != IS_STRING) {
		return NULL;
	}
	name = zend_ast_get_str(name_ast);
	if (name_ast->attr == ZEND_NAME_FQ) {
		return zend_string_copy(name);
	}
	if (name_ast->attr == ZEND_NAME_RELATIVE) {
		return aop_ns_join(ctx->ns, ZSTR_VAL(name), ZSTR_LEN(name));
	}
	sep = memchr(ZSTR_VAL(name), '\\', ZSTR_LEN(name));
	lc_first = zend_string_init(ZSTR_VAL(name), sep ? (size_t) (sep - ZSTR_VAL(name)) : ZSTR_LEN(name), 0);
	zend_str_tolower(ZSTR_VAL(lc_first), ZSTR_LEN(lc_first));
	imported = zend_hash_find_ptr(&ctx->imports, lc_first);
	zend_string_release(lc_first);
	if (imported) {
		if (!sep) {
			return zend_string_copy(imported);
		}
		return zend_string_concat2(ZSTR_VAL(imported), ZSTR_LEN(imported), sep, ZSTR_VAL(name) + ZSTR_LEN(name) - sep);
	}
	return aop_ns_join(ctx->ns, ZSTR_VAL(name), ZSTR_LEN(name));
}

static void aop_add_import(aop_ast_ctx *ctx, zend_string *prefix, zend_ast *elem)
{
	zend_string *name = zend_ast_get_str(elem->child[0]);
	zend_string *full = prefix
		? zend_string_concat3(ZSTR_VAL(prefix), ZSTR_LEN(prefix), "\\", 1, ZSTR_VAL(name), ZSTR_LEN(name))
		: zend_string_copy(name);
	zend_string *alias;

	if (elem->child[1]) {
		alias = zend_string_tolower(zend_ast_get_str(elem->child[1]));
	} else {
		const char *sep = zend_memrchr(ZSTR_VAL(full), '\\', ZSTR_LEN(full));
		zend_string *tmp = sep ? zend_string_init(sep + 1, ZSTR_VAL(full) + ZSTR_LEN(full) - sep - 1, 0) : zend_string_copy(full);
		alias = zend_string_tolower(tmp);
		zend_string_release(tmp);
	}
	zend_hash_update_ptr(&ctx->imports, alias, full);
	zend_string_release(alias);
}

static void aop_collect_use(aop_ast_ctx *ctx, zend_ast *ast)
{
	uint32_t i;

	if (ast->kind == ZEND_AST_USE) {
		zend_ast_list *list = zend_ast_get_list(ast);
		if (ast->attr != ZEND_SYMBOL_CLASS) {
			return;
		}
		for (i = 0; i < list->children; i++) {
			aop_add_import(ctx, NULL, list->child[i]);
		}
	} else if (ast->kind == ZEND_AST_GROUP_USE) {
		zend_string *prefix = zend_ast_get_str(ast->child[0]);
		zend_ast_list *list = zend_ast_get_list(ast->child[1]);
		for (i = 0; i < list->children; i++) {
			zend_ast *elem = list->child[i];
			uint32_t type = ast->attr ? ast->attr : elem->attr;
			if (type == ZEND_SYMBOL_CLASS) {
				aop_add_import(ctx, prefix, elem);
			}
		}
	}
}

static void aop_imports_dtor(zval *zv)
{
	zend_string_release((zend_string *) Z_PTR_P(zv));
}

static void aop_ctx_reset(aop_ast_ctx *ctx, zend_ast *name_ast)
{
	if (ctx->ns) {
		zend_string_release(ctx->ns);
	}
	ctx->ns = name_ast ? zend_string_copy(zend_ast_get_str(name_ast)) : NULL;
	zend_hash_clean(&ctx->imports);
}

static void aop_append_body_call(smart_str *src, aop_intro_method *m, const char *target, zend_string *impl)
{
	if (m->ret == AOP_RET_VALUE) {
		smart_str_appends(src, "return ");
	}
	if (target) {
		smart_str_appends(src, target);
	} else if (m->is_static) {
		smart_str_appendc(src, '\\');
		smart_str_append(src, impl);
		smart_str_appends(src, "::");
	} else {
		smart_str_appends(src, "\\AOP\\Internal\\introductionDelegate($this, '");
		smart_str_append(src, impl);
		smart_str_appends(src, "')->");
	}
	smart_str_append(src, m->name);
	smart_str_appendc(src, '(');
	smart_str_append(src, m->args);
	smart_str_appends(src, m->ret == AOP_RET_VOID ? "); return;" : ");");
}

static void aop_render_delegate(smart_str *src, aop_intro_method *m, zend_string *impl, bool has_parent)
{
	smart_str_append(src, m->head);
	smart_str_appends(src, " {\n");
	if (has_parent) {
		smart_str_appends(src, "if (\\AOP\\Internal\\introductionInherited(__CLASS__, '");
		smart_str_append(src, m->name);
		smart_str_appends(src, "')) { ");
		aop_append_body_call(src, m, "parent::", impl);
		smart_str_appends(src, " }\n");
	}
	aop_append_body_call(src, m, NULL, impl);
	smart_str_appends(src, "\n}\n");
}

/* Parses the generated methods and appends them to the class's statement list. */
static void aop_graft_methods(zend_ast_decl *decl, smart_str *src)
{
	zend_string *code, *filename = zend_get_compiled_filename();
	zend_arena *arena = NULL, *tail;
	zend_ast *root;
	zend_ast_list *stmts, *methods;
	zend_ast_decl *generated;
	uint32_t i;

	smart_str_appends(src, "}\n");
	smart_str_0(src);
	code = smart_str_extract(src);
	root = zend_compile_string_to_ast(code, &arena, filename ? filename : ZSTR_EMPTY_ALLOC());
	zend_string_release(code);
	if (!root) {
		if (arena) {
			zend_arena_destroy(arena);
		}
		if (EG(exception)) {
			zend_clear_exception();
		}
		zend_error(E_COMPILE_WARNING, "AOP: could not generate the introduced methods of %s", ZSTR_VAL(decl->name));
		return;
	}

	/* The parse arena lives as long as the file's AST arena: chain it behind the current head. */
	for (tail = arena; tail->prev; tail = tail->prev);
	tail->prev = CG(ast_arena)->prev;
	CG(ast_arena)->prev = arena;

	stmts = zend_ast_get_list(root);
	generated = (zend_ast_decl *) stmts->child[0];
	methods = zend_ast_get_list(generated->child[2]);
	for (i = 0; i < methods->children; i++) {
		zend_ast *method = methods->child[i];
		aop_ast_set_lineno(method, decl->start_lineno);
		decl->child[2] = zend_ast_list_add(decl->child[2], method);
		methods->child[i] = NULL;
	}
	zend_ast_destroy(root);
}

static void aop_weave_class(zend_ast_decl *decl, aop_ast_ctx *ctx)
{
	aop_intro_rule *rules = AOP_G(intro_rules);
	uint32_t count = AOP_G(intro_rule_count), i, j;
	zend_string *fqcn;
	HashTable declared, implemented;
	smart_str src = {0};
	bool any_method = false, indexed = false;

	if (decl->flags & (ZEND_ACC_INTERFACE | ZEND_ACC_TRAIT | ZEND_ACC_ENUM | ZEND_ACC_ANON_CLASS) || !decl->name) {
		return;
	}
	fqcn = aop_ns_join(ctx->ns, ZSTR_VAL(decl->name), ZSTR_LEN(decl->name));

	zend_hash_init(&declared, 8, NULL, NULL, 0);
	zend_hash_init(&implemented, 4, NULL, NULL, 0);

	for (i = 0; i < count; i++) {
		aop_intro_rule *r = &rules[i];
		zend_string *lc_iface;

		if (!aop_type_pattern_matches(r->pattern, fqcn)) {
			continue;
		}

		if (!indexed) {
			/* first match: index the class's own methods and interfaces */
			zend_ast_list *body = zend_ast_get_list(decl->child[2]);
			zval tmp;
			ZVAL_TRUE(&tmp);
			for (j = 0; j < body->children; j++) {
				zend_ast *stmt = body->child[j];
				if (stmt && stmt->kind == ZEND_AST_METHOD) {
					zend_string *lc = zend_string_tolower(((zend_ast_decl *) stmt)->name);
					zend_hash_update(&declared, lc, &tmp);
					zend_string_release(lc);
				}
			}
			indexed = true;
			if (decl->child[1]) {
				zend_ast_list *names = zend_ast_get_list(decl->child[1]);
				for (j = 0; j < names->children; j++) {
					zend_string *resolved = aop_resolve_class_name(names->child[j], ctx);
					if (resolved) {
						zend_string *lc = zend_string_tolower(resolved);
						zend_hash_update(&implemented, lc, &tmp);
						zend_string_release(lc);
						zend_string_release(resolved);
					}
				}
			}
		}

		lc_iface = zend_string_tolower(r->iface->name);
		if (zend_hash_exists(&implemented, lc_iface)) {
			zend_string_release(lc_iface);
			continue; /* declared by the class itself, or introduced by an earlier rule */
		}
		{
			zval tmp;
			zend_ast *name = zend_ast_create_zval_from_str(zend_string_copy(r->iface->name));
			name->attr = ZEND_NAME_FQ;
			Z_LINENO(((zend_ast_zval *) name)->val) = decl->start_lineno;
			ZVAL_TRUE(&tmp);
			zend_hash_update(&implemented, lc_iface, &tmp);
			if (decl->child[1]) {
				decl->child[1] = zend_ast_list_add(decl->child[1], name);
			} else {
				decl->child[1] = zend_ast_create_list(1, ZEND_AST_NAME_LIST, name);
				decl->child[1]->lineno = decl->start_lineno;
			}
		}
		zend_string_release(lc_iface);

		for (j = 0; j < r->num_methods; j++) {
			aop_intro_method *m = &r->methods[j];
			zval tmp;
			if (zend_hash_exists(&declared, m->lcname)) {
				continue;
			}
			ZVAL_TRUE(&tmp);
			zend_hash_add(&declared, m->lcname, &tmp);
			if (!any_method) {
				smart_str_appends(&src, "<?php class __aop_introduction__ {\n");
				any_method = true;
			}
			aop_render_delegate(&src, m, r->impl, decl->child[0] != NULL);
		}
	}

	if (any_method) {
		aop_graft_methods(decl, &src);
	}
	smart_str_free(&src);
	zend_hash_destroy(&declared);
	zend_hash_destroy(&implemented);
	zend_string_release(fqcn);
}

static void aop_ast_walk(zend_ast *ast, aop_ast_ctx *ctx);

static void aop_ast_walk_stmts(zend_ast_list *list, aop_ast_ctx *ctx)
{
	uint32_t i;

	for (i = 0; i < list->children; i++) {
		zend_ast *stmt = list->child[i];
		if (!stmt) {
			continue;
		}
		if (stmt->kind == ZEND_AST_NAMESPACE) {
			if (stmt->child[1]) {
				/* namespace X { ... } */
				zend_string *saved_ns = ctx->ns ? zend_string_copy(ctx->ns) : NULL;
				aop_ctx_reset(ctx, stmt->child[0]);
				aop_ast_walk(stmt->child[1], ctx);
				if (ctx->ns) {
					zend_string_release(ctx->ns);
				}
				ctx->ns = saved_ns;
				zend_hash_clean(&ctx->imports);
			} else {
				aop_ctx_reset(ctx, stmt->child[0]);
			}
			continue;
		}
		if (stmt->kind == ZEND_AST_USE || stmt->kind == ZEND_AST_GROUP_USE) {
			aop_collect_use(ctx, stmt);
			continue;
		}
		aop_ast_walk(stmt, ctx);
	}
}

/* Classes are statements (top level, or conditional inside blocks and function bodies): walk everything. */
static void aop_ast_walk(zend_ast *ast, aop_ast_ctx *ctx)
{
	uint32_t i, n;

	if (!ast || aop_ast_is_leaf(ast)) {
		return;
	}
	if (aop_ast_is_decl(ast->kind)) {
		zend_ast_decl *decl = (zend_ast_decl *) ast;
		if (ast->kind == ZEND_AST_CLASS) {
			aop_weave_class(decl, ctx);
		}
		for (i = 0; i < 5; i++) {
			aop_ast_walk(decl->child[i], ctx);
		}
		return;
	}
	if (zend_ast_is_list(ast)) {
		aop_ast_walk_stmts(zend_ast_get_list(ast), ctx);
		return;
	}
	n = zend_ast_get_num_children(ast);
	for (i = 0; i < n; i++) {
		aop_ast_walk(ast->child[i], ctx);
	}
}

static void aop_ast_process(zend_ast *ast)
{
	if (AOP_G(intro_rule_count) && ast) {
		aop_ast_ctx ctx;
		ctx.ns = NULL;
		zend_hash_init(&ctx.imports, 8, NULL, aop_imports_dtor, 0);
		aop_ast_walk(ast, &ctx);
		if (ctx.ns) {
			zend_string_release(ctx.ns);
		}
		zend_hash_destroy(&ctx.imports);
	}
	if (aop_prev_ast_process) {
		aop_prev_ast_process(ast);
	}
}
/* }}} */

/* {{{ AOP\Internal\introductionDelegate(object $self, string $impl): object */
ZEND_FUNCTION(introductionDelegate)
{
	zend_object *self;
	zend_string *impl, *lc;
	zend_class_entry *ce;
	zval *entry, *found, obj;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_OBJ(self)
		Z_PARAM_STR(impl)
	ZEND_PARSE_PARAMETERS_END();

	if (!AOP_G(intro_delegates)) {
		ALLOC_HASHTABLE(AOP_G(intro_delegates));
		zend_hash_init(AOP_G(intro_delegates), 8, NULL, ZVAL_PTR_DTOR, 0);
	}
	lc = zend_string_tolower(impl);
	entry = zend_hash_index_find(AOP_G(intro_delegates), zend_object_to_weakref_key(self));
	if (entry && (found = zend_hash_find(Z_ARRVAL_P(entry), lc))) {
		zend_string_release(lc);
		RETURN_COPY(found);
	}

	ce = zend_lookup_class(impl);
	if (!ce) {
		zend_string_release(lc);
		if (!EG(exception)) {
			zend_throw_error(NULL, "Class \"%s\" not found", ZSTR_VAL(impl));
		}
		RETURN_THROWS();
	}
	if (object_init_ex(&obj, ce) != SUCCESS) {
		zend_string_release(lc);
		RETURN_THROWS();
	}
	if (ce->constructor) {
		if (!(ce->constructor->common.fn_flags & ZEND_ACC_PUBLIC)) {
			zend_throw_error(NULL, "Call to non-public %s::__construct() of the default implementation",
				ZSTR_VAL(ce->name));
		} else {
			zend_call_known_instance_method_with_0_params(ce->constructor, Z_OBJ(obj), NULL);
		}
		if (EG(exception)) {
			zend_object_store_ctor_failed(Z_OBJ(obj));
			zval_ptr_dtor(&obj);
			zend_string_release(lc);
			RETURN_THROWS();
		}
	}

	/* The constructor may have changed the table: look the entry up again. */
	entry = zend_hash_index_find(AOP_G(intro_delegates), zend_object_to_weakref_key(self));
	if (!entry) {
		zval arr;
		array_init(&arr);
		entry = zend_weakrefs_hash_add(AOP_G(intro_delegates), self, &arr);
	}
	found = zend_hash_find(Z_ARRVAL_P(entry), lc);
	if (found) {
		zval_ptr_dtor(&obj);
		zend_string_release(lc);
		RETURN_COPY(found);
	}
	zend_hash_add_new(Z_ARRVAL_P(entry), lc, &obj);
	zend_string_release(lc);
	RETURN_COPY(&obj);
}
/* }}} */

/* {{{ AOP\Internal\introductionInherited(string $class, string $method): bool */
ZEND_FUNCTION(introductionInherited)
{
	zend_string *class_name, *method;
	zend_class_entry *ce;
	zend_function *fn;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STR(class_name)
		Z_PARAM_STR(method)
	ZEND_PARSE_PARAMETERS_END();

	ce = zend_lookup_class_ex(class_name, NULL, ZEND_FETCH_CLASS_NO_AUTOLOAD);
	if (!ce || !ce->parent) {
		RETURN_FALSE;
	}
	fn = zend_hash_find_ptr_lc(&ce->parent->function_table, method);
	RETURN_BOOL(fn && !(fn->common.fn_flags & ZEND_ACC_ABSTRACT));
}
/* }}} */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_aop_introduction_delegate, 0, 2, IS_OBJECT, 0)
	ZEND_ARG_TYPE_INFO(0, self, IS_OBJECT, 0)
	ZEND_ARG_TYPE_INFO(0, impl, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_aop_introduction_inherited, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, class, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry aop_ast_functions[] = {
	ZEND_NS_FE("AOP\\Internal", introductionDelegate, arginfo_aop_introduction_delegate)
	ZEND_NS_FE("AOP\\Internal", introductionInherited, arginfo_aop_introduction_inherited)
	ZEND_FE_END
};

/* {{{ lifecycle */
void aop_ast_minit(bool hooks)
{
	zend_register_functions(NULL, aop_ast_functions, NULL, MODULE_PERSISTENT);
	if (hooks) {
		aop_prev_ast_process = zend_ast_process;
		zend_ast_process = aop_ast_process;
		aop_ast_hooked = true;
	}
}

void aop_ast_mshutdown(void)
{
	if (aop_ast_hooked) {
		zend_ast_process = aop_prev_ast_process;
		aop_ast_hooked = false;
	}
}

void aop_ast_rinit(void)
{
	AOP_G(intro_rules) = NULL;
	AOP_G(intro_rule_count) = 0;
	AOP_G(intro_delegates) = NULL;
}

void aop_ast_rshutdown(void)
{
	HashTable *ht = AOP_G(intro_delegates);

	aop_rules_free(AOP_G(intro_rules), AOP_G(intro_rule_count));
	AOP_G(intro_rules) = NULL;
	AOP_G(intro_rule_count) = 0;

	if (ht) {
		/* Unregister the weak keys (this releases the delegates) before the table goes away. */
		while (zend_hash_num_elements(ht)) {
			zend_ulong key = 0;
			ZEND_HASH_FOREACH_NUM_KEY(ht, key) {
				break;
			} ZEND_HASH_FOREACH_END();
			zend_weakrefs_hash_del(ht, zend_weakref_key_to_object(key));
		}
		zend_hash_destroy(ht);
		FREE_HASHTABLE(ht);
		AOP_G(intro_delegates) = NULL;
	}
}
/* }}} */
