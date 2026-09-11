=== Creative Pear Monitor ===
Contributors: creativepear
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.3.0

Conector para clientes administrados por Creative Pear.

Versión 1.3.0: integración local con Defender para reportar escaneos, malware, cuarentena, protección, bloqueos e IPs enmascaradas sin compartir claves.

== Instalación ==
1. Instala el ZIP desde Plugins > Añadir plugin > Subir plugin.
2. Actívalo.
3. Abre Ajustes > Creative Pear Monitor.
4. Pega el ID del sitio y la clave generada por el dashboard. La URL del panel ya viene configurada.
5. Pulsa "Enviar reporte ahora" para validar la conexión.

El plugin reporta versiones, actualizaciones pendientes, administradores, PHP, presencia de formularios, estado reciente de wp_mail, configuración básica de WooCommerce y un resumen local de Defender. También puede autorizar una sesión firmada de tres minutos para que Creative Pear pruebe un formulario con CAPTCHA compatible sin desactivar el plugin de seguridad para otros visitantes. No envía contraseñas, claves de WPMU DEV, contenido de usuarios ni direcciones IP completas.

== Actualizaciones ==
Las nuevas versiones publicadas en GitHub se comprueban cada cinco minutos y aparecen en la pantalla de plugins de WordPress. La instalación solo se realiza cuando un administrador pulsa «Actualizar ahora». El antiguo intervalo de 15 minutos para los reportes se elimina al actualizar y se sustituye por eventos inmediatos más un pulso de respaldo cada cinco minutos.
