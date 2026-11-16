<?php

namespace Tests\Feature;

use App\Services\RoomPreviewLightingService;
use Tests\TestCase;

class RoomPreviewLightingTest extends TestCase
{
    public function test_adjustment_preserves_alpha_and_matches_the_color_formula(): void
    {
        if (! function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD required');
        $room=imagecreatetruecolor(16,12); imagefill($room,0,0,0x786450);
        $item=imagecreatetruecolor(4,4); imagealphablending($item,false);
        imagefill($item,0,0,(64<<24)|(120<<16)|(128<<8)|136);
        imagesetpixel($item,3,3,127<<24);
        $service=new RoomPreviewLightingService;
        $profile=$service->profile(array_fill(0,48,[120,100,80,.5]));
        $expected=$service->color([120,128,136],$profile,.125);
        $service->apply($item,$room,false);
        $pixel=imagecolorat($item,0,0);
        $this->assertSame(64,($pixel>>24)&127);
        $this->assertSame($expected,[($pixel>>16)&255,($pixel>>8)&255,$pixel&255]);
        $this->assertSame(127,(imagecolorat($item,3,3)>>24)&127);
        imagedestroy($item); imagedestroy($room);
    }

    public function test_light_adjustment_is_bounded_directional_and_preserves_highlights(): void
    {
        $service = new RoomPreviewLightingService;
        $this->assertSame([1.0, 0.0, 0.0], $service->profile([]));
        $samples = [];
        for ($i=0; $i<48; $i++) $samples[] = $i%8<4 ? [100,90,80,.25] : [190,180,170,.75];
        $profile = $service->profile($samples);
        $this->assertEqualsWithDelta(.9858666666666668, $profile[0], .000001);
        $left = $service->color([120,120,120], $profile, 0);
        $right = $service->color([120,120,120], $profile, 1);
        $this->assertGreaterThan($left[2], $left[0]);
        $this->assertGreaterThan($left[0], $right[0]);
        $this->assertSame([255,255,255], $service->color([255,255,255], $profile, .5));
        foreach ($left as $channel) $this->assertLessThan(20, abs($channel-120));
    }
}
