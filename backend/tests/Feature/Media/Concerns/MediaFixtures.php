<?php

namespace Tests\Feature\Media\Concerns;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * Fichiers de test réels (petites images valides en base64, signatures binaires minimales)
 * et fichiers piégés. Aucune dépendance à GD pour construire les fixtures.
 */
trait MediaFixtures
{
    /** PNG 4 × 3. */
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWOMjnvLAANMDEgAhQMAPQgBrCAnl7cAAAAASUVORK5CYII=';

    /** JPEG 4 × 3. */
    protected const JPG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAwAEAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4yiiiv7APnD/2Q==';

    /** GIF 4 × 3. */
    protected const GIF = 'R0lGODdhBAADAIAAAFxe7AAAACwAAAAABAADAAACA4SPVgA7';

    /** WebP 4 × 3. */
    protected const WEBP = 'UklGRjoAAABXRUJQVlA4IC4AAACQAQCdASoEAAMAAMASJaACdLoAA5gA/u9RL/9Xbf+tg//V23/V23/AXTuHTYAA';

    protected function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function png(string $name = 'photo.png'): UploadedFile
    {
        return $this->upload($name, base64_decode(self::PNG));
    }

    protected function jpg(string $name = 'photo.jpg'): UploadedFile
    {
        return $this->upload($name, base64_decode(self::JPG));
    }

    /** JPEG contenant un segment EXIF avec une position GPS (doit disparaître au réencodage). */
    protected function jpgWithGps(string $name = 'vacances.jpg'): UploadedFile
    {
        $jpeg = base64_decode(self::JPG);
        $payload = "Exif\0\0".'GPSLatitude=48.8584;GPSLongitude=2.2945;SECRET-LOCATION';
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return $this->upload($name, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    }

    protected function gif(string $name = 'anim.gif'): UploadedFile
    {
        return $this->upload($name, base64_decode(self::GIF));
    }

    protected function webp(string $name = 'image.webp'): UploadedFile
    {
        return $this->upload($name, base64_decode(self::WEBP));
    }

    protected function mp4(string $name = 'clip.mp4'): UploadedFile
    {
        return $this->upload($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
    }

    protected function mp3(string $name = 'son.mp3'): UploadedFile
    {
        return $this->upload($name, "ID3\x03\x00\x00\x00\x00\x00\x0A".str_repeat("\x00", 10)."\xFF\xFB\x90\x00".str_repeat("\x00", 400));
    }

    protected function wav(string $name = 'son.wav'): UploadedFile
    {
        return $this->upload($name, "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00");
    }

    protected function pdf(string $name = 'guide.pdf'): UploadedFile
    {
        return $this->upload($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    protected function storeMedia(User $user, UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->fromSpa()->post('/api/v1/media', ['file' => $file, ...$extra], ['Accept' => 'application/json']);
    }

    protected function requiresGd(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Extension GD non chargée : réencodage et vignettes indisponibles.');
        }
    }
}
