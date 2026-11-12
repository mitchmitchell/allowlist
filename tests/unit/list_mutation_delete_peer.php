<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AllowlistListMutationTestHarness.php';
if ($argc < 3)
{
    fwrite(STDERR, "usage: list_mutation_delete_peer.php <lockPath> <dbJsonPath> <listId> [waitingPath]\n");
    exit(2);
}
$lockPath = $argv[1];
$dbPath = $argv[2];
$listId = $argv[3];
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
$ok = $al->listDelete((int)$listId);
if ($ok === false)
{
    fwrite(STDERR, (string)$al->getLastError());
    exit(1);
}
file_put_contents($dbPath, json_encode($fp->astman->db));
echo '1';
