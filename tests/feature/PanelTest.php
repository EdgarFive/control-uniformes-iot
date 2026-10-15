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
            'dispositivos' => 'id INTEGER PRIMARY KEY, empresa_id INTEGER, sede_id INTEGER, nombre TEXT, codigo TEXT, token_hash TEXT, estado TEXT, ultima_conexion TEXT',
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
}
