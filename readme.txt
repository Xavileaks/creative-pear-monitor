=== Creative Pear Monitor ===
Contributors: creativepear
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.6.0

Conector para clientes administrados por Creative Pear.

La actualización remota segura conserva la activación del agente tras reemplazar sus archivos y permite que Creative Pear Status compruebe la compatibilidad antes de enviar una orden de actualización. Las versiones anteriores necesitan una instalación manual de transición.

El reporte del agente utiliza la versión instalada en disco para evitar mostrar temporalmente la versión anterior después de una actualización remota.

Versión 1.6.0: informa al panel privado de la URL real de acceso a WordPress, incluida la máscara de Defender cuando está activa.

Versión 1.5.0: permite actualizar Creative Pear Monitor desde Creative Pear Status mediante una orden firmada para cada sitio. La acción solo actualiza este agente.

Versión 1.4.0: añade estadísticas privadas y agregadas de WooCommerce para el panel, incluidos pedidos, ventas, ticket medio, pasarela principal y producto más vendido, sin enviar datos de clientes.

== Instalación ==
1. Instala el ZIP desde Plugins > Añadir plugin > Subir plugin.
2. Actívalo.
3. Abre Ajustes > Creative Pear Monitor.
4. Pega el ID del sitio y la clave generada por el dashboard. La URL del panel ya viene configurada.
5. Pulsa "Enviar reporte ahora" para validar la conexión.

El plugin reporta versiones, actualizaciones pendientes, administradores, PHP, presencia de formularios, estado reciente de wp_mail, configuración básica de WooCommerce y un resumen local de Defender. También puede autorizar una sesión firmada de tres minutos para que Creative Pear pruebe un formulario con CAPTCHA compatible, incluido hCaptcha para Elementor, sin desactivar el plugin de seguridad para otros visitantes. No envía contraseñas, claves de WPMU DEV, contenido de usuarios ni direcciones IP completas.

== Actualizaciones ==
Las nuevas versiones publicadas en GitHub se comprueban cada cinco minutos y aparecen en la pantalla de plugins de WordPress. Las versiones con actualización remota segura pueden instalarse mediante una orden firmada desde Creative Pear Status. Si el panel indica que el agente requiere instalación manual, instala una versión segura una vez desde WordPress antes de usar el botón masivo. El antiguo intervalo de 15 minutos para los reportes se elimina al actualizar y se sustituye por eventos inmediatos más un pulso de respaldo cada cinco minutos.
