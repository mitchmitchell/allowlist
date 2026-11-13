<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AllowlistListMutationTestHarness.php';
if ($argc < 5)
{
    fwrite(STDERR, "usage: list_mutation_member_peer.php <lockPath> <dbJsonPath> <listId> <number> [waitingPath]\n");
    exit(2);
}
$lockPath = $argv[1];
$dbPath = $argv[2];
$listId = $argv[3];
$number = $argv[4];
$waitingPath = $argc >= 6 ? $argv[5] : null;
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
$ok = $al->listMemberAdd((int)$listId, array('number' => $number, 'description' => 'peer'));
if ($ok === false)
{
    fwrite(STDERR, (string)$al->getLastError());
    exit(1);
}
file_put_contents($dbPath, json_encode($fp->astman->db));
echo '1';
