# Mi Finanzas

Aplicación web en PHP 8.3 y MySQL 8 para organizar finanzas personales y del hogar. Incluye autenticación, hogares, cuentas y movimientos, deudas y tarjetas, informes mensuales, presupuestos, metas de ahorro, importación CSV y recordatorios.

## Requisitos

- PHP 8.3 o superior con `pdo_mysql`, `mbstring` y `openssl`.
- MySQL 8.0.16 o superior (para que se apliquen las restricciones `CHECK`).
- Composer 2.
- Apache con `mod_rewrite` y `AllowOverride All`, o una configuración equivalente.
- HTTPS habilitado en producción.

## Despliegue inicial en el hosting

Sube el proyecto al directorio configurado para `finanzas.jodamuover.com`. El archivo `.env` se crea solo en el servidor; el SFTP está configurado para excluirlo. La configuración de Apache bloquea también el acceso web a ese archivo.

En Terminal/SSH del hosting:

```sh
cd /home/jodamuover-finanzas/htdocs/finanzas.jodamuover.com
composer install --no-dev --optimize-autoloader
cp .env.example .env
chmod 600 .env
nano .env
```

En `.env`, configura `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASSWORD` con los datos del panel de hosting, y completa las variables `MAIL_*` con la configuración que te dio tu proveedor SMTP. Mantén `APP_URL` en la URL HTTPS canónica. No compartas ese archivo ni pegues contraseñas en el chat. La base de datos debe existir y el usuario debe tener permisos sobre ella.

Luego ejecuta:

```sh
php -v
php -m | grep -E 'pdo_mysql|mbstring|openssl'
composer check-platform-reqs
php bin/migrate.php
php bin/check-install.php
```

En Windows PowerShell del servidor, si `grep` no está disponible, utiliza:

```powershell
php -m
```

Visita `https://finanzas.jodamuover.com/health`; debe responder `{"status":"ok"}`. Después abre el sitio, crea la primera cuenta y confirma el correo de verificación. La primera persona registrada queda como propietaria del hogar; desde su hogar puede generar una invitación para el otro integrante.

## Actualización: cuentas y movimientos

La migración `002_create_financial_core.sql` crea las tablas de cuentas, categorías, operaciones, movimientos y transferencias, y amplía `audit_log`. El usuario ya ejecutó esta migración en el hosting; conserva la siguiente sección como referencia.

El migrador omite migraciones ya registradas; no borres tablas ni el historial de migraciones. Si una migración muestra un error, detente y comparte el mensaje sin incluir contraseñas ni el contenido de `.env`.

## Actualización: deudas, informes y acceso

Antes de subir una migración a producción, genera un respaldo de la base de datos. Después de subir los archivos, conéctate por SSH:

**No reemplaces el `.env` existente.** Edita ese archivo solo en el servidor y añade las variables SMTP de `.env.example`: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`. Mantén `APP_URL` en la URL HTTPS canónica. Usa `tls` normalmente con el puerto 587 o `ssl` con 465, según los datos que te dio tu proveedor SMTP. No pegues secretos en el chat ni los subas al repositorio.

```sh
cd /home/jodamuover-finanzas/htdocs/finanzas.jodamuover.com
composer install --no-dev --optimize-autoloader
php -l index.php
find app bin config -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/migrate.php
php bin/check-install.php
```

La migración `003_debts_reports_auth.sql` añade tokens de verificación y recuperación, deudas/tarjetas y la categoría de intereses. No elimina datos existentes; las cuentas existentes quedan marcadas como verificadas. `check-install.php` debe mostrar `[OK]` para la migración de Fase 3, SMTP/HTTPS y Symfony Mailer antes de probar los correos.

En el sitio, comprueba lo siguiente:

1. Inicia sesión con la cuenta existente y confirma que cuentas, movimientos e integrantes sigan disponibles.
2. Usa recuperación de contraseña con tu propia cuenta y revisa que llegue el correo; cambia la contraseña solo si estás listo para usarla en el siguiente inicio de sesión.
3. En una base de pruebas, crea una tarjeta, registra una compra y un pago. La compra debe aparecer una sola vez como gasto; el pago reduce la deuda y el saldo bancario, sin duplicar el gasto.
4. Comprueba informes por mes y por espacio personal/familiar. Los desembolsos y abonos a capital no son ingresos ni gastos; los intereses sí son gastos.
5. Como propietario, cambia un integrante entre administrador e integrante y suspéndelo/reactívalo. Un administrador puede consultar integrantes, pero no cambiar roles.

No uses importes de prueba en una cuenta financiera real. Si no dispones de una base de pruebas, limita la comprobación inicial a rutas, correo y configuración; acuerda antes cómo registrar y anular movimientos de prueba.

La aplicación permite crear cuentas personales y familiares, registrar ingresos, gastos y ajustes, transferir entre cuentas del mismo espacio y moneda, y anular operaciones mediante movimientos compensatorios. Los saldos se calculan desde el saldo inicial y los movimientos confirmados. Los gastos respetan el saldo disponible y el sobregiro configurado. Las tarjetas y préstamos usan un registro de pasivos separado para evitar contar un pago como un segundo gasto.

Prueba en el sitio: crea una cuenta con saldo inicial, registra un ingreso y un gasto, verifica el saldo, transfiere entre dos cuentas del mismo espacio, intenta un gasto que exceda el saldo disponible y confirma que se rechaza; luego anula una operación y comprueba que se conserva su historial y se refleja el movimiento compensatorio. También verifica que una cuenta personal no sea visible para el otro usuario y que las cuentas familiares requieran rol propietario/administrador para crearse.

## Actualización: planificación financiera

Antes de aplicar la migración `004_financial_configuration_and_planning.sql`, crea un respaldo completo desde el panel del hosting. Sube los archivos nuevos sin reemplazar `.env` y ejecuta en SSH:

```sh
cd /home/jodamuover-finanzas/htdocs/finanzas.jodamuover.com
composer install --no-dev --optimize-autoloader
find app bin config -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -l index.php
php bin/migrate.php
php bin/check-install.php
```

El migrador aplicará las migraciones pendientes en orden (incluida la Fase 3 si aún no está registrada). Si alguna falla, detente y comparte solo el mensaje de error, sin credenciales ni contenido de `.env`; no repitas a ciegas una migración que se haya aplicado parcialmente.

La Fase 4 permite configurar el ciclo y umbral del presupuesto por espacio; solo propietarios y administradores pueden cambiar parámetros o presupuestos del hogar. Los presupuestos suman gastos confirmados por categoría y respetan anulaciones. Las metas guardan sus aportes y retiros en un libro separado: no cambian saldos ni informes de ingresos/gastos. La importación acepta CSV genérico con encabezados mapeados, hasta 2 MB y 1.000 filas; revisa la vista previa y los duplicados antes de confirmar. En el panel PHP del hosting configura `upload_max_filesize` en al menos `2M` y `post_max_size` en al menos `3M`.

Para validar en una base de pruebas, comprueba: cambiar entre parámetros personal/familiares y rechazar la edición por un miembro sin rol; crear un presupuesto, registrar un gasto y comprobar el avance; aportar y retirar de una meta y comprobar que el saldo de cuenta/informe no cambia; importar un CSV con `Fecha,Valor,Descripción,Categoría`, verificar la vista previa y confirmar, y volver a cargar el mismo archivo para revisar la deduplicación. No uses movimientos de prueba en una cuenta real.

### Recordatorios diarios

Los recordatorios por correo están desactivados de forma predeterminada. Para usarlos, configura SMTP en `.env`, actívalos en los parámetros del espacio y programa una tarea diaria en el cron del hosting. Desde SSH, prueba primero:

```sh
php bin/send-reminders.php
```

En cPanel agrega una tarea diaria, por ejemplo a las 8:15 a. m. (hora del servidor):

```cron
15 8 * * * /usr/bin/php /home/jodamuover-finanzas/htdocs/finanzas.jodamuover.com/bin/send-reminders.php
```

Si tu hosting usa otra ruta de PHP, reemplaza `/usr/bin/php` por la ruta que te indique el proveedor. El comando notifica cuando un presupuesto alcanza su umbral o se acerca la fecha de una meta; registra cada entrega para evitar reenvíos. Revisa el log de tareas si el comando informa envíos fallidos. No habilites recordatorios hasta probar SMTP.

## Actualización: panel, movimientos recurrentes y exportación

Antes de aplicar la migración `005_recurring_movements.sql`, crea un respaldo completo desde el panel del hosting. Sube los archivos nuevos sin reemplazar `.env` y ejecuta por SSH:

```sh
cd /home/jodamuover-finanzas/htdocs/finanzas.jodamuover.com
composer install --no-dev --optimize-autoloader
find app bin config -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -l index.php
php bin/migrate.php
php bin/check-install.php
```

El migrador aplica las migraciones pendientes en orden. Confirma que las fases anteriores aparezcan aplicadas y que `check-install.php` muestre `[OK]` para las fases 3, 4 y 5. Si hay un error, detente y comparte solo el mensaje, sin credenciales ni datos de `.env`; no repitas una migración parcial a ciegas.

El panel de inicio muestra el flujo de caja mensual, gastos por categoría y saldos de cuentas activas, con selector de mes y espacio personal/familiar. Los informes se pueden descargar en CSV compatible con hojas de cálculo o imprimir desde el navegador y guardar como PDF. El respaldo descargable es JSON: incluye tus datos personales y los hogares a los que tienes acceso, pero excluye contraseñas, tokens y secretos de configuración. Guárdalo en un lugar privado; esta versión no restaura automáticamente un respaldo.

Las programaciones recurrentes admiten ingresos y gastos semanales, mensuales o anuales. Al llegar la fecha aparecen como pendientes en `Movimientos recurrentes`; ningún movimiento se agrega a una cuenta ni modifica saldos hasta que pulses **Revisar y confirmar**. No necesitan cron. En meses sin el día programado (por ejemplo, el 31), se usa el último día del mes. Para probar, usa una cuenta y base de pruebas; comprueba el movimiento pendiente antes de confirmarlo y verifica que se registre una sola vez. Pausar una programación no la elimina y su historial confirmado se conserva.

## Página pública e indexación

La ruta `/` muestra una página pública en español para buscadores y visitantes. Las rutas de inicio de sesión, registro, invitaciones y la aplicación autenticada usan `noindex, nofollow`. `robots.txt` publica el sitemap, que contiene únicamente la página pública. El marcado estructurado describe la aplicación sin atribuirle módulos que aún no están disponibles.

## Metadatos de despliegue

`APP_URL` debe apuntar a la URL HTTPS canónica del sitio. Si se cambia el dominio, actualiza también `robots.txt` y `sitemap.xml`. No incluyas datos personales, cuentas financieras ni afirmaciones de certificación en contenido público sin verificarlas.

## Pruebas

Para instalar herramientas de desarrollo y ejecutar la suite en un entorno de desarrollo o pruebas:

```sh
composer install
composer test
```

No instalar dependencias de desarrollo en producción. No hay PHP ni Composer instalados en el equipo de desarrollo de este proyecto; los comandos de sintaxis, migración y verificación deben ejecutarse en el hosting. La suite PHPUnit debe ejecutarse en un entorno de pruebas con dependencias de desarrollo.

## Configuración de Apache

El `.htaccess` enruta las solicitudes al controlador frontal y bloquea el acceso web a configuración, migraciones, registros, dependencias y pruebas. Si el hosting no respeta `.htaccess`, configura el document root en un directorio público dedicado antes de publicar la aplicación.

## Estado

- Fase 1: estructura, conexión PDO, migración base, registro, inicio/cierre de sesión, límites de intentos, auditoría de eventos de acceso, hogares e invitaciones de siete días.
- Fase 2: cuentas y motor de movimientos implementados; migración ejecutada en el hosting, pruebas funcionales completas pendientes.
- Fase 3: código de tarjetas/deudas, informes, verificación/recuperación por correo y gestión de roles implementado; pendiente subir archivos, configurar SMTP, ejecutar la migración y probar en el hosting.
- Fase 4: configuración financiera por espacio, presupuestos, metas, importación CSV y recordatorios programados implementados; pendiente subir archivos, aplicar la migración y validar en el hosting.
- Fase 5: panel de flujo de caja, movimientos recurrentes con confirmación manual, exportación mensual CSV/PDF e información financiera en respaldo JSON; pendiente subir archivos, aplicar la migración y validar en el hosting.
- Página pública en español con metadatos SEO, sitemap y marcado estructurado; áreas de cuenta excluidas del índice.
