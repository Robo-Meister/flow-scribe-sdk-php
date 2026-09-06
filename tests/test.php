<?php
// Starts only a local HTTP fixture. No live OCR service or credentials are needed.
$directory = sys_get_temp_dir() . '/flowscribe-sdk-suite-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Unable to create test directory');
}
$server = null;
$tests = null;
$exitCode = 1;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to allocate local test port: ' . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment = getenv();
    $environment['FLOWSCRIBE_SDK_TEST_LOG'] = $directory . '/requests.jsonl';
    $server = proc_open(
        [PHP_BINARY, '-S', $address, __DIR__ . '/router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
        $pipes, __DIR__, $environment
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start fixture server');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (!proc_get_status($server)['running']) {
            break;
        }
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException('Fixture server did not start: ' . file_get_contents($directory . '/server.log'));
    }
    $tests = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', __DIR__ . '/run.php', 'http://' . $address],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes, __DIR__, $environment
    );
    if (!is_resource($tests)) {
        throw new RuntimeException('Unable to start SDK tests');
    }
    $exitCode = proc_close($tests);
    $tests = null;
} finally {
    if (is_resource($tests)) {
        proc_terminate($tests);
        proc_close($tests);
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
exit($exitCode);
