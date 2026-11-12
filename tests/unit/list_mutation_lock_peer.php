<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AllowlistListMutationTestHarness.php';
if ($argc < 4)
{
    fwrite(STDERR, "usage: list_mutation_lock_peer.php <lockPath> <dbJsonPath> <listName> [waitingPath]\n");
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
$al = new \FreePBX\modules\AllowlistListMutationTestHarness($fp);
$al->testListMutationLockPath = $lockPath;
$al->testListMutationDbReloadPath = $dbPath;
$al->testListMutationLockWaitingPath = $waitingPath;
$id = $al->listCreate($name);
if ($id === false)
{
    fwrite(STDERR, (string)$al->getLastError());
    exit(1);
}
file_put_contents($dbPath, json_encode($fp->astman->db));
echo (string)$id;
