# Panel administrativo de Control de Uniformes IoT

Implementación dentro de CodeIgniter 4 y Shield, sin dependencias frontend ni compilación. El panel original no requería migraciones nuevas. La ampliación de horarios y estadísticas sí añade una migración y una validación en el receptor: consultar [HORARIOS_ESTADISTICAS.md](HORARIOS_ESTADISTICAS.md) para el comportamiento y despliegue vigentes. Se conservan `Auth.allowRegistration = false`, Gemini y Telegram.

## Funcionalidades

- Login con las credenciales existentes de Shield, CSRF y cierre de sesión mediante POST.
- Acceso exclusivo a grupos `admin` y `superadmin`, con validación de sesión, activación y bloqueo de Shield.
- Empresa seleccionable entre asociaciones activas de `usuarios_empresas` con empresas activas. **Superadmin también requiere asociación explícita**; no tiene acceso implícito a otras empresas.
- Resumen de incumplimientos de hoy, semana desde el lunes y mes; gráfica de los últimos 14 días y seis detecciones recientes. Solo `sin_uniforme` se contabiliza como incumplimiento.
- Historial paginado a 20 registros con filtros por fechas inclusivas y cámara; detalle con motivo y Telegram (enviado, no enviado o sin confirmar).
- Fotografías servidas únicamente por `/admin/detecciones/{id}/foto`, con autorización sobre la empresa del registro, validación de ruta real y cabeceras privadas sin caché. Sin rutas físicas en HTML, sin enlaces públicos al almacenamiento.
- Consulta de dispositivos, sede y última conexión disponible, sin mostrar tokens ni hashes. La última conexión se muestra tal como está registrada; el panel no cambia el comportamiento del receptor.
- Consulta de uniformes; edición exclusiva de la descripción por `superadmin` asociado o por `admin` con `usuarios_empresas.rol` igual a `admin` o `superadmin`. El rol `consulta` no permite editar. No se alteran activación, nombre ni detecciones históricas.
- Interfaz en español, adaptable a móvil, navegación por teclado, gráfica con valores y tabla accesible, sin CDN.

Las fechas de la API se interpretan en UTC y se convierten a `empresas.zona_horaria` para visualización y límites de días. Si la zona es inválida se utiliza `America/Guatemala`. No se estima cumplimiento ni número total de personas: solo se muestran registros existentes.

## Archivos

Modificados: `app/Config/Auth.php`, `app/Config/Routes.php`, `app/Config/Filters.php`.

Creados:

- `app/Controllers/Admin/PanelController.php`: consultas, selección de empresa, historial, detalle, fotografías y configuración.
- `app/Filters/PanelFilter.php`: sesión Shield, grupos y cabeceras privadas. Se desactivó el caché compartido de páginas para evitar respuestas administrativas anteriores a los filtros de autorización.
- `app/Libraries/PanelTime.php`, `app/Libraries/PanelPhoto.php`: fechas y resolución confinada de archivos.
- `app/Commands/PanelCheck.php`: diagnóstico de solo lectura, sin credenciales ni identidad de usuarios en la salida.
- `app/Views/auth/login.php`, `app/Views/admin/*.php`: vistas del panel.
- `public/assets/panel.css`: estilos locales responsive.
- `tests/feature/PanelTest.php`, `tests/unit/PanelSecurityTest.php`: pruebas de acceso, sesión, CSRF, aislamiento y fotografías.

## Comprobación local

Desde la raíz del proyecto:

```powershell
php spark panel:check
php spark routes
php -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-coverage
php spark serve --host localhost --port 8080
```

Si SQLite3 ya está habilitado, omite `-d extension=sqlite3`. Las pruebas usan únicamente la conexión `tests`, SQLite `:memory:`; ejecutan las migraciones de Shield/Settings en memoria y un esquema de prueba de las tablas del panel. No ejecutan migraciones de la aplicación sobre PostgreSQL. Ese esquema de prueba no verifica diferencias de DDL o comportamiento entre SQLite y PostgreSQL.

Abre `http://localhost:8080/index.php/login`. El proyecto conserva `App.indexPage` y las variables actuales; si ya configuraste reescritura e `indexPage` vacío, utiliza `/login`. La URL de inicio debe coincidir con tu `app.baseURL` local para conservar las cookies de sesión. No mezcles `localhost` con `127.0.0.1`.

Usa el administrador existente. Si recibes «Acceso restringido», revisa con el responsable de la base:

1. Usuario activo y no bloqueado en Shield.
2. Grupo `admin` o `superadmin` en `auth_groups_users`.
3. Asociación de ese mismo usuario con la empresa correcta en `usuarios_empresas`, con `activo = true`.
4. Empresa con `estado = 'activa'`.

No se asignan grupos ni asociaciones automáticamente. Requieren revisar el usuario y la empresa exactos y autorizar el cambio de permisos; la implementación no modifica estos datos.

Para revisar asociaciones sin modificar nada, ejecuta en tu cliente PostgreSQL:

```sql
SELECT u.id AS usuario_id, u.username, gu."group" AS grupo,
       e.id AS empresa_id, e.nombre AS empresa,
       ue.rol, ue.activo, e.estado
FROM users u
LEFT JOIN auth_groups_users gu ON gu.user_id = u.id
LEFT JOIN usuarios_empresas ue ON ue.usuario_id = u.id
LEFT JOIN empresas e ON e.id = ue.empresa_id
WHERE u.deleted_at IS NULL
ORDER BY u.id, e.id;
```

Para ver fotografías localmente necesitas acceso al almacenamiento real o una copia autorizada de las evidencias, conservando la estructura `{empresa_id}/{nombre_aleatorio}.jpg`. La aplicación usa `PHOTO_STORAGE_DIR`; sin esa variable, solo fuera de producción, busca en `writable/uploads/detecciones`. No copies fotos a `public/`. No se añadieron fotos de ejemplo ni registros ficticios a PostgreSQL.

Prueba manual: inicia sesión, revisa las tarjetas y la gráfica, abre el historial, filtra un día y una cámara, abre un detalle y su fotografía. Cierra sesión y vuelve a abrir la URL de esa foto: debe pedir login. Con un usuario asociado solo a otra empresa, el detalle y foto deben responder 404. Consulta uniformes y modifica su descripción solamente cuando corresponda al cambio que quieras aplicar a futuros análisis.

## Estado comprobado en esta sesión

- PHP 8.2: sintaxis válida en los archivos revisados.
- Suite: 23 pruebas, 106 aserciones, todas correctas.
- Rutas administrativas con `panel` y `csrf`; registro público ausente; API sin cambio de rutas ni filtros CSRF.
- Base configurada: conexión y todas las tablas/columnas requeridas presentes.
- **Pendiente de permisos:** el diagnóstico encontró cero asociaciones activas que combinen empresa activa con grupo `admin`/`superadmin`. La existencia de una cuenta no basta; revisar la consulta anterior antes de cambiar datos.
- **Pendiente local:** el directorio de fotografías no está disponible/legible en este entorno. Esto no comprueba ni invalida el volumen ya utilizado en Railway.
- No se pudo realizar inspección visual con navegador: esta sesión no tiene navegadores conectados. Las vistas fueron renderizadas en las pruebas HTTP de las páginas; queda la revisión visual manual en escritorio y móvil.
- No se probó el login del administrador real porque no se utilizaron sus credenciales. El ciclo de login/logout sí se comprobó con Shield y un usuario aislado de prueba.

## Publicación en Railway, únicamente cuando la autorices

1. Revisa el diff y los archivos nuevos. Este trabajo no ha hecho commit, push ni despliegue.
2. Resuelve el permiso del administrador sobre la empresa prevista. Comprueba `php spark panel:check` en el entorno correspondiente. No ejecutes seeds de demostración ni `migrate:refresh`, rollback o reconstrucción de tablas.
3. Reutiliza el servicio, PostgreSQL, volumen persistente y configuración de arranque existentes. No necesitas otro proyecto, base de datos ni proceso frontend.
4. Conserva los secretos actuales. Revisa de forma privada que `CI_ENVIRONMENT=production`, que `app.baseURL` corresponde al dominio HTTPS y que `PHOTO_STORAGE_DIR` apunta al directorio existente dentro del volumen montado. El usuario del proceso PHP necesita lectura de esas fotografías; `writable` debe seguir siendo escribible para las sesiones.
5. Tras aprobar la publicación, incluye los archivos del panel en el commit y publica a la rama que ya utiliza el servicio. Si esa rama tiene autodeploy activo, un push puede iniciar el despliegue automáticamente: compruébalo antes de publicar. [Documentación de autodeploy de Railway](https://docs.railway.com/deployments/github-autodeploys).
6. El panel original no añadía migraciones. La ampliación de horarios requiere aplicar la migración aditiva nueva antes de habilitar su código; sigue [HORARIOS_ESTADISTICAS.md](HORARIOS_ESTADISTICAS.md). Comprueba también `motivo` y `telegram_enviado` con `panel:check`.
7. Espera a que finalice el despliegue y prueba `/api/health`, `/login`, `/admin`, historial, detalle y fotografía con la cuenta real. Comprueba que una sesión cerrada no puede consultar fotografías. Verifica recepción de la ESP32-CAM con su flujo habitual.

Si necesitas ajustar variables de Railway, sus cambios se preparan y requieren aplicarse mediante un despliegue; no cambies variables que ya funcionan sin necesidad. [Documentación de variables de Railway](https://docs.railway.com/variables).
