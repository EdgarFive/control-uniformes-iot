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
            'detecciones' => 'id INTEGER PRIMARY KEY, empresa_id INTEGER, dispositivo_id INTEGER, fecha TEXT, clasificacion TEXT, motivo TEXT, telegram_enviado BOOLEAN, foto_ruta TEXT',
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
        foreach (['admin', 'admin/detecciones', 'admin/detecciones/1', 'admin/detecciones/1/foto', 'admin/dispositivos', 'admin/uniformes'] as $url) {
            $this->get($url)->assertRedirectTo(site_url('login'));
        }
    }

    public function testLoginIsAvailableAndRegistrationIsAbsent(): void
    {
        $this->get('login')->assertSee('Iniciar sesión');
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
        $this->assertSame(3, substr_count($result->response()->getBody(), '<strong>1</strong>'));
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
        foreach (['admin', 'admin/detecciones', 'admin/detecciones/1', 'admin/dispositivos', 'admin/uniformes'] as $url) {
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
        foreach (['admin/detecciones/2', 'admin/detecciones/2/foto', 'admin?empresa=2', 'admin/uniformes?empresa=2'] as $url) {
            $this->assertNotFound($url);
        }
    }

    public function testSuperadminAlsoNeedsExplicitMembership(): void
    {
        $this->actingAs($this->admin('superadmin', false));
        $this->get('admin')->assertStatus(403);
        $this->assertNotFound('admin/detecciones/1/foto');
    }

    public function testOrdinaryUserIsDenied(): void
    {
        $this->actingAs($this->admin('user'));
        $this->get('admin')->assertStatus(403);
        $this->get('admin/detecciones/1/foto')->assertStatus(403);
    }

    public function testRevokedMembershipImmediatelyRemovesAccess(): void
    {
        $this->actingAs($this->admin());
        $this->get('admin')->assertOK();
        $this->db->table('usuarios_empresas')->where('empresa_id', 1)->update(['activo' => false]);
        $this->get('admin')->assertStatus(403);
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
}
