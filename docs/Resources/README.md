# Resources — Material técnico y diagramas

Material técnico del proyecto (diagramas de clases, base de datos / entidad-relación, secuencia, estados, actividad, componentes y despliegue) elaborados bajo la metodología **ICONIX**.

> **Nota:** Coloca los archivos de diagramas dentro de [`diagrams/`](diagrams/).

## Diferencias conocidas entre los diagramas originales y el código actual

Al contrastar el documento funcional del semestre anterior con el código PHP y el script [`../db_9eleven.sql`](../db_9eleven.sql), se identificaron los siguientes puntos a tener en cuenta:

1. **Modelo Entidad-Relación (BD):** El script `db_9eleven.sql` implementa **8 tablas** (`administrador`, `categorias`, `productos`, `proveedores`, `ventas`, `detalle_ventas`, `entradas`, `detalle_entradas`). No existen tablas físicas `reportes` ni `alerta_stock`, ya que ambos se calculan dinámicamente mediante consultas SQL. Las entradas de mercancía (**US-302**) se guardan en `entradas` y `detalle_entradas`; el diagrama ER original no las incluye (ver **US-105** en `Management/product-backlog.md`).
2. **Diagrama de paquetes / archivos PHP:** El documento funcional plantea archivos separados por acción (`nuevoProducto.php`, `consultarProducto.php`, `eliminarProducto.php`), mientras que la implementación real agrupa cada módulo en un solo archivo (`productos.php`, `categorias.php`, `proveedores.php`, `ventas.php`) manejando vistas mediante el parámetro `?vista=`.
