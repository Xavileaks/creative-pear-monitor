=== Creative Pear Monitor ===
Contributors: creativepear
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.2.0

Conector para clientes administrados por Creative Pear.

Versión 1.2.0: actualizaciones automáticas desde GitHub, sincronización inmediata tras cambios técnicos y acceso directo a Settings.

== Instalación ==
1. Instala el ZIP desde Plugins > Añadir plugin > Subir plugin.
2. Actívalo.
3. Abre Ajustes > Creative Pear Monitor.
4. Pega el ID del sitio y la clave generada por el dashboard.
5. Pulsa "Enviar reporte ahora" para validar la conexión.

El plugin reporta versiones, actualizaciones pendientes, administradores, PHP, presencia de formularios, estado reciente de wp_mail y configuración básica de WooCommerce. No envía contraseñas ni contenido de usuarios.

== Actualizaciones ==
Las nuevas versiones publicadas en GitHub se detectan y se instalan automáticamente. El antiguo intervalo de 15 minutos se elimina al actualizar y se sustituye por eventos inmediatos más un pulso de respaldo cada cinco minutos.
