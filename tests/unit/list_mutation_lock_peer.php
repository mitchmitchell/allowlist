<?php
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__, 2);
require $root . '/Allowlist.class.php';
use FreePBX\modules\Allowlist;
if ($argc < 4)
{
    fwrite(STDERR, "usage: list_mutation_lock_peer.php <lockPath> <dbJsonPath> <listName> [readyPath]\n");
    exit(2);
}
$lockPath = $argv[1];
$dbPath = $argv[2];
$name = $argv[3];
$waitingPath = $argc >= 5 ? $argv[4] : null;
$fp = new FakeFreePBX();
if (is_readable($dbPath))
{
    $decoded = json_decode(file_get_contents($dbPath), true);
    if (is_array($decoded))
    {
        $fp->astman->db = $decoded;
    }
}
$al = new Allowlist($fp);
$al->listMutationLockPathOverride = $lockPath;
$al->listMutationDbReloadPath = $dbPath;
$al->listMutationLockWaitingPath = $waitingPath;
$id = $al->listCreate($name);
if ($id === false)
{
    fwrite(STDERR, (string)$al->getLastError());
    exit(1);
}
file_put_contents($dbPath, json_encode($fp->astman->db));
echo (string)$id;
