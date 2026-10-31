<?php

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** All fixtures and migrations run exclusively on the tests SQLite :memory: database. */
final class PanelTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = ['CodeIgniter\Settings', 'CodeIgniter\Shield'];

    private ?string $analysisStorage = null;
    private string|false $previousStorage = false;

    protected function tearDown(): void
    {
        \Config\Services::resetSingle('uniformeIA');
        \Config\Services::resetSingle('telegram');
        if ($this->analysisStorage !== null) {
            putenv($this->previousStorage === false ? 'PHOTO_STORAGE_DIR' : 'PHOTO_STORAGE_DIR=' . $this->previousStorage);
            // Only the unique test directory created by useAnalysisStorage is removed.
            foreach (glob($this->analysisStorage . '/*') as $directory) {
                foreach (glob($directory . '/*') as $file) unlink($file);
                rmdir($directory);
            }
            rmdir($this->analysisStorage);
        }
        parent::tearDown();
    }

    private function useAnalysisStorage(): string
    {
        $this->previousStorage = getenv('PHOTO_STORAGE_DIR');
        $this->analysisStorage = WRITEPATH . 'testing/analysis-' . bin2hex(random_bytes(6));
        mkdir($this->analysisStorage, 0700, true);
        putenv('PHOTO_STORAGE_DIR=' . $this->analysisStorage);
        return $this->analysisStorage;
    }

    private function analysisJpeg(): string
    {
        // JPEG metadata fixture for the receiver; Gemini is always mocked.
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xC0\x00\x11\x08\x00\x01\x00\x01\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00\xFF\xD9";
    }

    private function analysisDependencies(string $classification): void
    {
        $ia = $this->createMock(\App\Services\UniformeIAService::class);
        $ia->expects($this->once())->method('analizar')->with($this->analysisJpeg(), 'Camisa blanca')
            ->willReturn(['clasificacion' => $classification, 'motivo' => 'Motivo del análisis de prueba']);
        $telegram = $this->createMock(\App\Services\TelegramService::class);
        $telegram->expects($this->never())->method('enviarFoto');
        \Config\Services::injectMock('uniformeIA', $ia);
        \Config\Services::injectMock('telegram', $telegram);
    }

    private function postAnalysis()
    {
        return $this->withHeaders(['X-Device-Token' => '1', 'Content-Type' => 'image/jpeg'])
            ->withBody($this->analysisJpeg())->post('api/detecciones');
    }

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('Run PHP with -d extension=sqlite3.');
        }
        parent::setUp();
        $this->assertSame(':memory:', $this->db->database);
        // PostgreSQL's named foreign keys in the application migrations are not
        // supported by SQLite. Mirror only the columns used by the panel here.
        $schema = [
            'empresas' => 'id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT, estado TEXT, zona_horaria TEXT',
            'usuarios_empresas' => 'usuario_id INTEGER, empresa_id INTEGER, rol TEXT, activo BOOLEAN',
            'sedes' => 'id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT',
            'dispositivos' => 'id INTEGER PRIMARY KEY, empresa_id INTEGER, sede_id INTEGER, nombre TEXT, codigo TEXT, token_hash TEXT, estado TEXT, ultima_conexion TEXT, deteccion_pausada BOOLEAN DEFAULT 0, horario_configurado BOOLEAN DEFAULT 0',
            'dispositivo_horarios' => 'id INTEGER PRIMARY KEY, dispositivo_id INTEGER, dia INTEGER, inicio TEXT, fin TEXT',
            'detecciones' => 'id INTEGER PRIMARY KEY, empresa_id INTEGER, dispositivo_id INTEGER, fecha TEXT, clasificacion TEXT, motivo TEXT, telegram_enviado BOOLEAN, foto_ruta TEXT, tamano_bytes INTEGER',
            'uniformes' => 'id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, descripcion TEXT, activo BOOLEAN, updated_at TEXT',
        ];
        foreach ($schema as $table => $columns) {
            $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->db->prefixTable($table) . ' (' . $columns . ')');
            $this->db->table($table)->emptyTable();
        }
        $this->db->table('empresas')->insertBatch([
            ['id' => 1, 'nombre' => 'Empresa autorizada', 'slug' => 'autorizada', 'estado' => 'activa', 'zona_horaria' => 'America/Guatemala'],
            ['id' => 2, 'nombre' => 'Empresa ajena', 'slug' => 'ajena', 'estado' => 'activa', 'zona_horaria' => 'America/Guatemala'],
        ]);
        foreach ([1, 2] as $company) {
            $this->db->table('dispositivos')->insert(['id' => $company, 'empresa_id' => $company, 'nombre' => 'Cámara ' . $company, 'codigo' => 'CAM-' . $company, 'token_hash' => hash('sha256', (string) $company), 'estado' => 'activo']);
            $this->db->table('detecciones')->insert(['id' => $company, 'empresa_id' => $company, 'dispositivo_id' => $company, 'fecha' => gmdate('Y-m-d H:i:s'), 'clasificacion' => 'sin_uniforme', 'motivo' => $company === 1 ? 'Motivo autorizado' : 'MOTIVO PRIVADO AJENO', 'telegram_enviado' => $company === 1, 'foto_ruta' => $company . '/' . str_repeat('a', 32) . '.jpg']);
            $this->db->table('uniformes')->insert(['id' => $company, 'empresa_id' => $company, 'nombre' => 'Uniforme ' . $company, 'descripcion' => 'Camisa blanca', 'activo' => true]);
        }
    }

    private function admin(string $group = 'admin', bool $membership = true, string $role = 'admin'): User
    {
        $model = new UserModel();
        $user = new User(['username' => 'adminprueba', 'email' => 'admin@example.test', 'password' => 'PruebaFuerte!423', 'active' => 1]);
        $model->save($user);
        $user = $model->findById($model->getInsertID());
        $user->addGroup($group);
        if ($membership) {
            $this->db->table('usuarios_empresas')->insert(['usuario_id' => $user->id, 'empresa_id' => 1, 'rol' => $role, 'activo' => true]);
        }
        return $user;
    }

    private function assertNotFound(string $url): void
    {
        try {
            $this->get($url)->assertStatus(404);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testGuestsCannotReadAnyAdministrativePageOrPhoto(): void
    {
        foreach (['admin', 'admin/estadisticas', 'admin/detecciones', 'admin/detecciones/1', 'admin/detecciones/1/foto', 'admin/dispositivos', 'admin/uniformes'] as $url) {
            $this->get($url)->assertRedirectTo(site_url('login'));
        }
    }

    public function testLoginIsAvailableAndRegistrationIsAbsent(): void
    {
        $login = $this->get('login');
        $login->assertSee('Iniciar sesión');
        $this->assertStringContainsString('<title>Iniciar sesión · Uniform</title>', $login->response()->getBody());
        $login->assertDontSee('Control de Uniformes IoT');
        $this->assertNotFound('register');
        $this->assertNotFound('logout');
    }

    public function testExistingShieldAccountCanLoginAndLogout(): void
    {
        $this->admin();
        $this->get('login')->assertOK();
        $this->withSession($_SESSION)->post('login', [
            'email' => 'admin@example.test', 'password' => 'PruebaFuerte!423', csrf_token() => csrf_hash(),
        ])->assertRedirectTo(site_url('admin'));
        $this->assertTrue(auth('session')->loggedIn());
        $this->withSession($_SESSION)->post('logout', [csrf_token() => csrf_hash()])->assertRedirectTo(site_url('login'));
        $this->assertFalse(auth('session')->loggedIn());
    }

    public function testLoginWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('login', ['email' => 'admin@example.test', 'password' => 'invalid']);
    }

    public function testEsp32EndpointStillUsesDeviceTokenWithoutCsrf(): void
    {
        $this->post('api/detecciones')->assertStatus(401);
    }

    public function testDashboardCountsOnlyActualCompanyNoncompliance(): void
    {
        $this->actingAs($this->admin());
        $this->db->table('detecciones')->insert(['id' => 3, 'empresa_id' => 1, 'dispositivo_id' => 1, 'fecha' => gmdate('Y-m-d H:i:s'), 'clasificacion' => 'no_concluyente']);
        $result = $this->get('admin');
        $result->assertOK();
        $existingIndicators = explode('<section class="card">', $result->response()->getBody(), 2)[0];
        $this->assertSame(3, substr_count($existingIndicators, '<strong>1</strong>'));
    }

    public function testUniformEditIsScopedAndCsrfProtected(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin/uniformes')->assertOK();
        $this->withSession($_SESSION)->post('admin/uniformes/1?empresa=1', [
            'descripcion' => 'Camisa azul de prueba', csrf_token() => csrf_hash(),
        ])->assertRedirect();
        $this->assertSame('Camisa azul de prueba', $this->db->table('uniformes')->where('id', 1)->get()->getRowArray()['descripcion']);
        $this->assertSame('Camisa blanca', $this->db->table('uniformes')->where('id', 2)->get()->getRowArray()['descripcion']);
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withSession($_SESSION)->post('admin/uniformes/2?empresa=1', [
            'descripcion' => 'Intento ajeno', csrf_token() => csrf_hash(),
        ]);
    }

    public function testReadOnlyMembershipCannotEditUniform(): void
    {
        $this->actingAs($this->admin('admin', true, 'consulta'));
        $this->get('admin/uniformes')->assertOK();
        $this->withSession($_SESSION)->post('admin/uniformes/1?empresa=1', [
            'descripcion' => 'Intento sin permiso', csrf_token() => csrf_hash(),
        ])->assertStatus(403);
        $this->assertSame('Camisa blanca', $this->db->table('uniformes')->where('id', 1)->get()->getRowArray()['descripcion']);
    }

    public function testAdminOnlySeesOwnCompanyAcrossPages(): void
    {
        $this->actingAs($this->admin());
        foreach (['admin', 'admin/estadisticas', 'admin/detecciones', 'admin/detecciones/1', 'admin/dispositivos', 'admin/uniformes'] as $url) {
            $result = $this->get($url);
            $result->assertOK();
            $result->assertSee('Empresa autorizada');
            $result->assertDontSee('MOTIVO PRIVADO AJENO');
            $result->assertDontSee('Empresa ajena');
        }
    }

    public function testOtherCompanyDetailPhotoAndSelectorAreNotAccessible(): void
    {
        $this->actingAs($this->admin());
        foreach (['admin/detecciones/2', 'admin/detecciones/2/foto', 'admin?empresa=2', 'admin/estadisticas?empresa=2', 'admin/uniformes?empresa=2'] as $url) {
            $this->assertNotFound($url);
        }
    }

    public function testSuperadminAlsoNeedsExplicitMembership(): void
    {
        $this->actingAs($this->admin('superadmin', false));
        $this->get('admin')->assertStatus(403);
        $this->get('admin/estadisticas')->assertStatus(403);
        $this->assertNotFound('admin/detecciones/1/foto');
    }

    public function testOrdinaryUserIsDenied(): void
    {
        $this->actingAs($this->admin('user'));
        $this->get('admin')->assertStatus(403);
        $this->get('admin/estadisticas')->assertStatus(403);
        $this->get('admin/detecciones/1/foto')->assertStatus(403);
    }

    public function testRevokedMembershipImmediatelyRemovesAccess(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin')->assertOK();
        $this->db->table('usuarios_empresas')->where('empresa_id', 1)->update(['activo' => false]);
        $this->get('admin')->assertStatus(403);
        $this->get('admin/estadisticas')->assertStatus(403);
        $this->assertNotFound('admin/detecciones/1');
    }

    public function testInvalidDatesAndForeignDeviceDoNotLeakRows(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin/detecciones?desde=2026-02-30')->assertSee('Introduce fechas válidas.');
        $result = $this->get('admin/detecciones?dispositivo=2');
        $result->assertOK();
        $result->assertDontSee('MOTIVO PRIVADO AJENO');
        $result->assertDontSee('Motivo autorizado');
    }

    public function testProtectedPhotoHasPrivateHeadersAndExactBytes(): void
    {
        $this->actingAs($this->admin());
        $base = WRITEPATH . 'testing/photos-' . bin2hex(random_bytes(6));
        mkdir($base . '/1', 0700, true);
        $name = str_repeat('a', 32) . '.jpg';
        file_put_contents($base . '/1/' . $name, "\xFF\xD8\xFFfixture\xFF\xD9");
        $previous = getenv('PHOTO_STORAGE_DIR');
        putenv('PHOTO_STORAGE_DIR=' . $base);
        try {
            $result = $this->get('admin/detecciones/1/foto');
            $result->assertOK();
            $result->assertHeader('Cache-Control', 'private, no-store, max-age=0');
            $result->assertHeader('Content-Type', 'image/jpeg');
            $this->assertSame("\xFF\xD8\xFFfixture\xFF\xD9", $result->response()->getBody());
        } finally {
            putenv($previous === false ? 'PHOTO_STORAGE_DIR' : 'PHOTO_STORAGE_DIR=' . $previous);
            unlink($base . '/1/' . $name);
            rmdir($base . '/1');
            rmdir($base);
        }
    }

    public function testDeviceConfigurationContractAndManualPause(): void
    {
        foreach (['' => 401, 'invalid' => 401, '1' => 200] as $token => $status) {
            $result = $this->withHeaders(['X-Device-Token' => (string) $token])->get('api/dispositivos/configuracion');
            $result->assertStatus($status);
            $body = json_decode($result->response()->getBody(), true);
            $this->assertSame(['ok' => $status === 200, 'deteccion_habilitada' => $status === 200], $body);
            $result->assertHeader('Cache-Control', 'private, no-store, max-age=0');
        }
        $this->db->table('dispositivos')->where('id', 1)->update(['deteccion_pausada' => true]);
        $result = $this->withHeaders(['X-Device-Token' => '1'])->get('api/dispositivos/configuracion');
        $result->assertOK();
        $this->assertSame(['ok' => true, 'deteccion_habilitada' => false], json_decode($result->response()->getBody(), true));
    }

    public function testInactiveDeviceAndCompanyCannotObtainEnabledConfiguration(): void
    {
        $this->db->table('dispositivos')->where('id', 1)->update(['estado' => 'inactivo']);
        $this->withHeaders(['X-Device-Token' => '1'])->get('api/dispositivos/configuracion')->assertStatus(401);
        $this->db->table('empresas')->where('id', 2)->update(['estado' => 'inactiva']);
        $this->withHeaders(['X-Device-Token' => '2'])->get('api/dispositivos/configuracion')->assertStatus(403);
    }

    public function testConfiguredEmptyScheduleBlocksPhotoBeforeJpegAndUniformChecks(): void
    {
        $this->db->table('dispositivos')->where('id', 1)->update(['horario_configurado' => true]);
        $this->db->table('uniformes')->where('empresa_id', 1)->delete();
        $this->withHeaders(['X-Device-Token' => '1'])->get('api/dispositivos/configuracion')->assertJSONFragment(['ok' => true, 'deteccion_habilitada' => false]);
        $before = $this->db->table('detecciones')->countAllResults();
        $this->withHeaders(['X-Device-Token' => '1'])->post('api/detecciones')->assertStatus(403);
        $this->assertSame($before, $this->db->table('detecciones')->countAllResults());
        $this->db->table('dispositivos')->where('id', 1)->update(['horario_configurado' => false]);
        $this->withHeaders(['X-Device-Token' => '1'])->post('api/detecciones')->assertStatus(409);
    }

    public function testPolicyUsesCompanyTimezoneAndPauseOverridesSchedule(): void
    {
        $this->db->table('dispositivo_horarios')->insert(['dispositivo_id' => 1, 'dia' => 1, 'inicio' => '23:00:00', 'fin' => '23:59:00']);
        $device = ['id' => 1, 'estado' => 'activo', 'horario_configurado' => 't'];
        $service = new \App\Services\DispositivoHorarioService();
        $now = new DateTimeImmutable('2026-10-27 05:30:00', new DateTimeZone('UTC'));
        $this->assertTrue($service->habilitada($device, 'America/Guatemala', $now));
        $this->assertFalse($service->habilitada($device, 'UTC', $now));
        $this->assertFalse($service->habilitada($device + ['deteccion_pausada' => true], 'America/Guatemala', $now));
    }

    public function testScheduleSaveAndPauseAreScopedAndPreserveEachOther(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin/dispositivos')->assertOK();
        $csrf = [csrf_token() => csrf_hash()];
        $this->withSession($_SESSION)->post('admin/dispositivos/1/pausa?empresa=1', ['pausada' => '1'] + $csrf)->assertRedirect();
        $this->withSession($_SESSION)->post('admin/dispositivos/1/horarios?empresa=1', [
            'horario_configurado' => '1', 'periodos' => [['dia' => '1', 'inicio' => '08:00', 'fin' => '12:00'], ['dia' => '1', 'inicio' => '14:00', 'fin' => '18:00']],
        ] + [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame(2, $this->db->table('dispositivo_horarios')->where('dispositivo_id', 1)->countAllResults());
        $device = $this->db->table('dispositivos')->where('id', 1)->get()->getRowArray();
        $this->assertTrue(\App\Services\DispositivoHorarioService::boolean($device['deteccion_pausada']));
        $this->assertTrue(\App\Services\DispositivoHorarioService::boolean($device['horario_configurado']));
        $this->withSession($_SESSION)->post('admin/dispositivos/1/pausa?empresa=1', ['pausada' => '0', csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame(2, $this->db->table('dispositivo_horarios')->where('dispositivo_id', 1)->countAllResults());
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withSession($_SESSION)->post('admin/dispositivos/2/horarios?empresa=1', ['horario_configurado' => '1', csrf_token() => csrf_hash()]);
    }

    public function testInvalidSchedulePreservesExistingConfiguration(): void
    {
        $this->actingAs($this->admin());
        $this->db->table('dispositivo_horarios')->insert(['dispositivo_id' => 1, 'dia' => 1, 'inicio' => '08:00:00', 'fin' => '12:00:00']);
        $this->get('admin/dispositivos');
        $this->withSession($_SESSION)->post('admin/dispositivos/1/horarios', ['horario_configurado' => '1', 'periodos' => [['dia' => '8']], csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame(1, $this->db->table('dispositivo_horarios')->where('dispositivo_id', 1)->countAllResults());
        $this->assertFalse(\App\Services\DispositivoHorarioService::boolean($this->db->table('dispositivos')->where('id', 1)->get()->getRowArray()['horario_configurado']));
    }

    public function testReadOnlyMembershipCannotChangeScheduleOrPause(): void
    {
        $this->actingAs($this->admin('admin', true, 'consulta'));
        $result = $this->get('admin/dispositivos');
        $result->assertOK();
        $result->assertDontSee('Guardar horario');
        foreach (['horarios', 'pausa'] as $action) {
            $this->withSession($_SESSION)->post('admin/dispositivos/1/' . $action, [csrf_token() => csrf_hash()])->assertStatus(403);
        }
    }

    public function testScheduleRequiresCsrf(): void
    {
        $this->actingAs($this->admin());
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('admin/dispositivos/1/horarios', ['horario_configurado' => '1']);
    }

    public function testHourlyAggregationIncludesZerosLocalBoundariesAndTenantScope(): void
    {
        foreach ([
            [10, 1, '2026-10-26 05:59:59', 'sin_uniforme'],
            [11, 1, '2026-10-26 06:00:00', 'sin_uniforme'],
            [12, 1, '2026-10-27 05:59:59', 'sin_uniforme'],
            [13, 1, '2026-10-27 06:00:00', 'sin_uniforme'],
            [14, 1, '2026-10-29 06:00:00', 'sin_uniforme'],
            [15, 2, '2026-10-26 06:00:00', 'sin_uniforme'],
            [16, 1, '2026-10-26 06:30:00', 'no_concluyente'],
        ] as [$id, $company, $date, $classification]) {
            $this->db->table('detecciones')->insert(['id' => $id, 'empresa_id' => $company, 'dispositivo_id' => $company, 'fecha' => $date, 'clasificacion' => $classification]);
        }
        // Remove only initial isolated fixtures, keeping the exact test timeline.
        $this->db->table('detecciones')->where('id <', 10)->delete();
        $series = (new \App\Services\EstadisticasService())->porHora(1, 'America/Guatemala', '2026-10-26', '2026-10-28');
        $this->assertCount(3, $series);
        foreach ($series as $hours) $this->assertCount(24, $hours);
        $this->assertSame(1, $series['2026-10-26'][0]);
        $this->assertSame(1, $series['2026-10-26'][23]);
        $this->assertSame(1, $series['2026-10-27'][0]);
        $this->assertSame(0, array_sum($series['2026-10-28']));
        $this->assertSame(3, array_sum(array_map('array_sum', $series)));
        $this->actingAs($this->admin());
        $result = $this->get('admin?vista=hora&desde=2026-10-26&hasta=2026-10-28');
        $result->assertOK();
        $result->assertSee('Total del período: 3');
        $result->assertSee('23:00');
        $result->assertSee('00:00');
    }

    public function testHourlyFiltersAndAuthorization(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin?vista=hora&desde=2026-10-26&hasta=2026-10-29')->assertSee('Selecciona entre uno y tres días');
        $this->get('admin?vista=hora&desde=2026-02-30&hasta=2026-02-30')->assertSee('Introduce fechas válidas');
        $this->get('admin?vista=hora&desde=2026-10-27&hasta=2026-10-26')->assertSee('Selecciona entre uno y tres días');
        $this->get('admin?vista[]=hora')->assertStatus(400);
        $this->assertNotFound('admin?empresa=2&vista=hora&desde=2026-10-26&hasta=2026-10-26');
    }

    public function testHourlyAggregationHandlesDstAndFractionalOffset(): void
    {
        $this->db->table('detecciones')->where('empresa_id', 1)->delete();
        foreach (['2026-11-01 05:30:00', '2026-11-01 06:30:00'] as $date) {
            $this->db->table('detecciones')->insert(['empresa_id' => 1, 'dispositivo_id' => 1, 'fecha' => $date, 'clasificacion' => 'sin_uniforme']);
        }
        $service = new \App\Services\EstadisticasService();
        $series = $service->porHora(1, 'America/New_York', '2026-11-01', '2026-11-01');
        $this->assertSame(2, $series['2026-11-01'][1]);
        $series = $service->porHora(1, 'Asia/Kathmandu', '2026-11-01', '2026-11-01');
        $this->assertSame(1, $series['2026-11-01'][11]);
        $this->assertSame(1, $series['2026-11-01'][12]);
    }

    public function testSelectedCompanyControlsSchedulesAndHourlyDashboard(): void
    {
        $user = $this->admin();
        $this->db->table('usuarios_empresas')->insert(['usuario_id' => $user->id, 'empresa_id' => 2, 'rol' => 'consulta', 'activo' => true]);
        $this->actingAs($user);
        $result = $this->get('admin/dispositivos?empresa=2');
        $result->assertSee('Cámara 2');
        $result->assertDontSee('Cámara 1');
        $this->withSession($_SESSION)->post('admin/dispositivos/2/pausa?empresa=2', ['pausada' => '1', csrf_token() => csrf_hash()])->assertStatus(403);
        $result = $this->get('admin?vista=hora&empresa=2');
        $result->assertOK();
        $result->assertSee('Total del período: 1');
        $result->assertDontSee('Motivo autorizado');
    }

    public function testPausedPhotosAreBlockedAndUnconfiguredCameraKeepsJpegValidation(): void
    {
        $this->db->table('dispositivos')->where('id', 1)->update(['deteccion_pausada' => true]);
        $this->withHeaders(['X-Device-Token' => '1'])->post('api/detecciones')->assertStatus(403);
        $this->db->table('dispositivos')->where('id', 1)->update(['deteccion_pausada' => false]);
        $this->withHeaders(['X-Device-Token' => '1'])->post('api/detecciones')->assertStatus(415);
        $this->withHeaders(['X-Device-Token' => '1', 'Content-Type' => 'image/jpeg'])->post('api/detecciones')->assertStatus(422);
        $this->get('api/health')->assertJSONFragment(['ok' => true, 'base_datos' => 'conectada']);
    }

    public function testPauseRequiresCsrfAndRevokedMembershipCannotSaveSchedules(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin/dispositivos');
        $this->db->table('usuarios_empresas')->where('empresa_id', 1)->update(['activo' => false]);
        $this->withSession($_SESSION)->post('admin/dispositivos/1/horarios', ['horario_configurado' => '1', csrf_token() => csrf_hash()])->assertStatus(403);
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('admin/dispositivos/1/pausa', ['pausada' => '1']);
    }

    public function testUniformBrandAndStatisticsNavigation(): void
    {
        $this->actingAs($this->admin());
        $result = $this->get('admin/estadisticas?empresa=1');
        $result->assertOK();
        $body = $result->response()->getBody();
        $this->assertStringContainsString('<title>Estadísticas · Uniform</title>', $body);
        $this->assertStringContainsString('<span>Uniform<span class="brand-sub">', $body);
        $result->assertDontSee('Uniformes IoT');
        $result->assertSee('Este mes');
        $result->assertSee('Incumplimientos por día');
        $result->assertDontSee('Últimas detecciones');
        preg_match('~<nav aria-label="Navegación principal">(.*?)</nav>~s', $body, $nav);
        $previous = -1;
        foreach (['Resumen', 'Estadísticas', 'Detecciones', 'Dispositivos', 'Uniformes'] as $label) {
            $position = strpos($nav[1], $label . '</a>');
            $this->assertNotFalse($position);
            $this->assertGreaterThan($previous, $position);
            $previous = $position;
        }
        $this->assertMatchesRegularExpression('~class="nav-link active"[^>]*aria-current="page"[^>]*href="[^"]*/admin/estadisticas\?empresa=1"~', $nav[1]);
    }

    public function testStatisticsAndSummaryReuseIdenticalDailyAndHourlyContent(): void
    {
        $this->actingAs($this->admin());
        $day = (new DateTimeImmutable('now', new DateTimeZone('America/Guatemala')))->format('Y-m-d');
        foreach (['dia', 'hora'] as $mode) {
            $query = '?empresa=1&vista=' . $mode . '&desde=' . $day . '&hasta=' . $day;
            $summary = $this->get('admin' . $query);
            $summary->assertOK();
            $summary->assertSee('Últimas detecciones');
            $statistics = $this->get('admin/estadisticas' . $query);
            $statistics->assertOK();
            foreach ([$summary, $statistics] as $index => $result) {
                preg_match('~<div class="stats-grid period-cards">.*?</section>~s', $result->response()->getBody(), $content);
                $this->assertNotEmpty($content);
                // Compare chart content independently of selector options.
                $rendered[$index] = preg_replace('~<select id="vista".*?</select>~s', '', $content[0]);
            }
            $this->assertSame($rendered[0], $rendered[1]);
        }
    }

    public function testStatisticsRetainsHourlyFiltersValidationAndCompanyPermissions(): void
    {
        $this->actingAs($this->admin('admin', true, 'consulta'));
        $result = $this->get('admin/estadisticas?empresa=1&vista=hora&desde=2026-10-26&hasta=2026-10-28');
        $result->assertOK();
        $result->assertSee('Incumplimientos por hora');
        $result->assertSee('America/Guatemala');
        $result->assertSee('2026-10-26');
        $result->assertSee('2026-10-28');
        $result->assertSee('00:00');
        $result->assertSee('23:00');
        $this->get('admin/estadisticas?vista=hora&desde=2026-10-26&hasta=2026-10-29')->assertSee('Selecciona entre uno y tres días');
        $this->get('admin/estadisticas?vista=hora&desde=2026-02-30&hasta=2026-02-30')->assertSee('Introduce fechas válidas');
        $this->get('admin/estadisticas?vista[]=hora')->assertStatus(400);
        $this->assertNotFound('admin/estadisticas?empresa=2');
    }

    public function testMonthlyCountsTwelveMonthsWithUtcBoundsYearsAndZeros(): void
    {
        foreach ([
            [100, 1, '2025-02-01 05:59:59', 'sin_uniforme'],
            [101, 1, '2025-02-01 06:00:00', 'sin_uniforme'],
            [102, 1, '2026-01-01 05:59:59', 'sin_uniforme'],
            [103, 1, '2026-01-01 06:00:00', 'sin_uniforme'],
            [104, 1, '2026-01-15 18:00:00', 'sin_uniforme'],
            [105, 1, '2026-01-15 18:00:01', 'sin_uniforme'],
            [106, 2, '2026-01-01 06:00:00', 'sin_uniforme'],
            [107, 1, '2026-01-01 06:00:00', 'no_concluyente'],
            [108, 1, '2025-01-15 18:00:00', 'sin_uniforme'],
            [109, 1, '2026-02-01 06:00:00', 'sin_uniforme'],
        ] as [$id, $company, $date, $classification]) {
            $this->db->table('detecciones')->insert(['id' => $id, 'empresa_id' => $company, 'dispositivo_id' => $company, 'fecha' => $date, 'clasificacion' => $classification]);
        }
        $this->db->table('detecciones')->where('id <', 100)->delete();
        $now = new DateTimeImmutable('2026-01-15 18:00:00', new DateTimeZone('UTC'));
        $service = new \App\Services\EstadisticasService();
        $months = $service->porMes(1, 'America/Guatemala', $now);
        $this->assertCount(12, $months);
        $this->assertSame('2025-02', array_key_first($months));
        $this->assertSame('2026-01', array_key_last($months));
        $this->assertSame('Febrero 2025', $months['2025-02']['label']);
        $this->assertSame('Enero 2026', $months['2026-01']['label']);
        $this->assertSame(1, $months['2025-02']['total']);
        $this->assertSame(1, $months['2025-12']['total']);
        $this->assertSame(2, $months['2026-01']['total']);
        $this->assertSame(0, $months['2025-03']['total']);
        $this->assertSame(4, array_sum(array_column($months, 'total')));
        $utcMonths = $service->porMes(1, 'UTC', $now);
        $this->assertSame(3, $utcMonths['2026-01']['total']);
        $this->assertSame(2, $utcMonths['2025-02']['total']);
    }

    public function testMonthlyCurrentMonthUsesCompanyZoneAndEmptyMonthsStayZero(): void
    {
        $this->db->table('detecciones')->where('empresa_id', 1)->delete();
        $now = new DateTimeImmutable('2026-01-01 03:00:00', new DateTimeZone('UTC'));
        $service = new \App\Services\EstadisticasService();
        $localMonths = $service->porMes(1, 'America/Guatemala', $now);
        $utcMonths = $service->porMes(1, 'UTC', $now);
        $this->assertSame('2025-01', array_key_first($localMonths));
        $this->assertSame('2025-12', array_key_last($localMonths));
        $this->assertSame('2026-01', array_key_last($utcMonths));
        $this->assertSame(array_fill(0, 12, 0), array_column($localMonths, 'total'));
    }

    public function testMonthlyPageSelectorAndTotalsAreAvailableInStatisticsAndSummary(): void
    {
        $this->actingAs($this->admin('admin', true, 'consulta'));
        // Hourly date limits do not constrain the fixed 12-month monthly view.
        $result = $this->get('admin/estadisticas?vista=mes&empresa=1&desde=2026-01-01&hasta=2026-12-31');
        $result->assertOK();
        $result->assertSee('Incumplimientos por mes');
        $result->assertSee('Total del período: 1');
        $result->assertSee('Ver valores por mes');
        $result->assertSee('America/Guatemala');
        $body = $result->response()->getBody();
        $this->assertSame(12, substr_count($body, 'class="chart-count"'));
        $this->assertMatchesRegularExpression('~<select id="vista"[^>]*>.*?Por hora.*?Por día.*?value="mes" selected>Por mes~s', $body);
        $summary = $this->get('admin?empresa=1');
        $summary->assertOK();
        $summary->assertSee('Últimas detecciones');
        $summary->assertSee('Incumplimientos por día');
        $this->assertStringContainsString('value="mes"', $summary->response()->getBody());
        $monthlySummary = $this->get('admin?vista=mes&empresa=1');
        $monthlySummary->assertOK();
        $monthlySummary->assertSee('Incumplimientos por mes');
        $monthlySummary->assertSee('Total del período: 1');
        $monthlySummary->assertSee('Últimas detecciones');
        $this->assertSame(12, substr_count($monthlySummary->response()->getBody(), 'class="chart-count"'));
        $this->assertNotFound('admin?vista=mes&empresa=2');
        $this->assertNotFound('admin/estadisticas?vista=mes&empresa=2');
        $this->db->table('usuarios_empresas')->where('empresa_id', 1)->update(['activo' => false]);
        $this->get('admin/estadisticas?vista=mes')->assertStatus(403);
        $this->get('admin?vista=mes')->assertStatus(403);
    }

    private function assertAnalysisWithoutPhoto(string $classification): void
    {
        $base = $this->useAnalysisStorage();
        $history = $this->db->table('detecciones')->orderBy('id')->get()->getResultArray();
        $this->analysisDependencies($classification);
        $beforeUtc = gmdate('Y-m-d H:i:s');
        $result = $this->postAnalysis();
        $result->assertStatus(200);
        $this->assertSame([
            'ok' => true, 'clasificacion' => $classification,
            'motivo' => 'Motivo del análisis de prueba', 'foto_guardada' => false,
        ], json_decode($result->response()->getBody(), true));
        $row = $this->db->table('detecciones')->where('id', 3)->get()->getRowArray();
        $this->assertSame($classification, $row['clasificacion']);
        $this->assertSame('Motivo del análisis de prueba', $row['motivo']);
        $this->assertSame(1, (int) $row['empresa_id']);
        $this->assertSame(1, (int) $row['dispositivo_id']);
        $this->assertNull($row['foto_ruta']);
        $this->assertNull($row['tamano_bytes']);
        $this->assertNull($row['telegram_enviado']);
        $this->assertGreaterThanOrEqual($beforeUtc, $row['fecha']);
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $row['fecha']);
        $this->assertSame([], glob($base . '/*'));
        $this->assertSame($history, $this->db->table('detecciones')->where('id <=', 2)->orderBy('id')->get()->getResultArray());
    }

    public function testConUniformeIsStoredWithoutPhotoOrTelegramAndKeepsHttpContract(): void
    {
        $this->assertAnalysisWithoutPhoto('con_uniforme');
    }

    public function testNoConcluyenteIsStoredWithoutPhotoOrTelegramAndKeepsHttpContract(): void
    {
        $this->assertAnalysisWithoutPhoto('no_concluyente');
    }

    public function testSinUniformeStillStoresPhotoBeforeTelegramAndKeepsHttpContract(): void
    {
        $base = $this->useAnalysisStorage();
        $history = $this->db->table('detecciones')->orderBy('id')->get()->getResultArray();
        $ia = $this->createMock(\App\Services\UniformeIAService::class);
        $ia->expects($this->once())->method('analizar')->with($this->analysisJpeg(), 'Camisa blanca')
            ->willReturn(['clasificacion' => 'sin_uniforme', 'motivo' => 'Incumplimiento de prueba']);
        $telegram = $this->createMock(\App\Services\TelegramService::class);
        $telegram->expects($this->once())->method('enviarFoto')->willReturnCallback(function ($path, $id, $company, $device, $reason) use ($base) {
            $this->assertSame($this->analysisJpeg(), file_get_contents($path));
            $row = $this->db->table('detecciones')->where('id', $id)->get()->getRowArray();
            $this->assertSame('sin_uniforme', $row['clasificacion']);
            $this->assertSame(str_replace('\\', '/', $base . '/' . $row['foto_ruta']), str_replace('\\', '/', $path));
            $this->assertSame('Empresa autorizada', $company);
            $this->assertSame('Cámara 1', $device);
            $this->assertSame('Incumplimiento de prueba', $reason);
            return true;
        });
        \Config\Services::injectMock('uniformeIA', $ia);
        \Config\Services::injectMock('telegram', $telegram);
        $result = $this->postAnalysis();
        $result->assertStatus(201);
        $this->assertSame([
            'ok' => true, 'deteccion_id' => 3, 'clasificacion' => 'sin_uniforme',
            'motivo' => 'Incumplimiento de prueba', 'foto_guardada' => true, 'telegram_enviado' => true,
        ], json_decode($result->response()->getBody(), true));
        $row = $this->db->table('detecciones')->where('id', 3)->get()->getRowArray();
        $this->assertSame(strlen($this->analysisJpeg()), (int) $row['tamano_bytes']);
        $this->assertTrue(\App\Services\DispositivoHorarioService::boolean($row['telegram_enviado']));
        $this->assertCount(1, glob($base . '/1/*'));
        $this->assertSame($history, $this->db->table('detecciones')->where('id <=', 2)->orderBy('id')->get()->getResultArray());
    }

    public function testGeminiTechnicalFailureDoesNotStoreAnythingOrNotify(): void
    {
        $base = $this->useAnalysisStorage();
        $history = $this->db->table('detecciones')->orderBy('id')->get()->getResultArray();
        $ia = $this->createMock(\App\Services\UniformeIAService::class);
        $ia->expects($this->once())->method('analizar')->willThrowException(new RuntimeException('Gemini HTTP 503'));
        $telegram = $this->createMock(\App\Services\TelegramService::class);
        $telegram->expects($this->never())->method('enviarFoto');
        \Config\Services::injectMock('uniformeIA', $ia);
        \Config\Services::injectMock('telegram', $telegram);
        $this->postAnalysis()->assertStatus(502);
        $this->assertSame($history, $this->db->table('detecciones')->orderBy('id')->get()->getResultArray());
        $this->assertSame([], glob($base . '/*'));
    }

    public function testPausedOrOutOfScheduleJpegNeverAnalyzesOrStores(): void
    {
        $base = $this->useAnalysisStorage();
        $history = $this->db->table('detecciones')->orderBy('id')->get()->getResultArray();
        $ia = $this->createMock(\App\Services\UniformeIAService::class);
        $ia->expects($this->never())->method('analizar');
        $telegram = $this->createMock(\App\Services\TelegramService::class);
        $telegram->expects($this->never())->method('enviarFoto');
        \Config\Services::injectMock('uniformeIA', $ia);
        \Config\Services::injectMock('telegram', $telegram);
        foreach ([['deteccion_pausada' => true, 'horario_configurado' => false], ['deteccion_pausada' => false, 'horario_configurado' => true]] as $state) {
            $this->db->table('dispositivos')->where('id', 1)->update($state);
            $this->postAnalysis()->assertStatus(403);
        }
        $this->assertSame($history, $this->db->table('detecciones')->orderBy('id')->get()->getResultArray());
        $this->assertSame([], glob($base . '/*'));
    }

    public function testFailedMetadataInsertCannotReturnSuccessfulAnalysis(): void
    {
        $this->useAnalysisStorage();
        $this->analysisDependencies('con_uniforme');
        $this->db->query('CREATE TEMP TRIGGER reject_analysis BEFORE INSERT ON ' . $this->db->prefixTable('detecciones') . " BEGIN SELECT RAISE(FAIL, 'isolated insert failure'); END");
        try {
            $this->postAnalysis()->assertStatus(500);
            $this->assertSame(2, $this->db->table('detecciones')->countAllResults());
        } finally {
            $this->db->query('DROP TRIGGER reject_analysis');
        }
    }

    public function testAnalysisStatisticsUseConclusiveDenominatorAndLocalDateScope(): void
    {
        foreach ([
            [100, 1, '2026-10-26 06:00:00', 'con_uniforme'],
            [101, 1, '2026-10-26 06:30:00', 'con_uniforme'],
            [102, 1, '2026-10-27 05:59:59', 'sin_uniforme'],
            [103, 1, '2026-10-27 05:00:00', 'no_concluyente'],
            [104, 2, '2026-10-26 06:00:00', 'con_uniforme'],
            [105, 1, '2026-10-27 06:00:00', 'con_uniforme'],
            [106, 1, '2026-10-26 05:59:59', 'con_uniforme'],
            [107, 1, '2026-10-26 06:00:00', 'pendiente'],
        ] as [$id, $company, $date, $classification]) {
            $this->db->table('detecciones')->insert(['id' => $id, 'empresa_id' => $company, 'dispositivo_id' => $company, 'fecha' => $date, 'clasificacion' => $classification]);
        }
        $zone = \App\Libraries\PanelTime::zone('America/Guatemala');
        $start = \App\Libraries\PanelTime::day('2026-10-26', $zone);
        $data = (new \App\Services\EstadisticasService())->analisis(1, $start, $start->modify('+1 day'));
        $this->assertSame(['con_uniforme' => 2, 'sin_uniforme' => 1, 'no_concluyente' => 1, 'total' => 4, 'concluyentes' => 3, 'cumplimiento' => 66.67, 'incumplimiento' => 33.33], $data);
        $this->actingAs($this->admin());
        $result = $this->get('admin/estadisticas?vista=hora&desde=2026-10-26&hasta=2026-10-26');
        $result->assertOK();
        $result->assertSee('Incumplimiento entre concluyentes');
        $result->assertSee('33,33 %');
        $result->assertSee('1 sin uniforme / 3 análisis concluyentes');
        $result->assertSee('Total del período: 1'); // Existing infringement chart remains unchanged.
        $result->assertDontSee('Antes de esta actualización solo se almacenaban incumplimientos');
        $compliant = $this->get('admin/estadisticas?vista=hora&desde=2026-10-26&hasta=2026-10-26&clasificacion=con_uniforme');
        $compliant->assertSee('Cumplimiento entre concluyentes');
        $compliant->assertSee('66,67 %');
        $compliant->assertSee('2 con uniforme / 3 análisis concluyentes');
    }

    public function testNoConclusiveAnalysesShowNdAndLegacyRowsAreNotReconstructed(): void
    {
        $service = new \App\Services\EstadisticasService();
        $start = new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable('2100-01-01', new DateTimeZone('UTC'));
        $legacy = $service->analisis(1, $start, $end);
        $this->assertSame(0, $legacy['con_uniforme']);
        $this->assertSame(1, $legacy['sin_uniforme']);
        $this->assertSame(0.0, $legacy['cumplimiento']);
        $this->assertSame(100.0, $legacy['incumplimiento']);
        $this->db->table('detecciones')->where('empresa_id', 1)->update(['clasificacion' => 'no_concluyente', 'foto_ruta' => null]);
        $this->assertNull($service->analisis(1, $start, $end)['cumplimiento']);
        $this->assertNull($service->analisis(1, $start, $end)['incumplimiento']);
        $this->actingAs($this->admin());
        $result = $this->get('admin/estadisticas?vista=mes');
        $result->assertSee('N/D');
        $result->assertSee('0 sin uniforme / 0 análisis concluyentes');
        $this->get('admin/estadisticas?vista=mes&clasificacion=con_uniforme')->assertSee('N/D');
        $empty = $service->analisis(1, $end, $end->modify('+1 day'));
        $this->assertSame(0, $empty['total']);
        $this->assertNull($empty['cumplimiento']);
        $this->assertNull($empty['incumplimiento']);
    }

    public function testConclusivePercentagesComplementEachOtherAndIgnoreInconclusiveRows(): void
    {
        $this->db->table('detecciones')->emptyTable();
        $service = new \App\Services\EstadisticasService();
        $start = new DateTimeImmutable('2026-10-26', new DateTimeZone('UTC'));
        $end = $start->modify('+1 day');
        $insert = function (string $classification): void {
            $this->db->table('detecciones')->insert(['empresa_id' => 1, 'dispositivo_id' => 1,
                'fecha' => '2026-10-26 12:00:00', 'clasificacion' => $classification]);
        };
        $insert('con_uniforme');
        $onlyCompliant = $service->analisis(1, $start, $end);
        $this->assertSame(100.0, $onlyCompliant['cumplimiento']);
        $this->assertSame(0.0, $onlyCompliant['incumplimiento']);
        for ($i = 0; $i < 31; $i++) {
            $insert('sin_uniforme');
        }
        $before = $service->analisis(1, $start, $end);
        $this->assertSame(3.13, $before['cumplimiento']);
        $this->assertSame(96.87, $before['incumplimiento']);
        $this->assertEqualsWithDelta(100.0, $before['cumplimiento'] + $before['incumplimiento'], 0.00001);
        for ($i = 0; $i < 50; $i++) {
            $insert('no_concluyente');
        }
        $after = $service->analisis(1, $start, $end);
        $this->assertSame(82, $after['total']);
        $this->assertSame(50, $after['no_concluyente']);
        $this->assertSame(32, $after['concluyentes']);
        $this->assertSame($before['cumplimiento'], $after['cumplimiento']);
        $this->assertSame($before['incumplimiento'], $after['incumplimiento']);
    }

    public function testHistoryShowsAllClassificationsWithoutBrokenPhotoLinks(): void
    {
        $this->db->table('detecciones')->insertBatch([
            ['id' => 3, 'empresa_id' => 1, 'dispositivo_id' => 1, 'fecha' => gmdate('Y-m-d H:i:s'), 'clasificacion' => 'con_uniforme', 'motivo' => 'Motivo con uniforme', 'foto_ruta' => null],
            ['id' => 4, 'empresa_id' => 1, 'dispositivo_id' => 1, 'fecha' => gmdate('Y-m-d H:i:s'), 'clasificacion' => 'no_concluyente', 'motivo' => 'Motivo no concluyente', 'foto_ruta' => null],
        ]);
        $this->actingAs($this->admin());
        $result = $this->get('admin/detecciones');
        $result->assertSee('Con uniforme');
        $result->assertSee('Sin uniforme');
        $result->assertSee('No concluyente');
        $result->assertDontSee('MOTIVO PRIVADO AJENO');
        foreach ([3, 4] as $id) {
            $detail = $this->get('admin/detecciones/' . $id);
            $detail->assertOK();
            $detail->assertSee('Sin fotografía registrada');
            $detail->assertSee('No aplica');
            $this->assertStringNotContainsString('/detecciones/' . $id . '/foto', $detail->response()->getBody());
            $this->assertNotFound('admin/detecciones/' . $id . '/foto');
        }
        $this->get('admin/detecciones/1')->assertSee('Fotografía de la detección');
        $this->assertNotFound('admin/detecciones/2');
    }

    public function testAnalysisTotalsFollowDailyHourlyAndMonthlyChartPeriods(): void
    {
        $this->actingAs($this->admin());
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/Guatemala')))->setTime(0, 0);
        foreach ([
            [100, $today, 'con_uniforme'],
            [101, $today->modify('-20 days'), 'no_concluyente'],
            [102, $today->modify('-400 days'), 'con_uniforme'],
            [103, $today->modify('+1 day'), 'con_uniforme'],
            [104, $today->modify('-14 days'), 'con_uniforme'],
        ] as [$id, $date, $classification]) {
            $this->db->table('detecciones')->insert(['id' => $id, 'empresa_id' => 1, 'dispositivo_id' => 1, 'fecha' => \App\Libraries\PanelTime::utc($date), 'clasificacion' => $classification]);
        }
        foreach (['dia' => [2, 1], 'hora' => [2, 1], 'mes' => [4, 2]] as $mode => [$expected, $compliantCount]) {
            $url = 'admin/estadisticas?vista=' . $mode . '&desde=' . $today->format('Y-m-d') . '&hasta=' . $today->format('Y-m-d');
            $result = $this->get($url);
            $result->assertOK();
            preg_match('~data-analysis="total".*?<strong>([0-9,]+)</strong>~s', $result->response()->getBody(), $total);
            $this->assertSame((string) $expected, $total[1]);
            $denominator = $compliantCount + 1;
            $result->assertSee('1 sin uniforme / ' . $denominator . ' análisis concluyentes');
            $result->assertSee($mode === 'mes' ? '33,33 %' : '50,00 %');
            $compliant = $this->get($url . '&clasificacion=con_uniforme');
            $compliant->assertSee($compliantCount . ' con uniforme / ' . $denominator . ' análisis concluyentes');
            $compliant->assertSee($mode === 'mes' ? '66,67 %' : '50,00 %');
        }
    }

    public function testTelegramFailureDoesNotLoseStoredInfringementOrPhoto(): void
    {
        $base = $this->useAnalysisStorage();
        $ia = $this->createMock(\App\Services\UniformeIAService::class);
        $ia->expects($this->once())->method('analizar')->willReturn(['clasificacion' => 'sin_uniforme', 'motivo' => 'Incumplimiento de prueba']);
        $telegram = $this->createMock(\App\Services\TelegramService::class);
        $telegram->expects($this->once())->method('enviarFoto')->willThrowException(new RuntimeException('Telegram no disponible'));
        \Config\Services::injectMock('uniformeIA', $ia);
        \Config\Services::injectMock('telegram', $telegram);
        $result = $this->postAnalysis();
        $result->assertStatus(201);
        $result->assertJSONFragment(['ok' => true, 'clasificacion' => 'sin_uniforme', 'foto_guardada' => true, 'telegram_enviado' => false]);
        $row = $this->db->table('detecciones')->where('id', 3)->get()->getRowArray();
        $this->assertNotNull($row['foto_ruta']);
        $this->assertFalse(\App\Services\DispositivoHorarioService::boolean($row['telegram_enviado']));
        $this->assertSame($this->analysisJpeg(), file_get_contents($base . '/' . $row['foto_ruta']));
    }

    public function testConUniformeChartsAndDefaultSinUniformeWorkInAllModesAndPages(): void
    {
        foreach ([[100, 1, 'con_uniforme'], [101, 1, 'con_uniforme'], [102, 1, 'no_concluyente'], [103, 2, 'con_uniforme']] as [$id, $company, $classification]) {
            $this->db->table('detecciones')->insert(['id' => $id, 'empresa_id' => $company, 'dispositivo_id' => $company, 'fecha' => gmdate('Y-m-d H:i:s'), 'clasificacion' => $classification]);
        }
        $this->actingAs($this->admin());
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/Guatemala')))->format('Y-m-d');
        foreach (['admin', 'admin/estadisticas'] as $page) {
            foreach (['dia' => 'día', 'hora' => 'hora', 'mes' => 'mes'] as $mode => $label) {
                $query = '?empresa=1&vista=' . $mode . '&desde=' . $today . '&hasta=' . $today;
                $compliant = $this->get($page . $query . '&clasificacion=con_uniforme');
                $compliant->assertOK();
                $compliant->assertSee('Con uniforme por ' . $label);
                $compliant->assertSee('Cumplimiento entre concluyentes');
                $compliant->assertSee('66,67 %');
                preg_match_all('~<span class="chart-count">(\d+)</span>~', $compliant->response()->getBody(), $values);
                $this->assertSame(2, array_sum(array_map('intval', $values[1])));
                $this->assertStringContainsString('value="con_uniforme" selected', $compliant->response()->getBody());
                // The original period indicators always count infringements.
                $cards = explode('<section class="card">', $compliant->response()->getBody(), 2)[0];
                $this->assertSame(3, substr_count($cards, '<strong>1</strong>'));
                $default = $this->get($page . $query);
                $default->assertOK();
                $default->assertSee('Incumplimientos por ' . $label);
                $default->assertSee('Incumplimiento entre concluyentes');
                $default->assertSee('33,33 %');
                $body = $default->response()->getBody();
                preg_match_all('~<span class="chart-count">(\d+)</span>~', $body, $values);
                $this->assertSame(1, array_sum(array_map('intval', $values[1])));
                preg_match('~<select id="clasificacion"[^>]*>(.*?)</select>~s', $body, $selector);
                $this->assertStringContainsString('value="sin_uniforme" selected', $selector[1]);
                $this->assertStringNotContainsString('no_concluyente', $selector[1]);
            }
        }
    }

    public function testConUniformeHourlyAndMonthlyQueriesPreserveLocalBoundariesAndZeros(): void
    {
        foreach ([[100, 1, '2026-10-26 05:59:59'], [101, 1, '2026-10-26 06:00:00'], [102, 1, '2026-10-27 05:59:59'], [103, 1, '2026-10-27 06:00:00'], [104, 2, '2026-10-26 06:00:00']] as [$id, $company, $date]) {
            $this->db->table('detecciones')->insert(['id' => $id, 'empresa_id' => $company, 'dispositivo_id' => $company, 'fecha' => $date, 'clasificacion' => 'con_uniforme']);
        }
        $service = new \App\Services\EstadisticasService();
        $hours = $service->porHora(1, 'America/Guatemala', '2026-10-26', '2026-10-26', 'con_uniforme');
        $this->assertCount(24, $hours['2026-10-26']);
        $this->assertSame(1, $hours['2026-10-26'][0]);
        $this->assertSame(1, $hours['2026-10-26'][23]);
        $this->assertSame(0, $hours['2026-10-26'][1]);
        $this->assertSame(2, array_sum($hours['2026-10-26']));
        $months = $service->porMes(1, 'America/Guatemala', new DateTimeImmutable('2026-10-27 05:59:59', new DateTimeZone('UTC')), 'con_uniforme');
        $this->assertCount(12, $months);
        $this->assertSame(3, $months['2026-10']['total']);
        $this->assertSame(0, $months['2026-09']['total']);
    }

    public function testChartClassificationRejectsUnsupportedValuesAndForeignCompanies(): void
    {
        $this->actingAs($this->admin());
        foreach (['admin', 'admin/estadisticas'] as $page) {
            foreach (['no_concluyente', 'invalid'] as $classification) {
                $this->get($page . '?clasificacion=' . $classification)->assertStatus(400);
            }
            $this->get($page . '?clasificacion[]=con_uniforme')->assertStatus(400);
            $this->assertNotFound($page . '?empresa=2&clasificacion=con_uniforme');
        }
        $service = new \App\Services\EstadisticasService();
        foreach (['mes', 'hora'] as $mode) {
            try {
                if ($mode === 'mes') $service->porMes(1, 'UTC', null, 'no_concluyente');
                else $service->porHora(1, 'UTC', '2026-10-26', '2026-10-26', 'no_concluyente');
                $this->fail('Unsupported chart classification accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
