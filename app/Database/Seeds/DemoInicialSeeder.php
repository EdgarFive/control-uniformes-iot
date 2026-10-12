<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\CLI\CLI;

class DemoInicialSeeder extends Seeder
{
    public function run()
    {
        $db = db_connect();

        // 1. Registrar empresa de prueba
        $empresa = $db->table('empresas')
            ->where('slug', 'empresa-demo')
            ->get()
            ->getRowArray();

        if (!$empresa) {
            $db->table('empresas')->insert([
                'nombre'        => 'Empresa de Prueba',
                'slug'          => 'empresa-demo',
                'estado'        => 'activa',
                'zona_horaria'  => 'America/Guatemala',
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);

            $empresaId = (int) $db->insertID();
        } else {
            $empresaId = (int) $empresa['id'];
        }

        // 2. Registrar uniforme autorizado
        $uniforme = $db->table('uniformes')
            ->where('empresa_id', $empresaId)
            ->where('nombre', 'Uniforme de Prueba')
            ->get()
            ->getRowArray();

        if (!$uniforme) {
            $db->table('uniformes')->insert([
                'empresa_id'  => $empresaId,
                'nombre'      => 'Uniforme de Prueba',
                'descripcion' => 'Camisa blanca y pantalón negro',
                'activo'      => true,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        }

        // 3. Comprobar si ya existe la cámara
        $dispositivo = $db->table('dispositivos')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'ESP32CAM-001')
            ->get()
            ->getRowArray();

        if ($dispositivo) {
            CLI::write(
                'La cámara ya está registrada. No se modificó su token.',
                'yellow'
            );
            return;
        }

        // 4. Generar una clave privada para la ESP32-CAM
        $token = bin2hex(random_bytes(32));

        $db->table('dispositivos')->insert([
            'empresa_id'      => $empresaId,
            'sede_id'         => null,
            'nombre'          => 'ESP32-CAM Entrada Principal',
            'codigo'          => 'ESP32CAM-001',
            'token_hash'      => hash('sha256', $token),
            'estado'          => 'activo',
            'ultima_conexion' => null,
        ]);

        CLI::write('Empresa y uniforme preparados.', 'green');
        CLI::write('Dispositivo registrado correctamente.', 'green');

        CLI::write(
            'TOKEN PRIVADO DE LA ESP32-CAM:',
            'yellow'
        );

        CLI::write($token);
        CLI::write(
            'Guarda este token. No se mostrará nuevamente.',
            'yellow'
        );
    }
}
