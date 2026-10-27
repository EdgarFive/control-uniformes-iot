<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\DispositivoHorarioService;

class DispositivosController extends BaseController
{
    public function configuracion()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store, max-age=0');
        $token = trim($this->request->getHeaderLine('X-Device-Token'));
        if ($token === '') {
            return $this->respuesta(401, false, false);
        }
        try {
            $db = db_connect();
            $device = $db->table('dispositivos')->where('token_hash', hash('sha256', $token))
                ->where('estado', 'activo')->get()->getRowArray();
            if (! $device) {
                return $this->respuesta(401, false, false);
            }
            $company = $db->table('empresas')->where('id', $device['empresa_id'])->where('estado', 'activa')->get()->getRowArray();
            if (! $company) {
                return $this->respuesta(403, false, false);
            }
            return $this->respuesta(200, true, (new DispositivoHorarioService())->habilitada($device, $company['zona_horaria']));
        } catch (\Throwable $e) {
            log_message('error', 'Configuración de dispositivo: ' . $e->getMessage());
            return $this->respuesta(503, false, false);
        }
    }

    private function respuesta(int $status, bool $ok, bool $enabled)
    {
        return $this->response->setStatusCode($status)->setJSON(['ok' => $ok, 'deteccion_habilitada' => $enabled]);
    }
}
