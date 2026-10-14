<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DevelopmentSetupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fasre-setup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/.env.example', "APP_ENV=local\nAPP_KEY=\n");
    }

    protected function tearDown(): void
    {
        foreach (['.env', '.env.example'] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        rmdir($this->directory);
    }

    private function initialize(string $environment = 'testing'): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/initialize-environment.php'], $this->directory, [
            'APP_ENV' => $environment, 'APP_KEY' => false,
        ]);
        $process->run();

        return $process;
    }

    public function test_existing_quoted_key_and_environment_are_unchanged(): void
    {
        $original = "APP_ENV=local\r\nAPP_KEY=\"base64:".base64_encode(str_repeat('x', 32))."\" # Preserve this key\r\nOTHER=value\r\n";
        file_put_contents($this->directory.'/.env', $original);
        $this->assertTrue($this->initialize()->isSuccessful());
        $this->assertSame($original, file_get_contents($this->directory.'/.env'));
    }

    public function test_new_environment_gets_a_valid_key_once(): void
    {
        $this->assertTrue($this->initialize()->isSuccessful());
        $original = file_get_contents($this->directory.'/.env');
        $values = Dotenv::parse($original);
        $this->assertSame(32, strlen(base64_decode(substr($values['APP_KEY'], 7), true)));
        $this->assertTrue($this->initialize()->isSuccessful());
        $this->assertSame($original, file_get_contents($this->directory.'/.env'));
    }

    public function test_production_setup_is_refused_without_changing_environment(): void
    {
        $original = "APP_ENV=production\nAPP_KEY=\n";
        file_put_contents($this->directory.'/.env', $original);
        $this->assertFalse($this->initialize('production')->isSuccessful());
        $this->assertSame($original, file_get_contents($this->directory.'/.env'));
        $this->assertFalse($this->initialize('testing')->isSuccessful());
        $this->assertSame($original, file_get_contents($this->directory.'/.env'));
    }
}
