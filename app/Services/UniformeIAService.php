<?php

namespace App\Services;

class UniformeIAService
{
    private const MODELO = 'gemini-3.5-flash-lite';
    /**
     * Analiza una fotografía sin almacenarla.
     *
     * @return array{clasificacion: string, motivo: string}
     */
    public function analizar(
        string $imagenJpeg,
        string $descripcionUniforme
    ): array {
        $apiKey = trim((string) env('GEMINI_API_KEY', ''));

        if ($apiKey === '') {
            throw new \RuntimeException(
                'GEMINI_API_KEY no está configurada.'
            );
        }

        if (trim($descripcionUniforme) === '') {
            throw new \InvalidArgumentException(
                'Debes especificar el uniforme autorizado.'
            );
        }

        $tamano = strlen($imagenJpeg);

        if (
            $tamano < 4
            || $tamano > 2 * 1024 * 1024
            || substr($imagenJpeg, 0, 3) !== "\xFF\xD8\xFF"
            || substr($imagenJpeg, -2) !== "\xFF\xD9"
        ) {
            throw new \InvalidArgumentException(
                'La fotografía JPEG no es válida.'
            );
        }

        $instrucciones = <<<PROMPT
Analiza la fotografía exclusivamente para verificar
el cumplimiento del uniforme descrito a continuación.

UNIFORME AUTORIZADO:
{$descripcionUniforme}

REGLAS:
1. Examina únicamente las prendas visibles.
2. No inventes colores, prendas ni logotipos.
3. Si las prendas visibles cumplen los requisitos
   y puedes comprobar los elementos obligatorios,
   responde "con_uniforme".
4. Si observas claramente una prenda que contradice
   los requisitos, responde "sin_uniforme".
5. Si no hay una persona visible, hay varias personas
   difíciles de evaluar, la ropa está tapada,
   la imagen es borrosa o faltan elementos necesarios
   para decidir, responde "no_concluyente".
6. Ante una duda, utiliza "no_concluyente".
7. No identifiques a la persona ni infieras
   características personales.

Devuelve únicamente una clasificación y
una explicación breve en español.
PROMPT;

        $solicitud = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $instrucciones,
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => base64_encode($imagenJpeg),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
                'responseFormat' => [
                    'text' => [
                        'mimeType' => 'APPLICATION_JSON',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'clasificacion' => [
                                    'type' => 'string',
                                    'enum' => [
                                        'con_uniforme',
                                        'sin_uniforme',
                                        'no_concluyente',
                                    ],
                                ],
                                'motivo' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'clasificacion',
                                'motivo',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $cliente = service('curlrequest');

        $url = 'https://generativelanguage.googleapis.com'
            . '/v1beta/models/'
            . self::MODELO
            . ':generateContent';

        $respuesta = $cliente->post($url, [
            'headers' => [
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $solicitud,
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        $codigo = $respuesta->getStatusCode();

        if ($codigo !== 200) {
            $errorGemini = json_decode(
                $respuesta->getBody(),
                true
            );

            $mensaje = $errorGemini['error']['message']
                ?? 'Google no proporcionó detalles';

            throw new \RuntimeException(
                'Gemini HTTP ' . $codigo . ': ' . $mensaje
            );
        }

        $datos = json_decode(
            $respuesta->getBody(),
            true
        );

        $texto = $datos['candidates'][0]
            ['content']['parts'][0]['text'] ?? null;

        if (!is_string($texto)) {
            throw new \RuntimeException(
                'Gemini no devolvió una respuesta válida.'
            );
        }

        $resultado = json_decode($texto, true);

        $clasificaciones = [
            'con_uniforme',
            'sin_uniforme',
            'no_concluyente',
        ];

        if (
            !is_array($resultado)
            || !in_array(
                $resultado['clasificacion'] ?? null,
                $clasificaciones,
                true
            )
            || !is_string($resultado['motivo'] ?? null)
        ) {
            throw new \RuntimeException(
                'La clasificación de Gemini es inválida.'
            );
        }

        return [
            'clasificacion' => $resultado['clasificacion'],
            'motivo' => $resultado['motivo'],
        ];
    }
}
