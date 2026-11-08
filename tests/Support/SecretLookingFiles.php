<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Support;

/**
 * The files that must never leave a machine, planted in a test project the way
 * a careless developer would have committed them: an env file, a private key,
 * and a source file that holds an AWS key. One file beside them is fine to send.
 */
final class SecretLookingFiles
{
    public const array REFUSED = ['.env', 'keys/server.pem', 'app/Keys.php'];

    public const string FINE = 'app/Fine.php';

    public static function plant(string $root): void
    {
        is_dir($root.'/keys') || mkdir($root.'/keys', 0755, true);
        is_dir($root.'/app') || mkdir($root.'/app', 0755, true);

        file_put_contents($root.'/.env', "APP_KEY=base64:c2VjcmV0\n");
        file_put_contents($root.'/keys/server.pem', "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu\n-----END RSA PRIVATE KEY-----\n");
        file_put_contents($root.'/app/Keys.php', "<?php\n\nclass Keys\n{\n    const AWS = 'AKIAABCDEFGHIJKLMNOP';\n}\n");
        file_put_contents($root.'/'.self::FINE, "<?php\n\nclass Fine\n{\n}\n");
    }

    /**
     * Whether any of what must not leave is in this text.
     */
    public static function leaks(string $text): bool
    {
        return str_contains($text, 'APP_KEY') || str_contains($text, 'BEGIN RSA PRIVATE KEY') || str_contains($text, 'AKIAABCDEFGHIJKLMNOP');
    }
}
