<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Read-only diagnostics. Never prints credentials, tokens or user identities. */
class PanelCheck extends BaseCommand
{
    protected $group = 'Panel';
    protected $name = 'panel:check';
    protected $description = 'Comprueba conexión, esquema y asociaciones administrativas sin modificar datos.';

    public function run(array $params)
    {
        try {
            $db = db_connect();
            $db->query('SELECT 1');
            CLI::write('Conexión a la base de datos: OK', 'green');
            $required = [
                'empresas' => ['id', 'nombre', 'estado', 'zona_horaria'],
                'usuarios_empresas' => ['usuario_id', 'empresa_id', 'rol', 'activo'],
                'dispositivos' => ['id', 'empresa_id', 'sede_id', 'nombre', 'estado'],
                'sedes' => ['id', 'empresa_id', 'nombre'],
                'uniformes' => ['id', 'empresa_id', 'descripcion', 'activo', 'updated_at'],
                'detecciones' => ['id', 'empresa_id', 'dispositivo_id', 'fecha', 'foto_ruta', 'clasificacion', 'motivo', 'telegram_enviado'],
            ];
            $ok = true;
            foreach ($required as $table => $fields) {
                $missing = $db->tableExists($table) ? array_diff($fields, $db->getFieldNames($table)) : $fields;
                CLI::write($table . ': ' . ($missing ? 'faltan campos: ' . implode(', ', $missing) : 'OK'), $missing ? 'yellow' : 'green');
                $ok = $ok && $missing === [];
            }
            foreach (config('Auth')->tables as $table) {
                $exists = $db->tableExists($table);
                CLI::write('Shield / ' . $table . ': ' . ($exists ? 'OK' : 'tabla ausente'), $exists ? 'green' : 'yellow');
                $ok = $ok && $exists;
            }
            $settings = $db->tableExists('settings');
            CLI::write('Settings: ' . ($settings ? 'OK' : 'tabla ausente'), $settings ? 'green' : 'yellow');
            if ($ok) {
                $count = $db->table('usuarios_empresas ue')->join(config('Auth')->tables['groups_users'] . ' gu', 'gu.user_id = ue.usuario_id')
                    ->join('empresas e', 'e.id = ue.empresa_id')->where('ue.activo', true)->where('e.estado', 'activa')
                    ->whereIn('gu.group', ['admin', 'superadmin'])->countAllResults();
                CLI::write('Asociaciones activas con grupo admin/superadmin: ' . $count, $count ? 'green' : 'yellow');
            }
            $base = trim((string) env('PHOTO_STORAGE_DIR', ''));
            if ($base === '' && ENVIRONMENT !== 'production') {
                $base = WRITEPATH . 'uploads/detecciones';
            }
            CLI::write('Almacenamiento de fotografías: ' . ($base !== '' && is_dir($base) && is_readable($base) ? 'legible' : 'no disponible en este entorno'));
        } catch (\Throwable) {
            CLI::error('No se pudo completar la comprobación. Revisa localmente la conexión y el esquema; no se modificaron datos.');
        }
    }
}
