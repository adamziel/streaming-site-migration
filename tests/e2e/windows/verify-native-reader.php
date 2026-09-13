<?php
/** Checks the actual reader before running the cross-host migration. */
use WordPress\Reprint\Server\WindowsFilesystem;
use function WordPress\Reprint\Server\source_io_path;

require dirname(__DIR__, 3) . '/packages/reprint-server/src/utils.php';
require dirname(__DIR__, 3) . '/packages/reprint-server/src/class-windows-filesystem.php';

if (!WindowsFilesystem::available()) {
    throw new RuntimeException('The Windows CI host must provide the native source reader.');
}
$path = WindowsFilesystem::resolve_input('\\\\?\\D:\\Reprint namespace cases\\Mixed Case\\trailing.');
$uri = source_io_path($path);
$stat = lstat($uri);
if ($stat['size'] !== 17 || ($stat['mode'] & 0170000) !== 0100000) {
    throw new RuntimeException('Native stat must describe the literal file.');
}
$file = fopen($uri, 'rb');
$first = fread($file, 8);
$offset = ftell($file);
$seek = fseek($file, 3);
$tail = fread($file, 14);
if ($first !== 'literal ' || $offset !== 8 || $seek !== 0 || $tail !== 'eral trailing.') {
    throw new RuntimeException('Native read/seek mismatch: ' . json_encode(compact('path', 'first', 'offset', 'seek', 'tail')));
}
fclose($file);
$before = hash_file('sha256', $uri);
foreach (['w', 'a', 'r+'] as $mode) {
    try {
        fopen($uri, $mode);
        throw new LogicException('The native source reader accepted a write mode: ' . $mode);
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), 'read-only') === false) {
            throw $error;
        }
    }
}
if (hash_file('sha256', $uri) !== $before) {
    throw new RuntimeException('Rejected write modes changed the source file.');
}
$names = scandir(source_io_path(dirname($path)));
foreach (['trailing', 'trailing.', 'trailing ', 'NUL.txt', 'COM1.txt', 'COM¹.txt'] as $name) {
    if (!in_array($name, $names, true)) {
        throw new RuntimeException('Native directory search omitted ' . $name);
    }
}
foreach (['\\\\.\\PhysicalDrive0', '\\\\.\\pipe\\reprint-test'] as $device) {
    try {
        WindowsFilesystem::resolve_input($device);
        throw new LogicException('The source reader accepted a device: ' . $device);
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), 'Windows device names cannot select migration files') === false) {
            throw $error;
        }
    }
}

// A host may tighten open_basedir after initialization. Retained registration
// must not make a later native open escape that PHP restriction.
ini_set('open_basedir', __DIR__);
if (WindowsFilesystem::available()) {
    throw new RuntimeException('Native source reads must be disabled under open_basedir.');
}
try {
    WindowsFilesystem::resolve_input('D:/');
    throw new LogicException('Native path resolution bypassed open_basedir.');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'open_basedir unset') === false) {
        throw $error;
    }
}
echo "PASS: native stat, bounded reads, seek, exact names, read-only modes, device rejection, and open_basedir.\n";
