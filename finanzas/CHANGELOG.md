# Changelog

## 0.3.0 - Deudas, informes y acceso

- Se agregan tarjetas, préstamos y otras deudas con cargos, abonos a capital, intereses y desembolsos sin doble contabilización.
- Se incorporan informes mensuales por categoría y por espacio personal o familiar.
- Se agregan verificación de correo, recuperación de contraseña con tokens de un solo uso y configuración SMTP.
- Se permite al propietario administrar roles y suspender o reactivar integrantes del hogar.
- La migración `003_debts_reports_auth.sql` y las pruebas funcionales aún deben ejecutarse en el hosting.

## 0.2.0 - Cuentas y movimientos financieros

- Se agrega el esquema de cuentas, categorías, operaciones, movimientos y transferencias.
- Se implementan saldos basados en movimientos, importes decimales sin floats, control de acceso personal/familiar e idempotencia.
- Se agregan transferencias, sobregiros configurables, anulaciones con movimientos compensatorios e historial de auditoría.
- Se añaden pantallas de cuentas y movimientos, además de pruebas unitarias para importes y fechas.
- La migración fue ejecutada en el hosting; las pruebas funcionales completas siguen pendientes.

## 0.1.1 - Página pública e indexación

- Se agrega una página pública que explica con transparencia el alcance actual del producto.
- Se incorporan metadatos SEO, URL canónica, Open Graph, tarjeta social y JSON-LD con nonce de CSP.
- Se excluyen del índice las páginas de autenticación y datos de usuario; se publican `robots.txt` y `sitemap.xml`.

## 0.1.0 - Fase 1

- Se crea la estructura inicial del proyecto PHP y el manejo de configuración por variables de entorno.
- Se agregan autenticación, registro, hogares, invitaciones de un solo uso y controles básicos de sesión.
- Se incorpora la primera migración MySQL y herramientas CLI de instalación/verificación.
