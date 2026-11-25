<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use spl\CLI;
use ValueError;

final class CLITest extends TestCase {

    private string|false $no_color;

    protected function setUp(): void {
        // force colours off so in-process output doesn't depend on whether phpunit runs in a terminal
        $this->no_color = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
    }

    protected function tearDown(): void {
        putenv($this->no_color === false ? 'NO_COLOR' : "NO_COLOR={$this->no_color}");
        CLIHarness::setStreams();
    }

    /**
     * @return resource
     */
    private function stream(string $contents = '') {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * Run PHP code in a child process whose STDOUT is a terminal and return what it captured from its own output.
     *
     * @param array<string, string> $env
     */
    private function runOnTerminal(string $code, array $env = []): string {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $env = array_merge(array_diff_key(getenv(), ['NO_COLOR' => true]), $env);

        // reading the pty directly raises an EIO notice when the child exits,
        // so the child buffers its own output and sends it to a STDERR pipe
        $code = "require {$autoload}; ob_start(); {$code}; fwrite(STDERR, (string) ob_get_clean());";

        try {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pty'], 2 => ['pipe', 'w']], $pipes, null, $env);
        }
        catch (ValueError $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process));
        $this->assertIsString($output);

        return $output;
    }

    public function testPrintlnLeavesUnknownBracketedTextWithColoursOff(): void {
        $this->expectOutputString("a [r] [x_y] [Hi] [123]\n");

        CLI::println('[bold]a[reset] [r] [x_y] [Hi] [123]');
    }

    public function testPrintStripsKnownTokensWithoutNewline(): void {
        $this->expectOutputString('plain [b]');

        CLI::print('[red]plain[reset] [b]');
    }

    public function testSelectListsOptionKeysLiterally(): void {
        CLIHarness::setStreams($this->stream("red\n"));
        $this->expectOutputString("Pick\n  [r] Red\n  [red] Crimson\nEnter choice [r]: ");

        $this->assertSame('red', CLIHarness::select('Pick', ['r' => 'Red', 'red' => 'Crimson'], 'r'));
    }

    public function testPromptShowsDefaultLiterally(): void {
        CLIHarness::setStreams($this->stream("\n"));
        $this->expectOutputString('Style [bold]: ');

        $this->assertSame('bold', CLIHarness::prompt('Style', 'bold'));
    }

    public static function promptAtEndOfInputProvider(): array {
        return [
            'default'       => ['Name', 'bob', 'bob', "Name [bob]: \n"],
            'no default'    => ['Name', '', '', "Name: \n"],
            'token default' => ['Style', 'bold', 'bold', "Style [bold]: \n"],
        ];
    }

    #[DataProvider('promptAtEndOfInputProvider')]
    public function testPromptReturnsDefaultAtEndOfInput(string $prompt, string $default, string $expected, string $output): void {
        CLIHarness::setStreams($this->stream());
        $this->expectOutputString($output);

        $this->assertSame($expected, CLIHarness::prompt($prompt, $default));
    }

    public function testPromptReadsLastLineWithoutNewline(): void {
        CLIHarness::setStreams($this->stream(' alice '));
        $this->expectOutputString('Name: ');

        $this->assertSame('alice', CLIHarness::prompt('Name'));
    }

    public static function confirmAtEndOfInputProvider(): array {
        return [
            'default yes' => [true, "Ok? (Y/n): \n"],
            'default no'  => [false, "Ok? (y/N): \n"],
        ];
    }

    #[DataProvider('confirmAtEndOfInputProvider')]
    public function testConfirmReturnsDefaultAtEndOfInput(bool $default, string $output): void {
        CLIHarness::setStreams($this->stream());
        $this->expectOutputString($output);

        $this->assertSame($default, CLIHarness::confirm('Ok?', $default));
    }

    public function testConfirmReadsAnswer(): void {
        CLIHarness::setStreams($this->stream("Yes\n"));
        $this->expectOutputString('Ok? (y/N): ');

        $this->assertTrue(CLIHarness::confirm('Ok?', false));
    }

    public function testSelectReturnsDefaultAtEndOfInputAfterInvalidChoice(): void {
        CLIHarness::setStreams($this->stream("zz\n"));
        $this->expectOutputString(
            "Colour\n  [r] Red\n  [g] Green\n"
            . "Enter choice [g]: Invalid choice. Please try again.\n"
            . "Enter choice [g]: \n",
        );

        $this->assertSame('g', CLIHarness::select('Colour', ['r' => 'Red', 'g' => 'Green'], 'g'));
    }

    public function testSelectThrowsAtEndOfInputWithoutDefault(): void {
        CLIHarness::setStreams($this->stream("zz\n"));

        ob_start();

        try {
            CLIHarness::select('Colour', ['r' => 'Red', 'g' => 'Green']);
            $this->fail('Expected select() to throw at end of input');
        }
        catch (RuntimeException $e) {
            $this->assertSame('Unexpected end of input', $e->getMessage());
        }
        finally {
            $output = ob_get_clean();
        }

        $this->assertSame(
            "Colour\n  [r] Red\n  [g] Green\n"
            . "Enter choice: Invalid choice. Please try again.\n"
            . "Enter choice: \n",
            $output,
        );
    }

    public function testTerminalOutputReplacesOnlyKnownTokens(): void {
        $output = $this->runOnTerminal("spl\\CLI::println('[bold]a[reset] [r] [x_y] [Hi]')");

        $this->assertSame("\033[1ma\033[0m [r] [x_y] [Hi]\n", $output);
    }

    public static function noColorProvider(): array {
        return [
            'one'   => ['1', "x\n"],
            'zero'  => ['0', "x\n"],
            'false' => ['false', "x\n"],
            'empty' => ['', "\033[31mx\033[0m\n"],
        ];
    }

    #[DataProvider('noColorProvider')]
    public function testNoColorDisablesColoursOnTerminal(string $value, string $expected): void {
        $output = $this->runOnTerminal("spl\\CLI::println('[red]x[reset]')", ['NO_COLOR' => $value]);

        $this->assertSame($expected, $output);
    }

}
