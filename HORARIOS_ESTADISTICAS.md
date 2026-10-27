# Horarios de cámaras y estadísticas por hora

Implementación sobre el proyecto existente. No cambia firmware, tokens, Shield,
Gemini, Telegram ni el almacenamiento de fotografías. La recepción incorpora
una comprobación antes de consultar el uniforme y antes de analizar la imagen.

## Horarios

En **Dispositivos**, cada cámara tiene pausa manual independiente y configuración
semanal con varios períodos. Solo pueden guardar los mismos perfiles autorizados
para editar uniformes: superadmin con asociación activa o administrador con rol
de empresa admin/superadmin. Se comprueba empresa seleccionada, asociación,
pertenencia de la cámara y CSRF en cada escritura. Usuarios de consulta solo leen.

- Se utiliza `empresas.zona_horaria`, con el fallback existente America/Guatemala.
- Sin horario configurado: funciona todo el día, salvo pausa manual o estado inactivo.
- Con horario configurado y sin períodos: no analiza fotografías.
- Día ISO 1=lunes, 7=domingo; el día corresponde al inicio del período.
- Inicio incluido, fin excluido. Un fin anterior al inicio cruza medianoche.
- Horas iguales se rechazan. Para cubrir 24 horas con horario semanal pueden usarse
  dos períodos contiguos (00:00–12:00 y 12:00–00:00).
- Períodos superpuestos se interpretan como unión; no duplican análisis.
- Guardar horarios no cambia la pausa. Reactivar no elimina los horarios.
- Se guardan todos los períodos en una transacción, hasta 100 por cámara.

### Contrato ESP32-CAM

`GET /api/dispositivos/configuracion`, cabecera `X-Device-Token` existente.
No necesita sesión ni CSRF. No recibe empresa desde el cliente; se deriva del token.

Respuesta HTTP 200 de una cámara autorizada:

```json
{"ok":true,"deteccion_habilitada":true}
```

Pausada o fuera de horario: HTTP 200 con `ok: true` y `deteccion_habilitada: false`.
Errores conservan ambos campos booleanos: HTTP 401 sin token válido/cámara activa,
403 para empresa inactiva y 503 ante error de consulta, con ambos valores false.
La respuesta no se almacena en caché.

El firmware ya consulta aproximadamente cada 60 segundos. El servidor verifica
su propia hora en cada POST de fotografía: fuera de horario/pausa responde 403,
`ok: false`, `deteccion_habilitada: false` y un mensaje. No llama a Gemini,
no guarda fotografías/detecciones y no envía Telegram en ese caso.

## Estadísticas

La vista Por día conserva los últimos 14 días y las tarjetas Hoy/Semana/Mes.
La vista Por hora admite fechas inclusivas de uno a tres días naturales;
por defecto consulta hoy según la empresa. Fechas inválidas, invertidas o rangos
mayores muestran un error. Cada jornada tiene 24 posiciones 00:00–23:00, su fecha
y total, con escala común entre jornadas y una tabla comparativa accesible.

Solo se cuentan filas existentes con clasificación sin_uniforme y empresa
seleccionada autorizada. Cero significa ausencia de registros; no acredita
cumplimiento ni funcionamiento de la cámara. No se calculan porcentajes.

Las fechas permanecen en UTC. La consulta agrupa minutos UTC dentro del rango
acotado y convierte esos grupos a la zona local; no carga todas las detecciones.
Esto admite zonas con fracciones de hora y horario de verano. Si se repite una
hora local se suman sus registros; las horas inexistentes muestran cero.

## Archivos

Modificados:

- app/Config/Routes.php
- app/Controllers/Admin/PanelController.php
- app/Controllers/Api/DeteccionesController.php
- app/Views/admin/dashboard.php
- app/Views/admin/dispositivos.php
- public/assets/panel.css
- tests/feature/PanelTest.php
- PANEL_ADMIN.md

Creados:

- app/Database/Migrations/2026-10-27-120000_AddHorariosDispositivos.php
- app/Controllers/Api/DispositivosController.php
- app/Services/DispositivoHorarioService.php
- app/Services/EstadisticasService.php
- tests/unit/DispositivoHorarioTest.php
- tests/database/HorariosMigrationTest.php
- HORARIOS_ESTADISTICAS.md

## Validación local

```powershell
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-coverage
```

Las pruebas usan únicamente SQLite en memoria. La prueba de migración ejecuta
el archivo nuevo en una conexión independiente y verifica que una cámara previa
conserva sus datos y recibe indicadores false por defecto. No se ejecutan las
migraciones de la aplicación sobre PostgreSQL ni se usan servicios externos.
Los registros de pruebas no se insertan en la base real.

Resultado local: **42 pruebas, 224 aserciones, todas correctas**. Sintaxis PHP
verificada en los archivos nuevos y modificados; `git diff --check` sin errores.
No se realizó una prueba visual en navegador ni una ejecución contra PostgreSQL.

Se cubren horarios múltiples, límites, medianoche/domingo, zona horaria, pausa,
compatibilidad, contrato booleano, tokens, permisos, CSRF, selección de empresa,
rangos, ceros, clasificación, UTC, horario de verano y fracciones horarias.
La suite anterior conserva login/logout, fotos privadas, historial y uniformes.

## Despliegue posterior: requiere autorización

1. Revisar los cambios y respaldar PostgreSQL. Confirmar las zonas horarias de las
   empresas y los permisos actuales. Mantener secretos, volumen y configuración.
2. Probar en un entorno PostgreSQL de ensayo la migración nueva y la agregación.
   La validación automática local usa SQLite; no sustituye esa comprobación.
3. Preparar la publicación en el mismo servicio/proyecto Railway. Coordinar
   mantenimiento y aplicación de esquema antes de habilitar la nueva versión:
   el código nuevo requiere las dos columnas y la tabla de horarios.
4. Revisar pendientes con `php spark migrate:status`. Con autorización expresa,
   aplicar `php spark migrate` usando la conexión de producción prevista.
   Revisar todas las migraciones pendientes, no solo asumir que está pendiente
   la nueva. No usar refresh, rollback, seeds ni reconstrucciones de tablas.
5. La migración añade columnas booleanas false y una tabla vacía; no modifica
   los datos de detecciones ni fotos. Su down rechaza la eliminación automática
   de configuración; cualquier reversión de esquema necesita otro procedimiento.
6. Publicar únicamente cuando estén autorizados commit/push/despliegue. Si hay
   autodeploy, coordinarlo para que el push no adelante el cambio de esquema.
7. Verificar health, login, dashboard diario/horario, historial y fotos. Con una
   cámara existente sin horario, comprobar el GET con su token real de forma
   privada. Comprobar pausa/reactivación y rechazo de fotos fuera de horario.
   Con autorización operativa, verificar el ciclo real Gemini/foto/Telegram
   dentro de horario. El firmware no necesita cambios.

No se ha ejecutado ninguna migración en producción, commit, push ni despliegue.
