/*
 * aop extension, stream G: experimental engine join points.
 *
 *   aop_fields.c  ZEND_NEW user opcode handler: field-get/field-set through per-class wrapped object
 *                 handlers, and `initialization` for classes without a constructor
 *   aop_catch.c   ZEND_CATCH user opcode handler: exception-handler (before-only)
 *   aop_ast.c     Engine::setIntroductions(): zend_ast_process hook weaving introductions
 *
 * Behaviour: docs/internals/contract.md §6 and "Engine notes (stream G)".
 */
#ifndef AOP_G_H
#define AOP_G_H

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
void aop_ast_mshutdown(void);
void aop_ast_rinit(void);
void aop_ast_rshutdown(void);
/* Engine::setIntroductions(): validates and compiles the rules; throws on invalid input. */
void aop_ast_set_rules(HashTable *rules);

#endif /* AOP_G_H */
