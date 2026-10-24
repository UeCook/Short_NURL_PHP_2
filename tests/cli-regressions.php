<?php
// 在临时项目副本中启动真实 CLI/HTTP fixture，不触碰部署配置和密钥。
if (!function_exists('assert_true')) {
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

if (!function_exists('assert_equals')) {
    function assert_equals($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' (expected: ' . var_export($expected, true) . ', actual: ' . var_export($actual, true) . ')');
        }
    }
}

if (!function_exists('removeRegressionFixture')) {
    function removeRegressionFixture(string $dir): void
    {
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            if (is_dir($path)) removeRegressionFixture($path);
            else unlink($path);
        }
        rmdir($dir);
    }
}

function runRegressionCli(string $dir, string $script, array $args): array
{
    $process = proc_open(array_merge([PHP_BINARY, $dir . '/' . $script], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $dir);
    if (!is_resource($process)) throw new RuntimeException('无法启动 CLI fixture');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

echo "▸ CLI 子进程回归测试\n";
$cliDir = sys_get_temp_dir() . '/shorturl-cli-' . bin2hex(random_bytes(6));
mkdir($cliDir . '/core', 0700, true);
mkdir($cliDir . '/cli', 0700, true);
$server = null;
try {
    foreach (['nurl', 'nurl-key'] as $name) copy(__DIR__ . '/../cli/' . $name, $cliDir . '/cli/' . $name);
    mkdir($cliDir . '/core/src', 0700, true);
    file_put_contents($cliDir . '/core/src/autoload.php', '<?php require ' . var_export(realpath(__DIR__ . '/../core/src/autoload.php'), true) . ';');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException($error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $cfg = require __DIR__ . '/../core/config.php';
    $cfg['domain'] = 'http://' . $address;
    $cfg['keys_path'] = $cliDir . '/keys.json';
    $cfg['internal_token_path'] = $cliDir . '/internal_token';
    $cfg['perm_path'] = $cliDir . '/perm.json';
    $cfg['temp_path'] = $cliDir . '/temp.json';
    $cfg['onetime_pool_size'] = 2;
    file_put_contents($cliDir . '/core/config.php', '<?php return ' . var_export($cfg, true) . ';');

    foreach (['nurl', 'nurl-key'] as $entry) {
        [$exit, $out] = runRegressionCli($cliDir, 'cli/' . $entry, ['-h']);
        assert_equals(0, $exit, "$entry 无后缀帮助入口");
    }
    [$exit] = runRegressionCli($cliDir, 'cli/nurl', ['-new']);
    assert_equals(0, $exit, 'nurl -new 初始化');
    $before = json_decode(file_get_contents($cfg['keys_path']), true);
    $tokenBefore = file_get_contents($cfg['internal_token_path']);
    [$exit] = runRegressionCli($cliDir, 'cli/nurl', ['-new', '-full']);
    $after = json_decode(file_get_contents($cfg['keys_path']), true);
    assert_equals(0, $exit, '-new -full 成功');
    assert_true($before['resident'] === $after['resident'], '-new -full 不轮换常驻密钥');
    assert_true($tokenBefore === file_get_contents($cfg['internal_token_path']), '-new -full 不轮换内部令牌');

    [$exit] = runRegressionCli($cliDir, 'cli/nurl', ['-new', '-long']);
    $after = json_decode(file_get_contents($cfg['keys_path']), true);
    assert_equals(0, $exit, '-new -long 成功');
    assert_true($before['resident']['key_hash'] !== $after['resident']['key_hash'], '-new -long 轮换常驻密钥');
    assert_true($before['onetime_pool'] === $after['onetime_pool'], '-new -long 不修改一次性池');
    $beforeRaw = file_get_contents($cfg['keys_path']);
    [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl', ['-new', '-long', '-full']);
    assert_equals(1, $exit, '冲突修饰标志返回退出码 1');
    assert_true(str_contains($err, '不能同时'), '冲突标志输出明确错误');
    assert_true($beforeRaw === file_get_contents($cfg['keys_path']), '冲突标志不修改密钥');

    [$exit, $out] = runRegressionCli($cliDir, 'cli/nurl', ['-svc', '-look']);
    assert_equals(0, $exit, '-svc 优先于 -look');
    assert_true(str_contains($out, '新服务密钥'), '实际生成服务密钥');
    [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl', ['-svc']);
    assert_equals(1, $exit, '重复 svc 友好失败');
    assert_true(str_contains($err, '-drop -svc') && !str_contains($err, 'Stack trace'), '重复 svc 显示修复命令而非堆栈');
    [$exit, $out] = runRegressionCli($cliDir, 'cli/nurl', ['-itk', '-look']);
    assert_equals(0, $exit, '-itk 优先于 -look');
    assert_true($tokenBefore !== file_get_contents($cfg['internal_token_path']), '-itk 确实轮换令牌');
    [$exit, $out] = runRegressionCli($cliDir, 'cli/nurl', ['-look']);
    assert_true(str_contains($out, 'LPA-Key') && str_contains($out, '已设置'), '-look 显示内部令牌状态');

    foreach ([['-ttl', 'abc'], ['-ttl', '-1'], ['-ttl', '1.5'], ['-ttl', '999999999999999999999'], ['-len', '0'], ['-len', '6']] as $invalid) {
        [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl-key', array_merge(['-key', 'su_test', '-add', '-url', 'https://example.com'], $invalid));
        assert_equals(1, $exit, '非法 ' . implode(' ', $invalid) . ' 拒绝');
        assert_true(str_contains($err, $invalid[0]) && !str_contains($err, 'cURL'), '参数错误在网络请求前返回');
    }
    [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl-key', ['-key', '-list']);
    assert_equals(1, $exit, '缺 key 值退出 1');
    assert_true(str_contains($err, '缺少参数值'), '不吞下一个标志');
    [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl-key', ['-key:su_test', '-add', '-url:https://example.com', '-ttl']);
    assert_equals(1, $exit, '显式 ttl 缺值不静默使用永久链');

    file_put_contents($cliDir . '/router.php', <<<'PHP'
<?php
header('Content-Type: application/json');
$request = ['path' => $_SERVER['REQUEST_URI'], 'body' => json_decode(file_get_contents('php://input'), true), 'key' => $_SERVER['HTTP_X_HEADLESS_TOKEN'] ?? null];
file_put_contents(__DIR__ . '/request.json', json_encode($request));
echo json_encode(['short_url' => 'https://short.example.com/test', 'exp' => null, 'dedup' => false, 'synced' => false, 'warning' => 'fixture-sync-warning', 'key_consumed' => true, 'id' => 'test', 'url' => 'https://example.com', 'lurl' => 'https://short.example.com/test', 'ok' => true]);
PHP
    );
    $server = proc_open([PHP_BINARY, '-S', $address, $cliDir . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $cliDir . '/server.out', 'a'], 2 => ['file', $cliDir . '/server.err', 'a']], $pipes, $cliDir);
    if (!is_resource($server)) throw new RuntimeException('无法启动测试 HTTP 服务');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('测试 HTTP 服务未就绪');
    [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl-key', ['-key:su_test', '-add', '-url:https://example.com', '-ttl:60', '-len:5']);
    assert_equals(0, $exit, '冒号语法创建及降级成功退出 0');
    $request = json_decode(file_get_contents($cliDir . '/request.json'), true);
    assert_equals(60, $request['body']['ttl'], '冒号 ttl 正确发送整数');
    assert_equals(5, $request['body']['len'], '冒号 len 正确发送');
    assert_equals('su_test', $request['key'], '冒号 key 正确发送');
    assert_true(str_contains($err, 'fixture-sync-warning'), 'CLI 创建告警写 STDERR');
    assert_true(str_contains($out, '已消费一次性 Key'), 'CLI 恢复消费提示');
    [$exit] = runRegressionCli($cliDir, 'cli/nurl-key', ['-key:su_test', '-get:test']);
    $request = json_decode(file_get_contents($cliDir . '/request.json'), true);
    assert_equals(0, $exit, '-get:code 正确路由');
    assert_equals('/headless/api/get/test', $request['path'], 'get 冒号参数路径');
    [$exit, $out, $err] = runRegressionCli($cliDir, 'cli/nurl-key', ['-key', 'su_test', '-rm', '-code', 'test']);
    assert_equals(0, $exit, '删除降级退出 0');
    assert_true(str_contains($err, 'fixture-sync-warning'), '删除告警写 STDERR');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    removeRegressionFixture($cliDir);
}
