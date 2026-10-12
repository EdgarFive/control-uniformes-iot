<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateNucleoIoTTables extends Migration
{
    public function up()
    {
        // 1. Usuarios asociados a empresas
        $this->forge->addField([
            'id' => [
                'type' => 'INTEGER',
                'auto_increment' => true,
            ],
            'usuario_id' => [
                'type' => 'INTEGER',
            ],
            'empresa_id' => [
                'type' => 'INTEGER',
            ],
            'rol' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'consulta',
            ],
            'activo' => [
                'type' => 'BOOLEAN',
                'default' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['usuario_id', 'empresa_id'],
            'uq_usuario_empresa'
        );
        $this->forge->addForeignKey(
            'usuario_id', 'users', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->forge->addForeignKey(
            'empresa_id', 'empresas', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->forge->createTable('usuarios_empresas');

        // 2. Camaras ESP32-CAM
        $this->forge->addField([
            'id' => [
                'type' => 'INTEGER',
                'auto_increment' => true,
            ],
            'empresa_id' => [
                'type' => 'INTEGER',
            ],
            'sede_id' => [
                'type' => 'INTEGER',
                'null' => true,
            ],
            'nombre' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],
            'codigo' => [
                'type' => 'VARCHAR',
                'constraint' => 60,
            ],
            'token_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
            ],
            'estado' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'activo',
            ],
            'ultima_conexion' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['empresa_id', 'codigo'],
            'uq_dispositivo_codigo'
        );
        $this->forge->addUniqueKey('token_hash');

        $this->forge->addForeignKey(
            'empresa_id', 'empresas', 'id',
            'CASCADE', 'RESTRICT'
        );
        $this->forge->addForeignKey(
            'sede_id', 'sedes', 'id',
            'CASCADE', 'SET NULL'
        );
        $this->forge->createTable('dispositivos');

        // 3. Registros de detecciones
        $this->forge->addField([
            'id' => [
                'type' => 'INTEGER',
                'auto_increment' => true,
            ],
            'empresa_id' => [
                'type' => 'INTEGER',
            ],
            'dispositivo_id' => [
                'type' => 'INTEGER',
            ],
            'fecha' => [
                'type' => 'DATETIME',
            ],
            'foto_ruta' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'tamano_bytes' => [
                'type' => 'INTEGER',
                'null' => true,
            ],
            'clasificacion' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'pendiente',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('empresa_id');
        $this->forge->addKey('dispositivo_id');

        $this->forge->addForeignKey(
            'empresa_id', 'empresas', 'id',
            'CASCADE', 'RESTRICT'
        );
        $this->forge->addForeignKey(
            'dispositivo_id', 'dispositivos', 'id',
            'CASCADE', 'RESTRICT'
        );
        $this->forge->createTable('detecciones');
    }

    public function down()
    {
        $this->forge->dropTable('detecciones', true);
        $this->forge->dropTable('dispositivos', true);
        $this->forge->dropTable('usuarios_empresas', true);
    }
}
