<?php

use App\Libraries\PanelPhoto;
use App\Libraries\PanelTime;
use CodeIgniter\Test\CIUnitTestCase;

final class PanelSecurityTest extends CIUnitTestCase
{
    public function testGuatemalaDayBoundariesAndDisplay(): void
    {
        $day = PanelTime::day('2026-10-14', PanelTime::zone('America/Guatemala'));
        $this->assertSame('2026-10-14 06:00:00', PanelTime::utc($day));
        $this->assertSame('2026-10-15 06:00:00', PanelTime::utc($day->modify('+1 day')));
        $this->assertSame('14/10/2026 23:30', PanelTime::display('2026-10-15 05:30:00', 'America/Guatemala'));
    }

    public function testInvalidCalendarDatesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PanelTime::day('2026-02-30', PanelTime::zone('America/Guatemala'));
    }

    public function testPhotoRequiresExactCompanyAndRejectsTraversal(): void
    {
        $base = WRITEPATH . 'testing/panel-' . bin2hex(random_bytes(6));
        mkdir($base . '/7', 0700, true);
        $name = str_repeat('a', 32) . '.jpg';
        file_put_contents($base . '/7/' . $name, 'test fixture');
        try {
            $this->assertSame(realpath($base . '/7/' . $name), PanelPhoto::resolve($base, 7, '7/' . $name));
            $this->assertNull(PanelPhoto::resolve($base, 8, '7/' . $name));
            $this->assertNull(PanelPhoto::resolve($base, 7, '7/../7/' . $name));
            $this->assertNull(PanelPhoto::resolve($base, 7, realpath($base . '/7/' . $name)));
            $this->assertNull(PanelPhoto::resolve($base, 7, '7/' . str_repeat('b', 32) . '.jpg'));
        } finally {
            unlink($base . '/7/' . $name);
            rmdir($base . '/7');
            rmdir($base);
        }
    }
}
