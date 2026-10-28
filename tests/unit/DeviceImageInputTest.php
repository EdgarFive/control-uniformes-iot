<?php

use App\Controllers\Api\DeteccionesController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;

final class DeviceImageInputTest extends CIUnitTestCase
{
    private function probe(): DeteccionesController
    {
        return new class extends DeteccionesController {
            public string|false $raw = "\xFF\xD8\xFFjpeg-bytes\xFF\xD9";
            public int $rawReads = 0;

            protected function leerEntradaCruda(): string|false
            {
                $this->rawReads++;
                return $this->raw;
            }

            public function read(IncomingRequest $request): string|false
            {
                $this->request = $request;
                return $this->leerImagen();
            }
        };
    }

    public function testStreamUriAndMissingCachedBodiesReadActualJpegBytes(): void
    {
        foreach (['php://input', null, ''] as $cached) {
            $request = $this->createMock(IncomingRequest::class);
            $request->method('getBody')->willReturn($cached);
            $probe = $this->probe();
            $this->assertSame($probe->raw, $probe->read($request));
            $this->assertSame(1, $probe->rawReads);
        }
    }

    public function testAlreadyLoadedBinaryBodyIsPreservedWithoutRereading(): void
    {
        $body = "\xFF\xD8\xFF\x00\x80\xFFbinary\xFF\xD9";
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn($body);
        $probe = $this->probe();
        $this->assertSame($body, $probe->read($request));
        $this->assertSame(0, $probe->rawReads);
    }

    public function testRawReadFailureAndMalformedBytesAreNotHidden(): void
    {
        foreach ([false, '', 'not-a-jpeg'] as $raw) {
            $request = $this->createMock(IncomingRequest::class);
            $request->method('getBody')->willReturn('php://input');
            $probe = $this->probe();
            $probe->raw = $raw;
            $this->assertSame($raw, $probe->read($request));
        }
    }
}
