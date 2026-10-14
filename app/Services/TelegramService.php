<?php

namespace App\Services;

class TelegramService
{
    public function enviarFoto(
        string $ruta,
        int $deteccionId,
        string $empresa,
        string $dispositivo,
        string $motivo
    ): bool {
        $token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
        $chatId = trim((string) env('TELEGRAM_CHAT_ID', ''));

        if ($token === '' || $chatId === '') {
            log_message('error', 'Telegram: faltan variables de configuración.');
            return false;
        }

        if (!is_file($ruta) || !is_readable($ruta)) {
            log_message('error', 'Telegram: fotografía no disponible.');
            return false;
        }

        $mensaje = "🚨 INCUMPLIMIENTO DE UNIFORME\n\n"
            . "Empresa: {$empresa}\n"
            . "Cámara: {$dispositivo}\n"
            . "Detección: #{$deteccionId}\n\n"
            . "Motivo: {$motivo}";

        // Telegram admite un máximo de 1024 caracteres
        // en la descripción de una fotografía.
        if (strlen($mensaje) > 900) {
            $mensaje = "🚨 INCUMPLIMIENTO DE UNIFORME\n\n"
                . "Empresa: {$empresa}\n"
                . "Cámara: {$dispositivo}\n"
                . "Detección: #{$deteccionId}";
        }

        $conexion = curl_init();

        if ($conexion === false) {
            log_message('error', 'Telegram: no se pudo iniciar cURL.');
            return false;
        }

        curl_setopt_array($conexion, [
            CURLOPT_URL => 'https://api.telegram.org/bot'
                . $token . '/sendPhoto',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'caption' => $mensaje,
                'photo' => new \CURLFile(
                    $ruta,
                    'image/jpeg',
                    'deteccion-' . $deteccionId . '.jpg'
                ),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
        ]);

        $respuesta = curl_exec($conexion);
        $codigo = (int) curl_getinfo(
            $conexion,
            CURLINFO_HTTP_CODE
        );
        $errorCurl = curl_error($conexion);

        curl_close($conexion);

        $datos = is_string($respuesta)
            ? json_decode($respuesta, true)
            : null;

        if (
            $codigo !== 200
            || !is_array($datos)
            || ($datos['ok'] ?? false) !== true
        ) {
            log_message(
                'error',
                'Telegram: HTTP ' . $codigo
                . ' - ' . ($datos['description'] ?? $errorCurl)
            );

            return false;
        }

        return true;
    }
}
