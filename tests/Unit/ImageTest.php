<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\Image\ImageChoice;
use IndianOpinion\WcProductCopy\Image\ImagePreparer;
use IndianOpinion\WcProductCopy\Image\JpegMeta;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;

/**
 * Section 4.2: the product image (cases 9 to 12).
 */
final class ImageTest extends TestCase
{
    private function jpeg(int $w, int $h, bool $exif = true): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($image, 120, 80, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        if ($exif) {
            $payload = "Exif\0\0" . 'GPS 51.5N 0.1W Camera Author Jane';
            $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
            $bytes = substr($bytes, 0, 2) . $segment . substr($bytes, 2);
        }

        return $bytes;
    }

    private function attachment(int $id, ?string $bytes, string $mime = 'image/jpeg', string $url = 'https://shop.test/wp-content/uploads/board.jpg'): void
    {
        $file = '/nonexistent/board-' . $id . '.jpg';
        if ($bytes !== null) {
            $file = (string) tempnam(sys_get_temp_dir(), 'pcimg');
            file_put_contents($file, $bytes);
            \WpState::$tempFiles[] = $file;
        }
        $site = &$this->site();
        $site['attachments'][$id] = ['file' => $file, 'mime' => $mime, 'url' => $url, 'meta' => ['filesize' => 1234]];
    }

    private function imageRun(int $productId): \IndianOpinion\WcProductCopy\Tests\Support\FakeCaller
    {
        $this->configure(['image_support' => true, 'send_image' => true]);
        $fake = $this->fakeCaller(true);
        $fake->push(FakeCaller::answer());
        $this->plugin()->generator->run($productId, []);

        return $fake;
    }

    /** Test 9 (case 9). */
    public function testGalleryImageIsUsedWithoutFeaturedAndNoneWhenOff(): void
    {
        $this->attachment(501, $this->jpeg(200, 100, false));
        $id = $this->richProduct(['image_id' => 0, 'gallery_image_ids' => [501, 502]]);

        $choice = ImageChoice::pick($this->stored($id), true, true);
        $this->assertSame(501, $choice->attachmentId);
        $this->assertTrue($choice->willSend());

        $fake = $this->imageRun($id);
        $this->assertNotNull($fake->requests[0]->imageDataUrl);

        $this->settings(['send_image' => false]);
        $fake->push(FakeCaller::answer());
        $this->plugin()->generator->run($id, ['refresh_drafts' => true]);
        $this->assertNull($fake->requests[1]->imageDataUrl);
        $this->assertSame('none', ImageChoice::pick($this->stored($this->product(['id' => 900])), true, true)->status);
    }

    /** Test 10 (case 10). */
    public function testLargeImageIsResizedReencodedAndStripped(): void
    {
        $this->attachment(510, $this->jpeg(4000, 1000));
        $prepared = (new ImagePreparer())->prepare(510);

        $this->assertTrue($prepared->sent());
        $this->assertNotNull($prepared->dataUrl);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $prepared->dataUrl);
        $bytes = base64_decode(substr($prepared->dataUrl, strlen('data:image/jpeg;base64,')), true);
        $this->assertIsString($bytes);
        $size = getimagesizefromstring($bytes);
        $this->assertIsArray($size);
        $this->assertSame(1024, max($size[0], $size[1]));
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertStringNotContainsString('GPS', $bytes);
    }

    public function testMetadataSegmentsAreStrippedEvenWithoutResize(): void
    {
        $withExif = $this->jpeg(40, 20);
        $this->assertStringContainsString('Exif', $withExif);
        $clean = JpegMeta::strip($withExif);
        $this->assertStringNotContainsString('Exif', $clean);
        $this->assertNotFalse(imagecreatefromstring($clean));
    }

    /** Test 11 (case 11). */
    public function testOffloadedFileIsFetchedOnlyFromTheSitesHosts(): void
    {
        $bytes = $this->jpeg(300, 200);
        \WpState::$http = static fn (string $url): array => ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/jpeg'], 'body' => $bytes];

        $this->attachment(520, null, 'image/jpeg', 'https://cdn.shop.test/uploads/board.jpg');
        $this->assertTrue((new ImagePreparer())->prepare(520)->sent());
        $this->assertCount(1, \WpState::$httpRequests);
        $this->assertSame(10, \WpState::$httpRequests[0]['args']['timeout']);
        $this->assertSame(0, \WpState::$httpRequests[0]['args']['redirection']);

        \WpState::$httpRequests = [];
        $this->attachment(521, null, 'image/jpeg', 'https://elsewhere.example/board.jpg');
        $id = $this->richProduct(['image_id' => 521]);
        $this->imageRun($id);
        $this->assertSame([], \WpState::$httpRequests);
        $draft = $this->plugin()->drafts->get($id);
        $this->assertNotNull($draft);
        $this->assertSame(['status' => 'image_unavailable', 'detail' => 'foreign_host'], $draft->extra['image']);

        \WpState::$httpRequests = [];
        \WpState::$http = static fn (string $url): array => ['response' => ['code' => 302], 'headers' => ['location' => 'https://elsewhere.example/board.jpg'], 'body' => ''];
        $this->attachment(522, null);
        $prepared = (new ImagePreparer())->prepare(522);
        $this->assertFalse($prepared->sent());
        $this->assertSame('redirect_off_host', $prepared->detail);
        $this->assertCount(1, \WpState::$httpRequests);
    }

    public function testNonImageAndOversizeBodiesAreRefused(): void
    {
        $this->attachment(530, null);
        \WpState::$http = static fn (): array => ['response' => ['code' => 200], 'headers' => ['content-type' => 'text/html'], 'body' => '<html>'];
        $this->assertSame('not_an_image', (new ImagePreparer())->prepare(530)->detail);

        $headers = ['content-type' => 'image/jpeg', 'content-length' => (string) (ImagePreparer::FETCH_LIMIT + 5)];
        \WpState::$http = static fn (): array => ['response' => ['code' => 200], 'headers' => $headers, 'body' => 'x'];
        $this->assertSame('too_large', (new ImagePreparer())->prepare(530)->detail);
    }

    /** Test 12 (case 12). */
    public function testSvgIsNeverSent(): void
    {
        $this->attachment(540, '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml');
        $id = $this->richProduct(['image_id' => 540]);

        $this->assertSame(ImageChoice::SVG, ImageChoice::pick($this->stored($id), true, true)->status);
        $fake = $this->imageRun($id);
        $this->assertNull($fake->requests[0]->imageDataUrl);
    }

    public function testUnreadableFileMeansImageUnavailable(): void
    {
        $this->attachment(550, 'not really a jpeg');
        $prepared = (new ImagePreparer())->prepare(550);
        $this->assertFalse($prepared->sent());
        $this->assertSame('image_unavailable', $prepared->status);
    }

    /** Ruling on Q1: the llm-json-php adapter is text-only and says so. */
    public function testTextOnlyCallerRecordsThatTheImageWasNotSent(): void
    {
        $this->attachment(560, $this->jpeg(100, 100));
        $id = $this->richProduct(['image_id' => 560]);
        $this->configure(['image_support' => true]);
        $fake = $this->fakeCaller(false);
        $fake->push(FakeCaller::answer());

        $this->plugin()->generator->run($id, []);

        $this->assertNull($fake->requests[0]->imageDataUrl);
        $draft = $this->plugin()->drafts->get($id);
        $this->assertNotNull($draft);
        $this->assertSame(['status' => 'not_sent', 'detail' => 'caller_text_only'], $draft->extra['image']);
    }
}
