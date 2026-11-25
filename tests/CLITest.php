<?php declare(strict_types=1);

namespace spl\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use spl\CLI;
use ValueError;

final class CLITest extends TestCase {

    private string|false $no_color;

    /**
     * Temp files and directories to remove, in creation order.
     *
     * @var array<int, string>
     */
    private array $paths = [];

    protected function setUp(): void {
        // force colours off so in-process output doesn't depend on whether phpunit runs in a terminal
        $this->no_color = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
    }

    protected function tearDown(): void {
        putenv($this->no_color === false ? 'NO_COLOR' : "NO_COLOR={$this->no_color}");
        CLIHarness::setStreams();

        foreach (array_reverse($this->paths) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
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
     * Write a commands file that returns the given PHP array body.
     */
    private function commandsFile(string $body, string $directory = ''): string {
        if ($directory === '') {
            $file = tempnam(sys_get_temp_dir(), 'spl-cli-');
            $this->assertIsString($file);
        }
        else {
            $file = "{$directory}/commands.php";
        }

        $this->paths[] = $file;
        file_put_contents($file, "<?php return [\n{$body}\n];\n");

        return $file;
    }

    /**
     * Run the commands in a commands file and return the exit code and what was written to STDERR.
     *
     * @param array<int, string> $argv
     * @return array{int, string}
     */
    private function dispatch(string $file, array $argv): array {
        $stderr = $this->stream();
        CLIHarness::setStreams(null, $stderr);

        $code = CLIHarness::dispatch($argv, $file);

        rewind($stderr);

        return [$code, (string) stream_get_contents($stderr)];
    }

    /**
     * Run CLI::handle() in a child process for an app whose commands file has the given body.
     *
     * @param array<int, string> $args
     * @return array{int, string, string} Exit code, STDOUT and STDERR
     */
    private function runHandle(string $body, array $args): array {
        $root = tempnam(sys_get_temp_dir(), 'spl-cli-');
        $this->assertIsString($root);
        unlink($root);
        mkdir("{$root}/config", 0o700, true);
        array_push($this->paths, $root, "{$root}/config");

        $this->commandsFile($body, "{$root}/config");

        $script = "{$root}/cli.php";
        $this->paths[] = $script;
        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\ndefine('SPL_ROOT', %s);\nspl\\CLI::handle(\$argv);\n",
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($root, true),
        ));

        $process = proc_open([PHP_BINARY, $script, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['NO_COLOR' => '1'] + getenv());
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
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

    public static function helpArgsProvider(): array {
        return [
            'no command'   => [['cli']],
            'help'         => [['cli', 'help']],
            'help extra'   => [['cli', '-v', 'help', 'zeta']],
        ];
    }

    /**
     * @param array<int, string> $argv
     */
    #[DataProvider('helpArgsProvider')]
    public function testBuiltInHelpListsCommandsSortedAndAligned(array $argv): void {
        $file = $this->commandsFile(<<<'PHP'
            'zeta' => ['description' => 'Last one', 'handler' => fn(array $options, array $args): ?int => 5],
            'alpha:long' => ['description' => 'First [bold]one[reset]', 'handler' => fn(array $options, array $args): ?int => 5],
            PHP);
        $this->expectOutputString(
            "Available commands:\n"
            . "  alpha:long  First one\n"
            . "  help        List available commands\n"
            . "  zeta        Last one\n",
        );

        $this->assertSame([0, ''], $this->dispatch($file, $argv));
    }

    public static function userHelpArgsProvider(): array {
        return [
            'no command' => [['cli']],
            'help'       => [['cli', 'help']],
        ];
    }

    /**
     * @param array<int, string> $argv
     */
    #[DataProvider('userHelpArgsProvider')]
    public function testUserDefinedHelpReplacesBuiltIn(array $argv): void {
        $file = $this->commandsFile(<<<'PHP'
            'help' => ['description' => 'Mine', 'handler' => function (array $options, array $args): ?int { echo 'custom help'; return 7; }],
            PHP);
        $this->expectOutputString('custom help');

        $this->assertSame([7, ''], $this->dispatch($file, $argv));
    }

    public function testDispatchPassesOptionsAndRemainingArgumentsAndReturnsExitCode(): void {
        $file = $this->commandsFile(<<<'PHP'
            'run' => ['description' => 'Run', 'handler' => function (array $options, array $args): ?int { echo json_encode([$options, $args]); return 3; }],
            PHP);
        $this->expectOutputString('[{"v":true,"x":"1"},["a","b"]]');

        $this->assertSame([3, ''], $this->dispatch($file, ['cli', '-v', 'run', 'a', '--x=1', 'b']));
    }

    public function testNullExitCodeBecomesZero(): void {
        $file = $this->commandsFile(<<<'PHP'
            'quiet' => ['description' => 'Quiet', 'handler' => fn(array $options, array $args): ?int => null, 'aliases' => ['q']],
            PHP);

        $this->assertSame([0, ''], $this->dispatch($file, ['cli', 'quiet']));
    }

    public static function invalidExitCodeProvider(): array {
        return [
            'string'   => ["'x'", 'got string'],
            'too big'  => ['256', 'got 256'],
            'negative' => ['-1', 'got -1'],
            'bool'     => ['true', 'got bool'],
        ];
    }

    #[DataProvider('invalidExitCodeProvider')]
    public function testInvalidExitCodeThrows(string $value, string $got): void {
        $file = $this->commandsFile("'bad' => ['description' => 'Bad', 'handler' => fn() => {$value}],");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Command 'bad' must return null or an exit code from 0 to 255, {$got}");

        $this->dispatch($file, ['cli', 'bad']);
    }

    public function testUnknownCommandWritesToStderr(): void {
        $file = $this->commandsFile("'ok' => ['description' => 'Ok', 'handler' => fn() => null],");
        $this->expectOutputString('');

        $this->assertSame(
            [1, "Error: Unknown command '[red]'\nRun 'help' to see available commands.\n"],
            $this->dispatch($file, ['cli', '[red]']),
        );
    }

    public static function invalidEntryProvider(): array {
        return [
            'missing handler'      => ["'bad' => ['description' => 'Bad'],", "Invalid command 'bad'", "'handler' must be callable"],
            'uncallable handler'   => ["'bad' => ['description' => 'Bad', 'handler' => 'spl_no_such_function'],", "Invalid command 'bad'", "'handler' must be callable"],
            'missing description'  => ["'bad' => ['handler' => fn() => null],", "Invalid command 'bad'", "'description' must be a string"],
            'integer description'  => ["'bad' => ['description' => 5, 'handler' => fn() => null],", "Invalid command 'bad'", "'description' must be a string"],
            'bare callable'        => ["'bad' => fn() => null,", "Invalid command 'bad'", "entry must be an array with 'description' and 'handler' keys"],
            'integer key'          => ["['description' => 'Bad', 'handler' => fn() => null],", "Invalid command '0'", 'name must be a non-empty string'],
        ];
    }

    #[DataProvider('invalidEntryProvider')]
    public function testInvalidEntryThrowsBeforeAnyCommandRuns(string $entry, string $command, string $problem): void {
        $file = $this->commandsFile("'ok' => ['description' => 'Ok', 'handler' => function () { echo 'ran'; }],\n{$entry}");
        $this->expectOutputString('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("{$command} in {$file}: {$problem}");

        $this->dispatch($file, ['cli', 'ok']);
    }

    public function testMissingCommandsFileThrows(): void {
        $file = sys_get_temp_dir() . '/spl-cli-missing-' . bin2hex(random_bytes(4)) . '.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Commands file not found: {$file}");

        $this->dispatch($file, ['cli']);
    }

    public function testCommandsFileMustReturnArray(): void {
        $file = tempnam(sys_get_temp_dir(), 'spl-cli-');
        $this->assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, "<?php return 'nope';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Commands file must return an array: {$file}");

        $this->dispatch($file, ['cli']);
    }

    public static function handleProvider(): array {
        return [
            'exit code' => [['three'], 3, 'three ran', ''],
            'null'      => [['none'], 0, '', ''],
            'help'      => [[], 0, "Available commands:\n  help   List available commands\n  none   None\n  three  Three\n", ''],
            'unknown'   => [['nope'], 1, '', "Error: Unknown command 'nope'\nRun 'help' to see available commands.\n"],
        ];
    }

    /**
     * @param array<int, string> $args
     */
    #[DataProvider('handleProvider')]
    public function testHandleExitsWithCommandExitCode(array $args, int $code, string $stdout, string $stderr): void {
        $result = $this->runHandle(<<<'PHP'
            'three' => ['description' => 'Three', 'handler' => function (array $options, array $args): ?int { echo 'three ran'; return 3; }],
            'none' => ['description' => 'None', 'handler' => fn(array $options, array $args): ?int => null],
            PHP, $args);

        $this->assertSame([$code, $stdout, $stderr], $result);
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
