<?php

use Dotenv\Exception\InvalidFileException;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $path = getcwd().'/.env';
    $template = getcwd().'/.env.example';
    $source = is_file($path) ? $path : $template;
    if (! is_readable($source)) {
        throw new RuntimeException('Cannot read the development environment.');
    }
    $content = file_get_contents($source);
    if ($content === false) {
        throw new RuntimeException('Cannot read the development environment.');
    }
    $values = Dotenv\Dotenv::parse($content);
    if (getenv('APP_ENV') === 'production' || ($values['APP_ENV'] ?? 'local') === 'production') {
        throw new RuntimeException('Use the deployment runbook for production; setup is for development.');
    }
    $pattern = '/^[\t ]*(?:export[\t ]+)?APP_KEY[\t ]*=.*$/m';
    if (preg_match_all($pattern, $content) > 1) {
        throw new RuntimeException('Resolve duplicate APP_KEY entries before setup.');
    }
    // Preserve a configured key exactly, including quoted/commented values.
    if (trim($values['APP_KEY'] ?? '') !== '' || trim(getenv('APP_KEY') ?: '') !== '') {
        if (! is_file($path) && file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot create the development environment.');
        }
        echo "Existing encryption key preserved.\n";
        exit(0);
    }

    $key = 'APP_KEY=base64:'.base64_encode(random_bytes(32));
    $content = preg_match($pattern, $content)
        ? preg_replace($pattern, $key, $content)
        : rtrim($content).PHP_EOL.$key.PHP_EOL;
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Cannot initialize the development environment.');
    }
    echo "Development encryption key initialized.\n";
} catch (InvalidFileException $error) {
    fwrite(STDERR, "The environment file has invalid syntax; correct it before setup.\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
