<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\UniformeIAService;

class DeteccionesController extends BaseController
{
    public function health()
    {
        try {
            $db = db_connect();
            $db->query('SELECT 1');

            return $this->response->setJSON([
                'ok' => true,
                'sistema' => 'Control de Uniformes IoT',
                'base_datos' => 'conectada',
            ]);
        } catch (\Throwable $e) {
            log_message('error', $e->getMessage());

            return $this->error(
                503,
                'Base de datos no disponible'
            );
        }
    }

    public function recibir()
    {
        // 1. Verificar token del dispositivo
        $token = trim(
            $this->request->getHeaderLine('X-Device-Token')
        );

        if ($token === '') {
            return $this->error(401, 'Token requerido');
        }

        try {
            $db = db_connect();

            $dispositivo = $db->table('dispositivos')
                ->where('token_hash', hash('sha256', $token))
                ->where('estado', 'activo')
                ->get()
                ->getRowArray();

            if (!$dispositivo) {
                return $this->error(
                    401,
                    'Dispositivo no autorizado'
                );
            }

            // 2. Identificar la empresa
            $empresaId = (int) $dispositivo['empresa_id'];

            $empresa = $db->table('empresas')
                ->where('id', $empresaId)
                ->where('estado', 'activa')
                ->get()
                ->getRowArray();

            if (!$empresa) {
                return $this->error(
                    403,
                    'Empresa no disponible'
                );
            }

            // 3. Consultar uniforme autorizado
            $uniformes = $db->table('uniformes')
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->limit(2)
                ->get()
                ->getResultArray();

            if (count($uniformes) !== 1) {
                return $this->error(
                    409,
                    'La empresa necesita exactamente un uniforme activo para esta prueba'
                );
            }

        } catch (\Throwable $e) {
            log_message('error', $e->getMessage());

            return $this->error(
                503,
                'No se pudo consultar la configuración'
            );
        }

        // 4. Validar fotografía JPEG
        $tipo = strtolower(trim(explode(
            ';',
            $this->request->getHeaderLine('Content-Type')
        )[0]));

        if ($tipo !== 'image/jpeg') {
            return $this->error(
                415,
                'Se requiere una fotografía JPEG'
            );
        }

        $imagen = $this->request->getBody();
        $tamano = strlen($imagen);

        if ($tamano < 4 || $tamano > 2 * 1024 * 1024) {
            return $this->error(
                422,
                'Tamaño de fotografía no permitido'
            );
        }

        // Comprobar las firmas de inicio y fin del archivo JPEG.
        $inicioValido = substr($imagen, 0, 3) === "\xFF\xD8\xFF";
        $finalValido  = substr($imagen, -2) === "\xFF\xD9";

        $datosImagen = @getimagesizefromstring($imagen);

        // Registrar información técnica sin guardar la fotografía.
        if ($datosImagen === false) {
            log_message(
                'warning',
                'getimagesizefromstring no reconoció el JPEG. Bytes: ' . $tamano
            );
        }

        if (
            !$inicioValido
            || !$finalValido
            || (
                $datosImagen !== false
                && ($datosImagen['mime'] ?? '') !== 'image/jpeg'
            )
        ) {
            log_message(
                'error',
                'JPEG rechazado. Bytes: ' . $tamano
                . ', inicio: ' . bin2hex(substr($imagen, 0, 4))
                . ', final: ' . bin2hex(substr($imagen, -4))
            );

            return $this->error(
                422,
                'Fotografía JPEG inválida'
            );
        }

        // 5. Analizar primero con Gemini
        try {
            $ia = new UniformeIAService();

            $resultado = $ia->analizar(
                $imagen,
                $uniformes[0]['descripcion']
            );
        } catch (\Throwable $e) {
            log_message('error', 'Fallo de IA: ' . $e->getMessage());

            return $this->error(
                502,
                'No fue posible analizar la fotografía'
            );
        }

        $clasificacion = $resultado['clasificacion'];

        // 6. Con uniforme o no concluyente: descartar
        if ($clasificacion !== 'sin_uniforme') {
            return $this->response->setJSON([
                'ok' => true,
                'clasificacion' => $clasificacion,
                'motivo' => $resultado['motivo'],
                'foto_guardada' => false,
            ]);
        }

        // 7. Sin uniforme: preparar almacenamiento
        $base = trim(
            (string) env('PHOTO_STORAGE_DIR', '')
        );

        // Evitar almacenamiento temporal en producción
        if ($base === '') {
            if (ENVIRONMENT === 'production') {
                return $this->error(
                    503,
                    'Almacenamiento persistente no configurado'
                );
            }

            $base = WRITEPATH . 'uploads/detecciones';
        }

        $directorio = rtrim($base, '/\\')
            . DIRECTORY_SEPARATOR
            . $empresaId;

        $ruta = null;

        try {
            if (
                !is_dir($directorio)
                && !mkdir($directorio, 0750, true)
                && !is_dir($directorio)
            ) {
                throw new \RuntimeException(
                    'No se pudo crear el directorio'
                );
            }

            $nombreArchivo = bin2hex(random_bytes(16)) . '.jpg';
            $ruta = $directorio
                . DIRECTORY_SEPARATOR
                . $nombreArchivo;

            if (file_put_contents($ruta, $imagen, LOCK_EX)
                !== $tamano) {
                throw new \RuntimeException(
                    'No se pudo guardar la fotografía'
                );
            }

            // 8. Registrar únicamente el incumplimiento
            $guardado = $db->table('detecciones')->insert([
                'empresa_id' => $empresaId,
                'dispositivo_id' => (int) $dispositivo['id'],
                'fecha' => gmdate('Y-m-d H:i:s'),
                'foto_ruta' => $empresaId . '/' . $nombreArchivo,
                'tamano_bytes' => $tamano,
                'clasificacion' => 'sin_uniforme',
            ]);

            if (!$guardado) {
                throw new \RuntimeException(
                    'No se pudo registrar la detección'
                );
            }

            return $this->response
                ->setStatusCode(201)
                ->setJSON([
                    'ok' => true,
                    'deteccion_id' => (int) $db->insertID(),
                    'clasificacion' => 'sin_uniforme',
                    'motivo' => $resultado['motivo'],
                    'foto_guardada' => true,
                ]);

        } catch (\Throwable $e) {
            if ($ruta !== null && is_file($ruta)) {
                unlink($ruta);
            }

            log_message('error', $e->getMessage());

            return $this->error(
                500,
                'No se pudo guardar la detección'
            );
        }
    }

    private function error(int $codigo, string $mensaje)
    {
        return $this->response
            ->setStatusCode($codigo)
            ->setJSON([
                'ok' => false,
                'mensaje' => $mensaje,
            ]);
    }
}
