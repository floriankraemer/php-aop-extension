/*
 * aop extension, stream G: experimental engine join points.
 *
 *   aop_fields.c  ZEND_NEW user opcode handler: field-get/field-set through per-class wrapped object
 *                 handlers, and `initialization` for classes without a constructor
 *   aop_catch.c   ZEND_CATCH user opcode handler: exception-handler (before-only)
 *   aop_ast.c     Engine::setIntroductions(): zend_ast_process hook weaving introductions
 *   aop_weave.c   aop.interception=woven: prologue weaving of functions, run from the same hook
 *
 * Behaviour: docs/internals/contract.md §6 and "Engine notes (stream G)".
 */
#ifndef AOP_G_H
#define AOP_G_H

#include "zend_ast.h"

/* Declarations, and the special nodes that are no declarations, have no ordinary children. */
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

/* aop_fields.c */
void aop_fields_minit(void);
void aop_fields_mshutdown(void);
void aop_fields_rinit(void);
void aop_fields_post_deactivate(void);

/* aop_catch.c */
void aop_catch_minit(void);
void aop_catch_mshutdown(void);

/* aop_ast.c */
void aop_ast_minit(bool hooks);
void aop_ast_set_lineno(zend_ast *ast, uint32_t line);
void aop_ast_mshutdown(void);
void aop_ast_rinit(void);
void aop_ast_rshutdown(void);
/* Engine::setIntroductions(): validates and compiles the rules; throws on invalid input. */
void aop_ast_set_rules(HashTable *rules);

/* aop_weave.c: prepends the interception prologue to every eligible function of a parsed file. */
void aop_weave_ast(zend_ast *ast);

#endif /* AOP_G_H */
