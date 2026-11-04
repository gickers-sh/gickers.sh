<?php

declare(strict_types=1);

namespace CodesSh\Tui\Tests\Style;

use CodesSh\Tui\Event\MouseMsg;
use CodesSh\Tui\Style\ZoneManager;
use PHPUnit\Framework\TestCase;

final class ZoneManagerTest extends TestCase
{
    protected function setUp(): void
    {
        ZoneManager::clear();
    }

    public function testMarkAndScanZones(): void
    {
        $tab1 = ZoneManager::mark('tab_readme', 'Readme');
        $tab2 = ZoneManager::mark('tab_files', 'Files');

        $frame = '  '.$tab1.'   '.$tab2;

        $clean = ZoneManager::scan($frame);

        // All zone sentinels stripped
        $this->assertSame('  Readme   Files', $clean);

        $zones = ZoneManager::getZones();
        $this->assertArrayHasKey('tab_readme', $zones);
        $this->assertArrayHasKey('tab_files', $zones);

        // '  Readme' -> 2 spaces, then Readme starts at col 3, length 6 -> ends at col 8
        $this->assertSame(3, $zones['tab_readme']['minX']);
        $this->assertSame(8, $zones['tab_readme']['maxX']);
        $this->assertSame(1, $zones['tab_readme']['minY']);
        $this->assertSame(1, $zones['tab_readme']['maxY']);

        // 'Files' starts after 2 + 6 + 3 = col 12, length 5 -> ends at col 16
        $this->assertSame(12, $zones['tab_files']['minX']);
        $this->assertSame(16, $zones['tab_files']['maxX']);

        // Test inBounds
        $clickInsideReadme = new MouseMsg(x: 5, y: 1, button: MouseMsg::BUTTON_LEFT, action: MouseMsg::ACTION_PRESS);
        $this->assertTrue(ZoneManager::inBounds('tab_readme', $clickInsideReadme));
        $this->assertFalse(ZoneManager::inBounds('tab_files', $clickInsideReadme));

        $clickInsideFiles = new MouseMsg(x: 14, y: 1, button: MouseMsg::BUTTON_LEFT, action: MouseMsg::ACTION_PRESS);
        $this->assertTrue(ZoneManager::inBounds('tab_files', $clickInsideFiles));
        $this->assertFalse(ZoneManager::inBounds('tab_readme', $clickInsideFiles));

        // Click outside
        $clickOutside = new MouseMsg(x: 1, y: 1, button: MouseMsg::BUTTON_LEFT, action: MouseMsg::ACTION_PRESS);
        $this->assertFalse(ZoneManager::inBounds('tab_readme', $clickOutside));
        $this->assertFalse(ZoneManager::inBounds('tab_files', $clickOutside));
    }
}
