# Creative Pear Monitor

Plugin de WordPress que conecta los sitios administrados por Creative Pear con su centro de control técnico.

## Funciones

- Inventario de WordPress, PHP, plugins, temas, Elementor y WooCommerce.
- Resumen agregado de WooCommerce de los últimos 30 días: pedidos, ventas netas, ticket medio, fallos, pasarela principal y producto más vendido, sin datos de clientes.
- Detección de administradores y cambios técnicos.
- Señales de correo, cron, base de datos y estado de checkout.
- Resumen local de Defender: escaneos, hallazgos, cuarentena, módulos, bloqueos e IPs enmascaradas.
- Sesiones firmadas de tres minutos para pruebas automáticas de formularios con CAPTCHA compatible, incluido hCaptcha para Elementor, sin desactivar plugins globalmente.
- Envío inmediato tras cambios relevantes y pulso de respaldo cada cinco minutos.
- Avisos de actualización cada cinco minutos desde las releases de este repositorio y actualización remota firmada desde Creative Pear Status.

## Instalación

1. Descarga `creative-pear-monitor.zip` desde la última release.
2. Instálalo en **Plugins → Añadir plugin → Subir plugin**.
3. Actívalo y abre **Settings** bajo el nombre del plugin.
4. Introduce el ID del sitio y la clave del agente. La URL de Creative Pear Status ya viene configurada.

La actualización remota solo puede reemplazar Creative Pear Monitor. Las instalaciones anteriores a la versión 1.5.0 necesitan actualizarse manualmente una vez; las siguientes versiones podrán instalarse desde el panel.

El plugin no incluye claves ni contraseñas en el repositorio y nunca envía contenido privado de WordPress, claves de WPMU DEV ni direcciones IP completas.
