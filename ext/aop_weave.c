/*
 * aop extension: prologue weaving (aop.interception=woven).
 *
 * The woven backend leaves the VM untouched (no zend_execute_ex hook, no user opcode handlers), so the
 * opcache JIT keeps running. Instead zend_ast_process (after parsing, before compiling every file and
 * eval'd string, see aop_ast.c) prepends one statement to the body of every eligible function and method:
 *
 *   value        if (\AOP\Internal\intercept()) return \AOP\Internal\result();
 *   void, ctor   if (\AOP\Internal\intercept()) { \AOP\Internal\result(); return; }
 *   never        if (\AOP\Internal\intercept()) \AOP\Internal\result();
 *   generator    if (\AOP\Internal\intercept()) return yield from \AOP\Internal\result();
 *
 * intercept() and result() are in aop_hook.c. The decision whether a call is advised stays a run time
 * matter (aop_decide()), so the woven form of a file does not depend on the registered rules and opcache
 * can cache it.
 *
 * Not woven: closures and arrow functions (they are only join points as fake closures, which run a method
 * that is woven itself), functions without a body (abstract, interface), property hooks (8.4+), functions
 * that return by reference (the engine never intercepts them), and everything in the AOP\ namespace.
 *
 * Verified against PHP-8.3.6, 8.4.27 and 8.5.12: function and method declarations in Zend/zend_ast.h
 * (zend_ast_decl: child[2] is the body, child[3] the return type; flags carry ZEND_ACC_RETURN_REFERENCE),
 * and the shapes the parser gives `if`, `return`, `yield from` and calls (Zend/zend_language_parser.y).
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_ast.h"
#include "zend_compile.h"
#include "php_aop.h"
#include "aop_g.h"

typedef enum {
	AOP_SHAPE_VALUE,
	AOP_SHAPE_VOID,
	AOP_SHAPE_NEVER,
	AOP_SHAPE_GENERATOR
} aop_shape;

/* True for the namespaces AOP and AOP\...: the runtime's own code is never intercepted (contract §1.4). */
static bool aop_namespace_is_aop(zend_ast *name_ast)
{
	zend_string *name;

	if (!name_ast || name_ast->kind != ZEND_AST_ZVAL || Z_TYPE_P(zend_ast_get_zval(name_ast)) != IS_STRING) {
		return false;
	}
	name = zend_ast_get_str(name_ast);
	return zend_string_equals_literal_ci(name, "AOP")
		|| (ZSTR_LEN(name) > sizeof("AOP\\") - 1 && strncasecmp(ZSTR_VAL(name), "AOP\\", sizeof("AOP\\") - 1) == 0);
}

/* {{{ prologue */
static zend_ast *aop_fq_call(const char *name, size_t len)
{
	zval zv;

	ZVAL_STR(&zv, zend_string_init(name, len, 0));
	return zend_ast_create(ZEND_AST_CALL, zend_ast_create_zval_ex(&zv, ZEND_NAME_FQ),
		zend_ast_create_list(0, ZEND_AST_ARG_LIST));
}

static zend_ast *aop_make_prologue(aop_shape shape)
{
	zend_ast *cond = aop_fq_call(ZEND_STRL("AOP\\Internal\\intercept"));
	zend_ast *result = aop_fq_call(ZEND_STRL("AOP\\Internal\\result"));
	zend_ast *then;

	switch (shape) {
		case AOP_SHAPE_GENERATOR:
			then = zend_ast_create(ZEND_AST_RETURN, zend_ast_create(ZEND_AST_YIELD_FROM, result));
			break;
		case AOP_SHAPE_VOID:
			then = zend_ast_create_list(2, ZEND_AST_STMT_LIST, result, zend_ast_create(ZEND_AST_RETURN, NULL));
			break;
		case AOP_SHAPE_NEVER:
			then = result;
			break;
		default:
			then = zend_ast_create(ZEND_AST_RETURN, result);
			break;
	}
	return zend_ast_create_list(1, ZEND_AST_IF, zend_ast_create(ZEND_AST_IF_ELEM, cond, then));
}
/* }}} */

/* {{{ classification */
/* A function is a generator when its own body (not a nested function or class) contains yield. */
static bool aop_has_yield(zend_ast *ast)
{
	uint32_t i, n;

	if (!ast || aop_ast_is_leaf(ast) || aop_ast_is_decl(ast->kind)) {
		return false;
	}
	if (ast->kind == ZEND_AST_YIELD || ast->kind == ZEND_AST_YIELD_FROM) {
		return true;
	}
	if (zend_ast_is_list(ast)) {
		zend_ast_list *list = zend_ast_get_list(ast);
		for (i = 0; i < list->children; i++) {
			if (aop_has_yield(list->child[i])) {
				return true;
			}
		}
		return false;
	}
	n = zend_ast_get_num_children(ast);
	for (i = 0; i < n; i++) {
		if (aop_has_yield(ast->child[i])) {
			return true;
		}
	}
	return false;
}

static aop_shape aop_classify(zend_ast_decl *decl)
{
	zend_ast *ret = decl->child[3];

	if (aop_has_yield(decl->child[2])) {
		return AOP_SHAPE_GENERATOR;
	}
	/* void and never are parsed as plain names; the compiler maps them to types later. */
	if (ret && ret->kind == ZEND_AST_ZVAL && ret->attr == ZEND_NAME_NOT_FQ && Z_TYPE_P(zend_ast_get_zval(ret)) == IS_STRING) {
		zend_string *type = zend_ast_get_str(ret);
		if (zend_string_equals_literal_ci(type, "void")) {
			return AOP_SHAPE_VOID;
		}
		if (zend_string_equals_literal_ci(type, "never")) {
			return AOP_SHAPE_NEVER;
		}
	}
	if (decl->kind == ZEND_AST_METHOD && zend_string_equals_literal_ci(decl->name, "__construct")) {
		return AOP_SHAPE_VOID;
	}
	return AOP_SHAPE_VALUE;
}

static void aop_weave_function(zend_ast_decl *decl)
{
	zend_ast_list *body, *woven;
	zend_ast *prologue;
	uint32_t i;

	if (!decl->child[2] || (decl->flags & ZEND_ACC_RETURN_REFERENCE)) {
		return;
	}
	body = zend_ast_get_list(decl->child[2]);

	prologue = aop_make_prologue(aop_classify(decl));
	aop_ast_set_lineno(prologue, decl->start_lineno);

	/* Lists only grow at the end: build the new body with the prologue in front. */
	woven = zend_ast_get_list(zend_ast_create_list(0, ZEND_AST_STMT_LIST));
	woven = zend_ast_get_list(zend_ast_list_add((zend_ast *) woven, prologue));
	for (i = 0; i < body->children; i++) {
		woven = zend_ast_get_list(zend_ast_list_add((zend_ast *) woven, body->child[i]));
	}
	woven->lineno = body->lineno;
	decl->child[2] = (zend_ast *) woven;
}
/* }}} */

/* {{{ walk */
static void aop_weave_walk(zend_ast *ast, bool *in_aop_ns);

static void aop_weave_walk_stmts(zend_ast_list *list, bool *in_aop_ns)
{
	uint32_t i;

	for (i = 0; i < list->children; i++) {
		zend_ast *stmt = list->child[i];

		if (!stmt) {
			continue;
		}
		if (stmt->kind == ZEND_AST_NAMESPACE) {
			bool saved = *in_aop_ns;
			*in_aop_ns = aop_namespace_is_aop(stmt->child[0]);
			if (stmt->child[1]) {
				/* namespace X { ... }: the setting ends with the block. */
				aop_weave_walk(stmt->child[1], in_aop_ns);
				*in_aop_ns = saved;
			}
			/* namespace X;: stays in force for the statements that follow. */
			continue;
		}
		aop_weave_walk(stmt, in_aop_ns);
	}
}

/* Functions and classes are statements (top level, or conditional inside blocks and bodies): walk everything. */
static void aop_weave_walk(zend_ast *ast, bool *in_aop_ns)
{
	uint32_t i, n;

	if (!ast || aop_ast_is_leaf(ast)) {
		return;
	}
	if (aop_ast_is_decl(ast->kind)) {
		zend_ast_decl *decl = (zend_ast_decl *) ast;

		if (!*in_aop_ns && (ast->kind == ZEND_AST_FUNC_DECL || ast->kind == ZEND_AST_METHOD)) {
			aop_weave_function(decl);
		}
		for (i = 0; i < 5; i++) {
			aop_weave_walk(decl->child[i], in_aop_ns);
		}
		return;
	}
	if (zend_ast_is_list(ast)) {
		aop_weave_walk_stmts(zend_ast_get_list(ast), in_aop_ns);
		return;
	}
	n = zend_ast_get_num_children(ast);
	for (i = 0; i < n; i++) {
		aop_weave_walk(ast->child[i], in_aop_ns);
	}
}

void aop_weave_ast(zend_ast *ast)
{
	bool in_aop_ns = false;

	aop_weave_walk(ast, &in_aop_ns);
}
/* }}} */
