---
name: 👤 Historia de Usuario (HU)
about: Funcionalidad vista desde la perspectiva del Administrador del minimarket.
title: "[HU-XX] - Título corto de la historia"
labels: user-story
assignees: ''
---

## 📝 Descripción
**Como** Administrador del minimarket 9Eleven,
**Quiero** [acción o funcionalidad deseada],
**Para** [beneficio o valor esperado en la gestión].

**Épica asociada:** # (Número de issue de la épica)

---

## 🧪 Criterios de Aceptación (Formato Gherkin)

### Escenario 1: Flujo exitoso
* **Dado que** [contexto inicial, ej: el administrador ha iniciado sesión y está en el módulo de productos],
* **Cuando** [acción que realiza el usuario, ej: completa el formulario con datos válidos y guarda],
* **Entonces** [resultado esperado, ej: el sistema actualiza la base de datos y muestra un mensaje de confirmación].

### Escenario 2: Validación / Manejo de errores
* **Dado que** [contexto inicial],
* **Cuando** [acción inválida o error provocado],
* **Entonces** [resultado esperado, ej: el sistema rechaza la operación y muestra un mensaje claro sin romper la interfaz].

---

## 🏁 Definition of Done (DoD)
- [ ] Código implementado respetando la arquitectura modular y estándares del proyecto (`config.php`, `requerirLogin()`).
- [ ] Consultas SQL ejecutadas mediante *prepared statements* de PDO y salidas escapadas con `h()`.
- [ ] Criterios de aceptación (Gherkin) probados manualmente en entorno local (XAMPP).
- [ ] Si altera el diseño original: diagramas en código (Mermaid/UML) y documentación actualizados.
- [ ] Pull Request creado hacia `main`, revisado y aprobado por al menos un miembro del equipo.
