# AGENTS

- Consulta `docs/domain-contract.md` antes de reinterpretar reglas de negocio.
- No reaudites reglas ya cerradas sin evidencia nueva en código o tests.
- No inventes equivalencias por similitud de nombres.
- Reutiliza servicios financieros existentes antes de crear lógica paralela.
- Ejecuta tests dirigidos por riesgo; evita suites amplias si el cambio es acotado.
- No generes release por cambios parciales.
- Detente solo si hay ambigüedad real que afecte el resultado o el riesgo de datos.
- Todo cambio funcional debe evaluar impacto en `config/assistant_knowledge.php`. Si cambia comportamiento que un usuario pueda preguntar, la base de conocimiento debe actualizarse en el mismo ciclo y desplegarse junto con el código. No cerrar una funcionalidad con conocimiento desalineado.
