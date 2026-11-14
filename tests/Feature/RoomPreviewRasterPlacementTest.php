<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Services\RoomPreviewRasterService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomPreviewRasterPlacementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD required');
        Storage::fake('public');
    }

    public function test_blocked_placement_never_renders_an_invented_door(): void
    {
        $product = $this->product('Door', $this->image(20, 40, fn ($image) => imagefill($image, 0, 0, 0xff0000)));
        $this->assertNull(app(RoomPreviewRasterService::class)->composite($this->room(), $product, ['renderable' => false]));
        $this->assertNull(app(RoomPreviewRasterService::class)->composite($this->room(), $product, ['renderable' => true]));
    }

    public function test_door_fills_only_the_detected_perspective_opening_without_a_floor_shadow(): void
    {
        $product = $this->product('Door', $this->image(20, 40, function ($image): void {
            imagefilledrectangle($image, 0, 0, 9, 39, 0xff0000);
            imagefilledrectangle($image, 10, 0, 19, 39, 0x0000ff);
        }));
        $placement = ['surface' => 'opening', 'quad_percent' => [[30, 10], [70, 20], [80, 90], [20, 80]]];
        $result = app(RoomPreviewRasterService::class)->composite($this->room(), $product, $placement);
        $this->assertSame([40, 20, 160, 180], $result['bounds']);
        $image = imagecreatefromstring($result['bytes']);
        $this->assertColor(0xff0000, $image, 64, 32);
        $this->assertColor(0x0000ff, $image, 130, 43);
        $this->assertColor(0xffffff, $image, 50, 30);
        $this->assertColor(0xffffff, $image, 155, 50);
        $this->assertColor(0xffffff, $image, 100, 184);
        imagedestroy($image);

        $flipped = app(RoomPreviewRasterService::class)->composite($this->room(), $product, $placement + ['flip' => -1]);
        $image = imagecreatefromstring($flipped['bytes']);
        $this->assertColor(0x0000ff, $image, 64, 32);
        $this->assertColor(0xff0000, $image, 130, 43);
        imagedestroy($image);
    }

    public function test_crossing_or_out_of_frame_opening_is_rejected(): void
    {
        $product = $this->product('Door', $this->image(20, 40));
        foreach ([
            [[20, 20], [80, 80], [80, 20], [20, 80]],
            [[-1, 20], [80, 20], [80, 80], [20, 80]],
            [[20, 20], [20, 20], [80, 80], [20, 80]],
        ] as $quad) {
            $this->assertNull(app(RoomPreviewRasterService::class)->composite($this->room(), $product, ['quad_percent' => $quad]));
        }
    }

    public function test_alpha_padding_is_cropped_and_catalog_image_aspect_ratio_is_preserved(): void
    {
        $bytes = $this->image(100, 100, function ($image): void {
            imagealphablending($image, false);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagefilledrectangle($image, 40, 30, 59, 69, 0xff0000);
            imagesavealpha($image, true);
        });
        $product = $this->product('Chair', $bytes);
        // A catalog photo's projected silhouette is independent of physical width/height metadata.
        $product->width_cm = 400; $product->height_cm = 20;
        $result = app(RoomPreviewRasterService::class)->composite($this->room(), $product, $this->placement());
        $image = imagecreatefromstring($result['bytes']);
        $this->assertSame([80, 80, 120, 160], $result['bounds']);
        $this->assertColor(0xff0000, $image, 100, 85);
        $this->assertColor(0xffffff, $image, 100, 75);
        $this->assertColor(0xffffff, $image, 75, 100);
        $this->assertSame($bytes, Storage::disk('public')->get('products/raster-product.png'));
        imagedestroy($image);
    }

    public function test_regular_furniture_applies_rotation_perspective_and_horizontal_flip(): void
    {
        $product = $this->product('Chair', $this->image(20, 40, function ($image): void {
            imagefilledrectangle($image, 0, 0, 9, 39, 0xff0000);
            imagefilledrectangle($image, 10, 0, 19, 39, 0x0000ff);
        }));
        $service = app(RoomPreviewRasterService::class);
        $normal = imagecreatefromstring($service->composite($this->room(), $product, $this->placement())['bytes']);
        $flipped = imagecreatefromstring($service->composite($this->room(), $product, $this->placement() + ['flip' => -1])['bytes']);
        $this->assertColor(0xff0000, $normal, 90, 120);
        $this->assertColor(0x0000ff, $flipped, 90, 120);
        $rotatedResult = $service->composite($this->room(), $product, $this->placement() + ['rotation_deg' => 18]);
        $rotated = imagecreatefromstring($rotatedResult['bytes']);
        $this->assertColor(0xffffff, $normal, 130, 100);
        $this->assertNotSame(0xffffff, imagecolorat($rotated, 130, 100) & 0xffffff);
        [$left, $top, $right, $bottom] = $rotatedResult['guard_bounds'];
        $outside = [];
        for ($y = 0; $y < 200; $y++) for ($x = 0; $x < 200; $x++) {
            if ((imagecolorat($rotated, $x, $y) & 0xffffff) !== 0xffffff && ($x < $left || $x >= $right || $y < $top || $y >= $bottom)) $outside[] = [$x, $y];
        }
        $this->assertSame([], $outside, 'Reported bounds must include the rotated image and its contact shadow.');
        $sheared = imagecreatefromstring($service->composite($this->room(), $product, $this->placement() + ['perspective_deg' => 24])['bytes']);
        $this->assertColor(0xffffff, $normal, 72, 90);
        $this->assertColor(0xff0000, $sheared, 72, 90);
        foreach ([$normal, $flipped, $rotated, $sheared] as $image) imagedestroy($image);
    }

    public function test_opaque_product_colours_are_never_guessed_as_transparent(): void
    {
        $product = $this->product('Chair', $this->image(20, 40));
        $room = $this->image(200, 200, fn ($image) => imagefill($image, 0, 0, 0x888888));
        $image = imagecreatefromstring(app(RoomPreviewRasterService::class)->composite($room, $product, $this->placement())['bytes']);
        $this->assertColor(0xffffff, $image, 100, 100);
        $this->assertColor(0x888888, $image, 50, 100);
        imagedestroy($image);
    }

    public function test_fully_transparent_product_does_not_invent_a_contact_shadow(): void
    {
        $product = $this->product('Chair', $this->image(20, 40, function ($image): void {
            imagealphablending($image, false);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagesavealpha($image, true);
        }));
        $this->assertNull(app(RoomPreviewRasterService::class)->composite($this->room(), $product, $this->placement()));
    }

    private function product(string $category, string $bytes): FurnitureProduct
    {
        Storage::disk('public')->put('products/raster-product.png', $bytes);
        return new FurnitureProduct(['category' => $category, 'image_path' => 'products/raster-product.png']);
    }

    private function placement(): array
    {
        return ['x_percent' => 50, 'y_percent' => 80, 'max_width_percent' => 20, 'renderable' => true];
    }

    private function room(): string
    {
        return $this->image(200, 200);
    }

    private function image(int $width, int $height, ?callable $draw = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, 0xffffff);
        if ($draw) $draw($image);
        ob_start(); imagepng($image); $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function assertColor(int $expected, \GdImage $image, int $x, int $y): void
    {
        $this->assertSame($expected, imagecolorat($image, $x, $y) & 0xffffff, "Pixel {$x},{$y} differs");
    }
}
