<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$port = 28383;
$tmpDir = sys_get_temp_dir().'/codes_sh_smoke_'.bin2hex(random_bytes(6));
mkdir($tmpDir.'/repos', 0755, true);
mkdir($tmpDir.'/lfs', 0755, true);

$dbPath = $tmpDir.'/soft-serve.db';
$dbUrl = 'sqlite:///'.$dbPath;
$publicUrl = sprintf('http://127.0.0.1:%d', $port);

$envLocalPath = __DIR__.'/../.env.local';
file_put_contents($envLocalPath, sprintf(
    "DATABASE_URL=\"%s\"\nSOFT_SERVE_DATA_PATH=\"%s\"\nSOFT_SERVE_HTTP_PUBLIC_URL=\"%s\"\n",
    $dbUrl,
    $tmpDir,
    $publicUrl,
));

// Re-read .env.local into environment
(new Dotenv())->loadEnv(__DIR__.'/../.env');

echo "=== 1. Running database migrations in temp database ===\n";
$migProc = new Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], __DIR__.'/..');
$migProc->mustRun();

echo "=== 2. Bootstrapping users, tokens, and repositories ===\n";
$kernel = new App\Kernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();
echo "=== 1b. Running legacy migrations for server hooks ===\n";
$legacyDm = new App\Service\Database\DatabaseManager(dataPath: $tmpDir);
$legacyDm->migrate();

echo "=== 2. Bootstrapping users, tokens, and repositories ===\n";
$em = $container->get('doctrine.orm.entity_manager');
$now = new DateTimeImmutable();
$admin = new User('admin-smoke', true, $now);
$smoker = new User('smoker', false, $now);
$intruder = new User('intruder', false, $now);
$em->persist($admin);
$em->persist($smoker);
$em->persist($intruder);
$em->flush();

[$token1] = AccessToken::issue($smoker, 'smoketoken', null, $now);
$refl = new ReflectionProperty($token1, 'tokenHash');
$refl->setValue($token1, AccessToken::hash('smoketoken123'));
$em->persist($token1);

[$token2] = AccessToken::issue($intruder, 'intrudertoken', null, $now);
$refl2 = new ReflectionProperty($token2, 'tokenHash');
$refl2->setValue($token2, AccessToken::hash('intrudertoken123'));
$em->persist($token2);
$em->flush();

// Create pubdemo & privdemo
$pubRepo = new Repo(RepoName::fromInput('pubdemo'), $smoker, 'pubdemo', 'Public demo', false, false, false, null, $now);
$privRepo = new Repo(RepoName::fromInput('privdemo'), $smoker, 'privdemo', 'Private demo', true, false, false, null, $now);
$em->persist($pubRepo);
$em->persist($privRepo);
$em->flush();

// Initialize bare repos on disk
$pubPath = $tmpDir.'/repos/pubdemo.git';
$privPath = $tmpDir.'/repos/privdemo.git';
(new Process(['git', 'init', '--bare', $pubPath]))->mustRun();
(new Process(['git', 'init', '--bare', $privPath]))->mustRun();

// Create a commit in pubdemo so it has a valid branch to clone
$workDir = $tmpDir.'/pub_work';
mkdir($workDir);
(new Process(['git', 'init', '-b', 'main'], $workDir))->mustRun();
(new Process(['git', 'config', 'user.name', 'Smoker'], $workDir))->mustRun();
(new Process(['git', 'config', 'user.email', 'smoke@example.com'], $workDir))->mustRun();
file_put_contents($workDir.'/README.md', '# Public Demo');
(new Process(['git', 'add', '.'], $workDir))->mustRun();
(new Process(['git', 'commit', '-m', 'Initial commit'], $workDir))->mustRun();
(new Process(['git', 'push', $pubPath, 'main'], $workDir))->mustRun();
(new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/main'], $pubPath))->mustRun();

$kernel->shutdown();

echo "=== 3. Starting php -S 127.0.0.1:{$port} ===\n";
$serverProc = new Process(['php', '-S', sprintf('127.0.0.1:%d', $port), '-t', 'public', 'public/index.php'], __DIR__.'/..');
$serverProc->start();
usleep(400000); // 400ms wait for server to bind

try {
    echo "=== 4. Push-to-create via git push http://smoker:smoketoken123@.../demo.git ===\n";
    $pushDir = $tmpDir.'/push_work';
    mkdir($pushDir);
    (new Process(['git', 'init', '-b', 'main'], $pushDir))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Smoker'], $pushDir))->mustRun();
    (new Process(['git', 'config', 'user.email', 'smoke@example.com'], $pushDir))->mustRun();
    file_put_contents($pushDir.'/hello.txt', 'Push to create works!');
    (new Process(['git', 'add', '.'], $pushDir))->mustRun();
    (new Process(['git', 'commit', '-m', 'Push to create commit'], $pushDir))->mustRun();

    $pushUrl = sprintf('http://smoker:smoketoken123@127.0.0.1:%d/demo.git', $port);
    $pushProc = new Process(['git', 'push', $pushUrl, 'main'], $pushDir);
    $pushProc->mustRun();
    echo "Push-to-create output:\n".$pushProc->getErrorOutput()."\n";

    echo "=== 5. Anonymous clone of a public repo ===\n";
    $cloneDir = $tmpDir.'/clone_anon';
    $cloneUrl = sprintf('http://127.0.0.1:%d/pubdemo.git', $port);
    $cloneProc = new Process(['git', 'clone', $cloneUrl, $cloneDir]);
    $cloneProc->mustRun();
    echo "Anonymous clone output:\n".$cloneProc->getErrorOutput()."\n";
    echo 'README content in cloned repo: '.trim((string) file_get_contents($cloneDir.'/README.md'))."\n";

    echo "=== 6. 404 for a private repo with another user (denyRead) ===\n";
    $curlProc = new Process([
        'curl',
        '-s',
        '-o',
        '/dev/null',
        '-w',
        '%{http_code}',
        '-H',
        'Authorization: Token intrudertoken123',
        sprintf('http://127.0.0.1:%d/privdemo.git/info/refs?service=git-upload-pack', $port),
    ]);
    $curlProc->mustRun();
    $statusCode = trim($curlProc->getOutput());
    echo "HTTP Status for intruder accessing private repo: {$statusCode}\n";
    if ('404' !== $statusCode) {
        throw new RuntimeException("Expected 404, got {$statusCode}");
    }

    echo "=== 7. LFS batch upload + basic upload + download with curl ===\n";
    $lfsPayload = "Smoke test LFS binary content 2026\n";
    $oid = hash('sha256', $lfsPayload);
    $size = strlen($lfsPayload);

    // a. Batch request
    $batchPayload = json_encode([
        'operation' => 'upload',
        'transfers' => ['basic'],
        'objects' => [['oid' => $oid, 'size' => $size]],
    ]);

    $batchCurl = new Process([
        'curl',
        '-s',
        '-X',
        'POST',
        '-H',
        'Authorization: Token smoketoken123',
        '-H',
        'Accept: application/vnd.git-lfs+json',
        '-H',
        'Content-Type: application/vnd.git-lfs+json',
        '-d',
        $batchPayload,
        sprintf('http://127.0.0.1:%d/demo.git/info/lfs/objects/batch', $port),
    ]);
    $batchCurl->mustRun();
    $batchResp = $batchCurl->getOutput();
    echo "LFS Batch response:\n{$batchResp}\n";

    // b. Basic upload
    $uploadCurl = new Process([
        'curl',
        '-s',
        '-X',
        'PUT',
        '-H',
        'Authorization: Token smoketoken123',
        '-H',
        'Content-Length: '.$size,
        '-d',
        $lfsPayload,
        sprintf('http://127.0.0.1:%d/demo.git/info/lfs/objects/basic/%s', $port, $oid),
    ]);
    $uploadCurl->mustRun();

    // c. Basic download
    $downloadCurl = new Process([
        'curl',
        '-s',
        '-X',
        'GET',
        '-H',
        'Authorization: Token smoketoken123',
        sprintf('http://127.0.0.1:%d/demo.git/info/lfs/objects/basic/%s', $port, $oid),
    ]);
    $downloadCurl->mustRun();
    $downloaded = $downloadCurl->getOutput();
    echo "LFS Downloaded content:\n{$downloaded}\n";
    if ($downloaded !== $lfsPayload) {
        throw new RuntimeException('Downloaded payload mismatch!');
    }

    echo "=== SMOKE TEST COMPLETED SUCCESSFULLY! ===\n";
} finally {
    $serverProc->stop();
    @unlink($envLocalPath);
    (new Process(['rm', '-rf', $tmpDir]))->run();
}
