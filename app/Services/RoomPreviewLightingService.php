<?php

namespace App\Services;

class RoomPreviewLightingService
{
    public function profile(array $samples): array
    {
        $usable = array_values(array_filter($samples, fn ($s) => max($s[0], $s[1], $s[2]) - min($s[0], $s[1], $s[2]) <= 100
            && ($s[0] + $s[1] + $s[2]) / 3 > 25 && ($s[0] + $s[1] + $s[2]) / 3 < 240));
        if (count($usable) < 8) return [1.0, 0.0, 0.0];
        $luma = fn ($s) => .299 * $s[0] + .587 * $s[1] + .114 * $s[2];
        $mean = fn ($values) => array_sum($values) / max(1, count($values));
        $left = array_filter($usable, fn ($s) => $s[3] < .5);
        $right = array_filter($usable, fn ($s) => $s[3] >= .5);
        return [.9 + .16 * $mean(array_map($luma, $usable)) / 255,
            max(-.12, min(.12, $mean(array_map(fn ($s) => ($s[0] - $s[2]) / 255, $usable)))) * .25,
            $left && $right ? max(-.07, min(.07, ($mean(array_map($luma, $right)) - $mean(array_map($luma, $left))) / 255 * .2)) : 0.0];
    }

    public function color(array $rgb, array $profile, float $x): array
    {
        [$gain, $warm, $direction] = $profile;
        $weight = 1 - (($rgb[0] + $rgb[1] + $rgb[2]) / 765) ** 4;
        return array_map(fn ($channel, $index) => (int) floor(max(0, min(255,
            $channel * (1 + ($gain - 1 + $direction * (2 * $x - 1) + [ $warm, 0, -$warm ][$index]) * $weight))) + .5), $rgb, [0, 1, 2]);
    }

    public function apply(\GdImage &$item, \GdImage $room, bool $flip): void
    {
        $samples = [];
        for ($y = 0; $y < 6; $y++) for ($x = 0; $x < 8; $x++) {
            $pixel = imagecolorat($room, min(imagesx($room)-1, (int) (($x+.5)/8*imagesx($room))), min(imagesy($room)-1, (int) (($y+.5)/6*imagesy($room))));
            $samples[] = [($pixel>>16)&255, ($pixel>>8)&255, $pixel&255, ($x+.5)/8];
        }
        $profile = $this->profile($samples);
        if ($profile === [1.0, 0.0, 0.0]) return;
        // Preview furniture occupies a fraction of a 1600px frame; avoid processing huge catalog originals.
        $scale=min(1,1200/max(imagesx($item),imagesy($item)));
        if ($scale<1) {
            $resized=imagecreatetruecolor(max(1,(int) round(imagesx($item)*$scale)),max(1,(int) round(imagesy($item)*$scale)));
            imagealphablending($resized,false); imagesavealpha($resized,true);
            imagecopyresampled($resized,$item,0,0,0,0,imagesx($resized),imagesy($resized),imagesx($item),imagesy($item));
            imagedestroy($item); $item=$resized;
        }
        [$gain, $warm, $direction] = $profile;
        $columnGains = [];
        for ($x=0; $x<imagesx($item); $x++) {
            $u=($x+.5)/imagesx($item);
            $columnGains[$x]=$gain-1+$direction*(2*($flip ? 1-$u : $u)-1);
        }
        imagealphablending($item, false);
        for ($y = 0; $y < imagesy($item); $y++) for ($x = 0; $x < imagesx($item); $x++) {
            $pixel = imagecolorat($item, $x, $y); $alpha = ($pixel>>24)&127;
            if ($alpha === 127) continue;
            $r=($pixel>>16)&255; $g=($pixel>>8)&255; $b=$pixel&255;
            $weight=1-(($r+$g+$b)/765)**4;
            $adjust=$columnGains[$x];
            $r=(int) floor(max(0,min(255,$r*(1+($adjust+$warm)*$weight)))+.5);
            $g=(int) floor(max(0,min(255,$g*(1+$adjust*$weight)))+.5);
            $b=(int) floor(max(0,min(255,$b*(1+($adjust-$warm)*$weight)))+.5);
            imagesetpixel($item, $x, $y, ($alpha<<24)|($r<<16)|($g<<8)|$b);
        }
    }
}
