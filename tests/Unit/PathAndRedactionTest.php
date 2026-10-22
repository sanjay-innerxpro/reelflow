<?php

namespace Tests\Unit;

use App\Services\Sync\PathSanitizer;
use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

class PathAndRedactionTest extends TestCase
{
    public function test_relative_paths_are_normalised(): void
    {
        $this->assertSame('recipes/pasta.mp4', PathSanitizer::relative('recipes\\pasta.mp4'));
        $this->assertSame('a/b.mp4', PathSanitizer::relative('./a//b.mp4'));
    }

    public function test_absolute_and_escaping_paths_are_rejected(): void
    {
        $this->assertNull(PathSanitizer::relative('/home/me/video.mp4'));
        $this->assertNull(PathSanitizer::relative('C:\\Users\\me\\video.mp4'));
        $this->assertNull(PathSanitizer::relative('../secret/video.mp4'));
        $this->assertNull(PathSanitizer::relative(''));
    }

    public function test_folder_keeps_source_categorisation(): void
    {
        $this->assertSame('Cooking/recipes/indian', PathSanitizer::folderFor('Cooking', 'recipes/indian/dal.mp4'));
        $this->assertSame('Cooking', PathSanitizer::folderFor('Cooking', 'dal.mp4'));
    }

    public function test_secrets_are_redacted(): void
    {
        $data = SecretRedactor::redact([
            'access_token' => 'EAAabc',
            'nested' => ['client_secret' => 'shh', 'status_code' => 'FINISHED'],
            'url' => 'https://graph.facebook.com/x?access_token=EAAsecret123&fields=id',
            'header' => 'Authorization: Bearer ya29.a0AfH6SMBsecret',
        ]);

        $this->assertSame(SecretRedactor::MASK, $data['access_token']);
        $this->assertSame(SecretRedactor::MASK, $data['nested']['client_secret']);
        $this->assertSame('FINISHED', $data['nested']['status_code']);
        $this->assertStringNotContainsString('EAAsecret123', $data['url']);
        $this->assertStringNotContainsString('ya29', $data['header']);
        $this->assertStringNotContainsString('rfd_abcdefghijklmnopqrstuvwxyz', SecretRedactor::redactString('token rfd_abcdefghijklmnopqrstuvwxyz123'));
    }
}
