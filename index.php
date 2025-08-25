<?php
session_start();

// ================== 配置区域 ==================
@mkdir('attempts', 0755, true);
@mkdir('attempts/ip_lock', 0755, true);
@mkdir('attempts/ip_attempts', 0755, true);
@mkdir('public_notes', 0755, true);
@mkdir('review_queue', 0755, true);

// 🔐 安全配置
define('ENCRYPTION_METHOD', 'aes-256-cbc');        // 加密方法
define('IV', '1234567890987654');                   // 初始化向量
define('LOCK_DURATION', 300);                       // IP锁定持续时间（秒）
define('MAX_FILE_SIZE', 51200);                     // 最大文件大小（50KB）
define('BASE_URL', 'https://eazynote.free.nf');     // 基础URL

// 🤖 AI审核API配置
define('DEFAULT_API_KEY_1', 'cpk_');
define('DEFAULT_API_KEY_2', 'sk-');
define('API_URL_1', 'https://llm.chutes.ai/v1/chat/completions');
define('API_URL_2', 'https://api.suanli.cn/v1/chat/completions');
define('DEFAULT_MODEL_1', 'Qwen/Qwen3-Coder-30B-A3B-Instruct');  // 主模型
define('DEFAULT_MODEL_2', 'free:Qwen3-30B-A3B');                 // 备用模型

// 📋 审核参数配置
define('REVIEW_MAX_ATTEMPTS', 2);       // 最大审核尝试次数
define('REVIEW_TIMEOUT', 50);            // 请求超时时间（秒）
define('REVIEW_TEMPERATURE', 0.1);       // 模型温度参数
define('REVIEW_MAX_TOKENS', 50);         // 最大响应token数
define('REVIEW_PROMPT', "请你判断下列文本是否包含违法、色情、反社会、违反道德或低俗的内容，你可以宽容一些，除非严重违规，尽量通过，并仅以以下格式中的一个回复：
如果合规，请回复：tg:y
如果不合规，请回复：tg:n
需要审核的内容：
");

// ================== 函数定义 ==================

function is_ip_locked($ip) {
    $lock_file = "attempts/ip_lock/" . $ip;
    if (file_exists($lock_file)) {
        $expire_time = (int)file_get_contents($lock_file);
        if (time() < $expire_time) {
            return true;
        } else {
            unlink($lock_file);
        }
    }
    return false;
}

function reset_fail_count($ip) {
    $attempts_file = "attempts/ip_attempts/" . $ip;
    $lock_file = "attempts/ip_lock/" . $ip;
    @unlink($attempts_file);
    @unlink($lock_file);
}

function increment_fail_count($ip) {
    $attempts_file = "attempts/ip_attempts/" . $ip;
    $count = 1;
    if (file_exists($attempts_file)) {
        $count = (int)file_get_contents($attempts_file) + 1;
    }
    file_put_contents($attempts_file, $count);
    if ($count >= 5) {
        $lock_file = "attempts/ip_lock/" . $ip;
        file_put_contents($lock_file, time() + LOCK_DURATION);
        @unlink($attempts_file);
    }
}

function can_register($ip) {
    $file = "attempts/ip_attempts/reg_" . $ip;
    if (file_exists($file)) {
        $last_register_time = (int)file_get_contents($file);
        if (time() - $last_register_time < 60) {
            return false;
        }
    }
    return true;
}

function log_register_attempt($ip) {
    $file = "attempts/ip_attempts/reg_" . $ip;
    file_put_contents($file, time());
}

function get_review_status($username) {
    $queue_file = "review_queue/{$username}.review";
    if (!file_exists($queue_file)) {
        return 'none';
    }
    
    $log = file_get_contents($queue_file);
    if (strpos($log, '审核通过') !== false) {
        return 'approved';
    }
    if (strpos($log, '审核拒绝') !== false) {
        return 'rejected';
    }
    if (strpos($log, '开始审核') !== false || strpos($log, '等待重新审核') !== false) {
        return 'pending';
    }
    return 'none';
}

function is_user_shared($username) {
    $public_file = 'public.txt';
    if (!file_exists($public_file)) return false;
    
    $public_users = file($public_file, FILE_IGNORE_NEW_LINES);
    return in_array($username, $public_users);
}

function toggle_share($username, $force = false) {
    $public_file = 'public.txt';
    $public_users = [];
    
    if (file_exists($public_file)) {
        $public_users = file($public_file, FILE_IGNORE_NEW_LINES);
    }
    
    $key = array_search($username, $public_users);
    $is_shared = ($key !== false);
    
    if ($is_shared && !$force) {
        unset($public_users[$key]);
        file_put_contents($public_file, implode("\n", array_values($public_users)));
        @unlink("public_notes/{$username}.txt");
        return false;
    } else {
        if (!$is_shared) {
            $public_users[] = $username;
            file_put_contents($public_file, implode("\n", array_values($public_users)));
        }
        return true;
    }
}

function get_public_users() {
    $public_file = 'public.txt';
    $users = [];
    if (file_exists($public_file)) {
        $users = file($public_file, FILE_IGNORE_NEW_LINES);
    }
    return array_filter($users);
}

function get_top_users() {
    $top_file = 'top.txt';
    $users = [];
    if (file_exists($top_file)) {
        $users = file($top_file, FILE_IGNORE_NEW_LINES);
    }
    return array_filter($users);
}

function create_public_version($username, $content) {
    file_put_contents("public_notes/{$username}.txt", $content);
}

function delete_public_version($username) {
    @unlink("public_notes/{$username}.txt");
}

function ai_review_content($content) {
    $max_attempts = REVIEW_MAX_ATTEMPTS;
    $attempt = 0;
    $review_id = bin2hex(random_bytes(8));
    
    $prompt = REVIEW_PROMPT . $content;

    $headers1 = [
        'Authorization: Bearer ' . DEFAULT_API_KEY_1,
        'Content-Type: application/json'
    ];

    $data1 = json_encode([
        "model" => DEFAULT_MODEL_1,
        "messages" => [["role" => "user", "content" => $prompt]],
        "stream" => false,
        "max_tokens" => REVIEW_MAX_TOKENS,
        "temperature" => REVIEW_TEMPERATURE
    ]);

    $ch = curl_init();

    while ($attempt < $max_attempts) {
        $url = ($attempt == 0) ? API_URL_1 : API_URL_2;
        
        $headers = ($attempt == 0) ? $headers1 : [
            'Authorization: Bearer ' . DEFAULT_API_KEY_2,
            'Content-Type: application/json'
        ];

        $data = ($attempt == 0) ? $data1 : json_encode([
            "model" => DEFAULT_MODEL_2,
            "messages" => [["role" => "user", "content" => $prompt]],
            "stream" => false,
            "temperature" => REVIEW_TEMPERATURE
        ]);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => REVIEW_TIMEOUT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $attempt++;
            continue;
        }

        $result = json_decode($response, true);
        
        file_put_contents("review_queue/debug.log", 
            "===== 审核ID: {$review_id} =====\n" .
            "API响应 (尝试 #{$attempt}):\n" .
            "URL: " . ($attempt == 0 ? API_URL_1 : API_URL_2) . "\n" .
            "完整响应: " . print_r($response, true) . "\n" .
            "--------------------------------\n", 
            FILE_APPEND);

        file_put_contents("review_queue/debug.log", 
            "解码后的结果: " . print_r($result, true) . "\n", 
            FILE_APPEND);
            
        if (isset($result['choices'][0]['message']['content'])) {
            $reply = trim($result['choices'][0]['message']['content']);
            
            file_put_contents("review_queue/debug.log", 
                "原始回复内容: " . $reply . "\n" .
                "正在查找tg:y或tg:n...\n",
                FILE_APPEND);
            
            if (preg_match('/tg:\s*y/i', $reply)) {
                file_put_contents("review_queue/debug.log", "找到tg:y，审核通过\n", FILE_APPEND);
                curl_close($ch);
                return true;
            }
            
            if (preg_match('/tg:\s*n/i', $reply)) {
                file_put_contents("review_queue/debug.log", "找到tg:n，审核拒绝\n", FILE_APPEND);
                curl_close($ch);
                return false;
            }
            
            file_put_contents("review_queue/debug.log", "未找到tg:y或tg:n，审核拒绝\n", FILE_APPEND);
        }
        
        $attempt++;
    }
    
    file_put_contents("review_queue/debug.log", 
    "所有尝试结束. review_id: {$review_id}\n" .
    "--------------------------------\n\n", 
    FILE_APPEND);
    
    curl_close($ch);
    return false;
}

// 处理分享操作（AI审核 + 用户等待）
if (isset($_GET['action']) && $_GET['action'] == 'share' && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $content = $_SESSION['content'];
    $queue_file = "review_queue/{$username}.review";
    
    $review_status = get_review_status($username);
    
    // 1. 如果已公开，处理取消公开
    if (is_user_shared($username)) {
        if ($review_status === 'pending') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => '内容正在审核中，无法取消分享！',
                'action' => 'pending'
            ]);
            exit;
        }
        
        toggle_share($username);
        delete_public_version($username);
        
        if (file_exists($queue_file)) {
            file_put_contents($queue_file, "审核任务已取消\n");
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => '已取消公开分享',
            'action' => 'unshare'
        ]);
        exit;
    }
    
    // 2. 检查是否已在审核中
    if ($review_status === 'pending') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => '内容正在审核中，请勿重复提交！',
            'action' => 'pending'
        ]);
        exit;
    }
    
    // 3. 新审核任务
    file_put_contents($queue_file, date('Y-m-d H:i:s') . " - 开始审核\n");
    
    // 4. 执行审核
    $approved = ai_review_content($content);
    
    if ($approved) {
        file_put_contents($queue_file, date('Y-m-d H:i:s') . " - 审核通过\n", FILE_APPEND);
        toggle_share($username);
        create_public_version($username, $content);
        @unlink($queue_file);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => '已通过审核并成功公开分享！',
            'action' => 'shared'
        ]);
    } else {
        file_put_contents($queue_file, date('Y-m-d H:i:s') . " - 审核拒绝：内容不符合要求\n", FILE_APPEND);
        @unlink($queue_file);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => '审核未通过，内容可能存在违规问题',
            'action' => 'rejected'
        ]);
    }
    exit;
}

// 处理公开访问
if (isset($_GET['open'])) {
    $username = $_GET['open'];
    $is_shared = is_user_shared($username);
    
    if (!$is_shared) {
        die("<script>alert('该用户未公开分享笔记！');window.location.href='?';</script>");
    }
    
    $public_content = '';
    if (file_exists("public_notes/{$username}.txt")) {
        $public_content = file_get_contents("public_notes/{$username}.txt");
    } else {
        $queue_file = "review_queue/$username.review";
        if (file_exists($queue_file)) {
            $log = file_get_contents($queue_file);
            if (strpos($log, '审核通过') !== false) {
                die("<script>alert('内容已通过审核，正在生成中，请稍后再试');window.location.href='?';</script>");
            }
            die("<script>alert('该笔记正在审核中，请稍后再查看');window.location.href='?';</script>");
        } else {
            die("<script>alert('内容不存在！');window.location.href='?';</script>");
        }
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?=$username?>的公开笔记 - EasyNote</title>
        <link rel="stylesheet" href="./edit/index.css" media="none" onload="if(media!='all')media='all'">
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #f0f0f0;
                padding: 20px;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
                background-color: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }
            h2 {
                text-align: center;
                color: #333;
            }
            .content-display {
                min-height: 300px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .actions {
                text-align: center;
                margin-top: 20px;
            }
            button {
                background-color: #007bff;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
            }
            button:hover {
                background-color: #0069d9;
            }
            .loading {
                text-align: center;
                padding: 20px;
                color: #666;
            }
            #content-textarea {
                display: none;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>欢迎访问 <?=$username?> 的公开笔记</h2>
            <textarea id="content-textarea"><?=htmlspecialchars($public_content)?></textarea>
            <div id="vditor-container">
                <div class="loading">编辑器加载中...本提示消失后可能仍需耐心等待一小会</div>
            </div>
            <div class="actions">
                <button onclick="window.location.href='?'">返回首页</button>
            </div>
        </div>
        
        <script src="./edit/index.min.js" async></script>
        <script>
        var vditor = null;
        
        function initVditor() {
            var content = document.getElementById('content-textarea').value || '';
            var container = document.getElementById('vditor-container');
            
            container.innerHTML = '';
            
            vditor = new Vditor('vditor-container', {
                value: content,
                height: 500,
                mode: 'ir',
                preview: {
                    mode: 'both'
                },
                cache: {
                    enable: false
                },
                toolbar: [
                    'emoji',
                    'headings',
                    'bold',
                    'italic',
                    'strike',
                    'link',
                    '|',
                    'list',
                    'ordered-list',
                    'check',
                    'outdent',
                    'indent',
                    '|',
                    'quote',
                    'line',
                    'code',
                    'inline-code',
                    'insert-before',
                    'insert-after',
                    '|',
                    'table',
                    '|',
                    'undo',
                    'redo',
                    '|',
                    'fullscreen',
                    'edit-mode',
                    {
                        name: 'more',
                        toolbar: [
                            'both',
                            'preview',
                            'info',
                            'help',
                        ],
                    }
                ],
                after: function() {
                    console.log('Vditor loaded');
                }
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Vditor !== 'undefined') {
                initVditor();
            } else {
                var checkVditor = setInterval(function() {
                    if (typeof Vditor !== 'undefined') {
                        clearInterval(checkVditor);
                        initVditor();
                    }
                }, 100);
            }
        });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// 处理查看所有分享
if (isset($_GET['page']) && $_GET['page'] == 'allshares') {
    $top_users = get_top_users();
    $public_users = get_public_users();
    
    $top_map = array_flip($top_users);
    
    $top_list = [];
    $normal_list = [];
    
    foreach ($public_users as $user) {
        if (isset($top_map[$user])) {
            $top_list[] = $user;
        } else {
            $normal_list[] = $user;
        }
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>EasyNote - 公开分享</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #f0f0f0;
                padding: 20px;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
                background-color: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }
            h1 {
                text-align: center;
                color: #333;
            }
            .section {
                margin-bottom: 30px;
            }
            .section h2 {
                border-bottom: 1px solid #eee;
                padding-bottom: 5px;
                color: #007bff;
            }
            .user-list {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 10px;
            }
            .user-item {
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: #f9f9f9;
                text-align: center;
            }
            .user-item a {
                text-decoration: none;
                color: #007bff;
                font-weight: bold;
            }
            .user-item a:hover {
                text-decoration: underline;
            }
            .top-badge {
                background: #ffc107;
                color: #333;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 12px;
                margin-left: 5px;
            }
            .back-button {
                display: block;
                text-align: center;
                margin-top: 20px;
            }
            .back-button a {
                background-color: #007bff;
                color: white;
                padding: 10px 20px;
                border-radius: 4px;
                text-decoration: none;
                display: inline-block;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>所有公开分享的笔记</h1>
            
            <?php if (count($top_list) > 0): ?>
            <div class="section">
                <h2><span style="color:#ffc107">★</span> 置顶分享</h2>
                <div class="user-list">
                    <?php foreach ($top_list as $user): ?>
                    <div class="user-item">
                        <a href="?open=<?=$user?>"><?=$user?><span class="top-badge">置顶</span></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (count($normal_list) > 0): ?>
            <div class="section">
                <h2>全部分享</h2>
                <div class="user-list">
                    <?php foreach ($normal_list as $user): ?>
                    <div class="user-item">
                        <a href="?open=<?=$user?>"><?=$user?></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (count($public_users) == 0): ?>
            <p style="text-align: center; padding: 20px; background: #f9f9f9; border-radius: 4px;">
                暂无公开分享的笔记
            </p>
            <?php endif; ?>
            
            <div class="back-button">
                <a href="?">返回首页</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 处理新建操作
if (isset($_POST['action']) && $_POST['action'] == 'new') {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    if (!can_register($ip)) {
        echo "<script>alert('每个IP每分钟只能注册一次，请稍后再试！');</script>";
        exit;
    }

    $username = $_POST['username'];
    $password = $_POST['password'];
    $content = $_POST['content'];

    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
        echo "<script>alert('用户名只能包含字母、数字、- _ . ！');</script>";
        exit;
    }

    if (empty($username) || empty($password) || empty($content)) {
        echo "<script>alert('所有字段都是必填的！');</script>";
    } elseif (!preg_match('/^[a-zA-Z0-9-_]{8,16}$/', $password)) {
        echo "<script>alert('密码必须为8-16位，只能包含字母、数字、-和_');</script>";
    } else {
        $filepath = "data/$username.txt";
        if (file_exists($filepath)) {
            echo "<script>alert('用户名已存在，请选择其他用户名！');</script>";
        } else {
            if (strlen($content) > MAX_FILE_SIZE) {
                echo "<script>alert('笔记内容不能超过 50KB！');</script>";
            } else {
                $encrypted_content = openssl_encrypt($content, ENCRYPTION_METHOD, $password, 0, IV);
                if ($encrypted_content === false) {
                    echo "<script>alert('加密内容失败！');</script>";
                } else {
                    if (file_put_contents($filepath, $encrypted_content) !== false) {
                        $_SESSION['username'] = $username;
                        $_SESSION['password'] = $password;
                        $_SESSION['content'] = $content;
                        
                        log_register_attempt($ip);
                        
                        echo "<script>alert('注册成功！');window.location.href='?';</script>";
                        exit;
                    } else {
                        echo "<script>alert('文件保存失败！');</script>";
                    }
                }
            }
        }
    }
}

// 处理查看操作（登录）
if (isset($_POST['action']) && $_POST['action'] == 'view') {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (is_ip_locked($ip)) {
        $lock_file = "attempts/ip_lock/" . $ip;
        $expire_time = (int)file_get_contents($lock_file);
        $remaining = $expire_time - time();
        $minutes = floor($remaining / 60);
        $seconds = $remaining % 60;
        echo "<script>alert('多次登陆失败，登录已被阻止，剩余时间：{$minutes}分{$seconds}秒');</script>";
        exit;
    }

    $username = $_POST['username'];
    $password = $_POST['password'];

    $valid_credentials = false;
    $filepath = "data/$username.txt";
    
    if (file_exists($filepath)) {
        $encrypted_content = file_get_contents($filepath);
        $decrypted_content = openssl_decrypt($encrypted_content, ENCRYPTION_METHOD, $password, 0, IV);
        if ($decrypted_content !== false) {
            $valid_credentials = true;
        }
    }

    if ($valid_credentials) {
        reset_fail_count($ip);
        $_SESSION['username'] = $username;
        $_SESSION['password'] = $password;
        $_SESSION['content'] = $decrypted_content;
        header("Location: ?" . time());
        exit;
    } else {
        increment_fail_count($ip);
        echo "<script>alert('用户名或密码错误！');</script>";
    }
}

// 处理保存操作
if (isset($_POST['action']) && $_POST['action'] == 'save') {
    if (isset($_SESSION['username']) && isset($_SESSION['password']) && isset($_POST['content'])) {
        $username = $_SESSION['username'];
        $password = $_SESSION['password'];
        $content = $_POST['content'];

        if (strlen($content) > MAX_FILE_SIZE) {
            echo "<script>alert('笔记内容不能超过 50KB！');</script>";
        } else {
            $encrypted_content = openssl_encrypt($content, ENCRYPTION_METHOD, $password, 0, IV);
            if ($encrypted_content === false) {
                echo "<script>alert('加密内容失败！');</script>";
            } else {
                $filepath = "data/$username.txt";
                if (file_put_contents($filepath, $encrypted_content) !== false) {
                    $_SESSION['content'] = $content;
                    
                    if (is_user_shared($username)) {
                        toggle_share($username);
                        delete_public_version($username);
                        @unlink("review_queue/$username.review");
                        
                        echo "<script>
                            alert('文件保存成功！内容已修改，不再公开分享。如需重新公开，请点击【公开分享】按钮。');
                            window.location.href = window.location.href;
                        </script>";
                        exit;
                    } else {
                        echo "<script>alert('文件保存成功！');</script>";
                    }
                } else {
                    echo "<script>alert('文件保存失败！');</script>";
                }
            }
        }
    } else {
        echo "<script>alert('未登录或内容为空！');</script>";
    }
}

// 处理删除操作
if (isset($_POST['action']) && $_POST['action'] == 'delete') {
    if (isset($_SESSION['username'])) {
        $username = $_SESSION['username'];
        $filepath = "data/$username.txt";
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        if (is_user_shared($username)) {
            delete_public_version($username);
            $public_users = get_public_users();
            $key = array_search($username, $public_users);
            if ($key !== false) {
                unset($public_users[$key]);
                file_put_contents('public.txt', implode("\n", array_values($public_users)));
            }
        }
        
        @unlink("review_queue/$username.review");
        
        session_destroy();
        echo "<script>alert('用户和文件已删除！');window.location.href='?';</script>";
        exit;
    } else {
        echo "<script>alert('未登录！');</script>";
    }
}

// 处理退出操作
if (isset($_POST['action']) && $_POST['action'] == 'logout') {
    session_destroy();
    header("Location: ?");
    exit();
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EasyNote——一款简单、便利的在线免费备忘录</title>
    <link rel="stylesheet" href="https://fakecaptcha.netlify.app/fakeCAPTCHA/fakeCAPTCHA.css">
    <link rel="stylesheet" href="./edit/index.css" media="none" onload="if(media!='all')media='all'">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h2 {
            text-align: center;
            color: #333;
        }
        input, textarea {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        button {
            background-color: #28a745;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #218838;
        }
        .error-msg {
            color: #d32f2f;
            font-size: 0.85em;
        }
        .actions {
            display: flex;
            justify-content: space-between;
        }
        .toggle-buttons {
            text-align: center;
            margin-bottom: 20px;
        }
        .toggle-buttons button {
            margin: 0 5px;
        }
        .active {
            background-color: #1e7e34;
        }
        #vditor-container {
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 10px 0;
            min-height: 400px;
            background: #fff;
        }
        #content-textarea {
            display: none;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        .share-info {
            text-align: center;
            padding: 10px;
            background-color: #e9f7fe;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
        }
        .share-info a {
            color: #007bff;
            text-decoration: none;
            margin-left: 10px;
        }
        .share-info a:hover {
            text-decoration: underline;
        }
        .all-shares {
            text-align: center;
            margin: 20px 0;
        }
        .all-shares a {
            color: #007bff;
            text-decoration: none;
        }
        .all-shares a:hover {
            text-decoration: underline;
        }
        .share-toggle {
            background-color: #007bff;
            color: white;
        }
        .share-toggle:hover {
            background-color: #0069d9;
        }
        .checking-status {
            margin: 15px 0;
            text-align: center;
            color: #007bff;
            font-weight: bold;
            padding: 10px;
            border-radius: 4px;
            background-color: #e9f7fe;
        }
        .checking-status.error {
            background-color: #fce8e6;
            color: #d93025;
        }
        .checking-status.success {
            background-color: #e6f4ea;
            color: #137333;
        }
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .review-notice {
            margin: 15px 0;
            padding: 10px;
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            color: #856404;
            text-align: center;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>EasyNote</h2>
    <p>一款简单、便利的在线免费备忘录</p>
    
    <?php if (!isset($_SESSION['username'])): ?>
        <div class="all-shares">
            <a href="?page=allshares">查看所有公开分享笔记</a>
        </div>
    <?php endif; ?>
    
    <?php if (!isset($_SESSION['username'])): ?>
        <div class="toggle-buttons">
            <button class="<?php echo (!isset($_GET['register']) ? 'active' : ''); ?>" onclick="window.location.href='?'">登录</button>
            <button class="<?php echo (isset($_GET['register']) ? 'active' : ''); ?>" onclick="window.location.href='?register'">注册</button>
        </div>
        
        <?php if (isset($_GET['register'])): ?>
            <form method="post" id="register-form">
                <input type="text" name="username" placeholder="用户名（a-z 0-9 _ - .）" required>
                <div class="error-msg">仅支持字母、数字及 . _ - 符号</div>
                <input type="password" name="password" placeholder="密码" required>
                <div class="error-msg">8-16位，仅支持字母、数字及 - _</div>
                <textarea id="content-textarea" name="content" placeholder="内容"></textarea>
                <div id="vditor-container">
                    <div class="loading">编辑器加载中...本提示消失后可能仍需耐心等待一小会</div>
                </div>
                <div id="captcha"></div>
                <div class="actions">
                    <button type="submit" name="action" value="new" onclick="syncContent(); return checkCaptcha()">注册</button>
                    <button type="button" onclick="window.location.href='?'">返回登录</button>
                </div>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="text" name="username" placeholder="用户名" required>
                <input type="password" name="password" placeholder="密码" required>
                <div id="captcha"></div>
                <div class="actions">
                    <button type="submit" name="action" value="view" onclick="return checkCaptcha()">登录</button>
                    <button type="button" onclick="window.location.href='?register'">注册新用户</button>
                </div>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p style="text-align: center;">欢迎回来，<strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></p>
        
        <?php 
        $is_shared = is_user_shared($_SESSION['username']);
        $review_status = get_review_status($_SESSION['username']);
        $shareStatus = $is_shared ? '已公开分享，公共访问链接:' : '未公开分享，若点击公开无反应请先刷新一次';
        $shareButton = $is_shared ? '取消公开' : '公开分享';
        $shareLink = $is_shared ? BASE_URL . '/?open=' . $_SESSION['username'] : '';
        ?>
        <div class="share-info">
            分享状态：<?=$shareStatus?>
            <?php if ($is_shared): ?>
                <a href="<?=$shareLink?>" target="_blank"><?=$shareLink?></a>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-bottom: 20px;">
            <button type="button" class="share-toggle" id="share-button"><?=$shareButton?></button>
        </div>

        <div class="checking-status" id="review-status" style="display: none;">
            <div class="loading-spinner"></div>
            <span id="status-text">正在审核，请耐心等待</span>
        </div>

        <?php if ($review_status === 'pending'): ?>
        <div class="review-notice">
            ⚠️ 内容正在审核中，请稍后查看结果...
        </div>
        <?php elseif ($review_status === 'rejected'): ?>
        <div class="review-notice" style="background-color: #fce8e6; color: #d93025;">
            ❌ 上次审核未通过，请修改内容后重新提交
        </div>
        <?php endif; ?>

        <form method="post" id="save-form">
            <textarea id="content-textarea" name="content"><?php echo htmlspecialchars($_SESSION['content']); ?></textarea>
            <div id="vditor-container">
                <div class="loading">编辑器加载中...本提示消失后可能仍需耐心等待一小会</div>
            </div>
            <div class="actions">
                <button type="submit" name="action" value="save" onclick="syncContent()">保存</button>
                <button type="submit" name="action" value="delete" onclick="return confirm('确定要删除账户和所有数据吗？')">删除</button>
                <button type="submit" name="action" value="logout">退出</button>
            </div>
        </form>

    <?php endif; ?>
</div>

<script src="https://fakecaptcha.netlify.app/fakeCAPTCHA/fakeCAPTCHA.js" async></script>
<script src="./edit/index.min.js" async></script>

<script>
var vditor = null;
var captcha = null;

function initVditor() {
    var content = document.getElementById('content-textarea').value || '';
    var container = document.getElementById('vditor-container');
    
    container.innerHTML = '';
    
    vditor = new Vditor('vditor-container', {
        value: content,
        height: 400,
        mode: 'ir',
        preview: {
            mode: 'both'
        },
        cache: {
            enable: false
        },
        toolbar: [
            'emoji',
            'headings',
            'bold',
            'italic',
            'strike',
            'link',
            '|',
            'list',
            'ordered-list',
            'check',
            'outdent',
            'indent',
            '|',
            'quote',
            'line',
            'code',
            'inline-code',
            'insert-before',
            'insert-after',
            '|',
            'table',
            '|',
            'undo',
            'redo',
            '|',
            'fullscreen',
            'edit-mode',
            {
                name: 'more',
                toolbar: [
                    'both',
                    'preview',
                    'info',
                    'help',
                ],
            }
        ],
        after: function() {
            console.log('Vditor loaded');
        }
    });
}

function syncContent() {
    if (vditor) {
        document.getElementById('content-textarea').value = vditor.getValue();
    }
}

function initCaptcha() {
    if (typeof CAPTCHA !== 'undefined') {
        var config = {
            element: "#captcha",
            textBefore: "我是真人",
            textDuring: "正在进行人机身份验证",
            textAfter: "已通过人机身份验证",
            duration: 1000,
            success: true,
            dark: false
        };
        captcha = new CAPTCHA(config);
    }
}

function checkCaptcha() {
    if (captcha && !captcha.checked) {
        alert('请先通过验证码！');
        return false;
    }
    return true;
}

function handleShareClick() {
    let btn = document.getElementById('share-button');
    let originalText = btn.innerText;
    let reviewStatus = document.getElementById('review-status');
    let statusText = document.getElementById('status-text');
    let spinner = reviewStatus.querySelector('.loading-spinner');
    
    reviewStatus.style.display = 'block';
    reviewStatus.className = 'checking-status';
    statusText.innerText = '正在提交审核请求...';
    spinner.style.display = 'inline-block';
    btn.disabled = true;
    btn.innerText = '处理中...';
    
    let xhr = new XMLHttpRequest();
    xhr.open('GET', '?action=share', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    let response = JSON.parse(xhr.responseText);
                    if (response.status === 'success') {
                        statusText.innerText = response.message;
                        reviewStatus.classList.add('success');
                        spinner.style.display = 'none';
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else if (response.status === 'error' && response.action === 'pending') {
                        statusText.innerText = response.message;
                        reviewStatus.classList.add('error');
                        spinner.style.display = 'none';
                        btn.disabled = false;
                        btn.innerText = originalText;
                        setTimeout(function() {
                            reviewStatus.style.display = 'none';
                        }, 3000);
                    } else {
                        statusText.innerText = response.message;
                        reviewStatus.classList.add('error');
                        spinner.style.display = 'none';
                        btn.disabled = false;
                        btn.innerText = originalText;
                        setTimeout(function() {
                            reviewStatus.style.display = 'none';
                        }, 3000);
                    }
                } catch (e) {
                    statusText.innerText = '响应解析错误，请重试';
                    reviewStatus.classList.add('error');
                    spinner.style.display = 'none';
                    btn.disabled = false;
                    btn.innerText = originalText;
                }
            } else {
                statusText.innerText = '网络请求失败，请重试';
                reviewStatus.classList.add('error');
                spinner.style.display = 'none';
                btn.disabled = false;
                btn.innerText = originalText;
            }
        }
    };
    xhr.onerror = function() {
        statusText.innerText = '网络错误，请重试';
        reviewStatus.classList.add('error');
        spinner.style.display = 'none';
        btn.disabled = false;
        btn.innerText = originalText;
    };
    xhr.send();
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_SESSION['username']) || isset($_GET['register'])): ?>
    if (typeof Vditor !== 'undefined') {
        initVditor();
    } else {
        var checkVditor = setInterval(function() {
            if (typeof Vditor !== 'undefined') {
                clearInterval(checkVditor);
                initVditor();
            }
        }, 100);
    }
    <?php endif; ?>
    
    if (typeof CAPTCHA !== 'undefined') {
        initCaptcha();
    } else {
        var checkCaptcha = setInterval(function() {
            if (typeof CAPTCHA !== 'undefined') {
                clearInterval(checkCaptcha);
                initCaptcha();
            }
        }, 100);
    }
    
    if (document.getElementById('share-button')) {
        document.getElementById('share-button').addEventListener('click', handleShareClick);
    }
});
</script>

<div id="notification-popup" style="display:none; position: fixed; bottom: 15px; right: 15px; background: #fff; border: 1px solid #ccc; padding: 15px; box-shadow: 0 0 10px rgba(0,0,0,0.2); border-radius: 8px; z-index: 9999; width: 300px;">
    <h3>📢 欢迎使用 EasyNote</h3>
    <p id="notification-content">
        这是一个便利、永久免费的在线笔记本，你可以把它当作备忘录、文字传输工具，甚至是简单的博客使用。
    </p>
    
    <h3 style="color: #d32f2f; font-size: 15px;">⚠️ 使用须知</h3>
    <p id="notification-content" style="font-size: 14px; line-height: 1;">
        <strong>内容审核：</strong>公开分享的笔记将经过AI审核，请勿发布违法、色情、反社会等违规内容。<br><br>
        
        <strong>数据安全：</strong>我们尽力保护您的数据安全，存储的文件都经过加密，但注意保存密码，若忘记密码没人能救回你的数据，但因网络环境、不可抗力等因素导致的数据丢失，本站不承担责任。<br><br>
        
        <strong>服务可用性：</strong>本站为免费服务，不保证100%可用性，可能会因维护、升级等原因暂停服务。<br><br>
        
        <strong>使用责任：</strong>请合理使用本站服务，遵守相关法律法规，因不当使用导致的后果由用户自行承担。
    </p>
    <h3 style="font-size: 15px;">其它通知见公开笔记中置顶，本站代码已开源至<a href="https://github.com/Huchangzhi/eazynote">github</a></h3>
    <div style="text-align: right; margin-top: 6px;">
        <button onclick="closePopup('today')" style="background-color: #ff9800; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-right: 5px;">当天不再提示</button>
        <button onclick="closePopup()" style="background-color: #2196f3; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">关闭</button>
    </div>
</div>

<script>
    const NOTIFICATION_VERSION = 6;//修改通知后+1
    
    function getCookie(name) {
        let value = "; " + document.cookie;
        let parts = value.split("; " + name + "=");
        if (parts.length === 2) return parts.pop().split(";").shift();
    }

    function setCookie(name, value) {
        let now = new Date();
        let expire = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
        document.cookie = name + "=" + value + ";expires=" + expire.toUTCString() + ";path=/";
    }

    function closePopup(option) {
        let popup = document.getElementById("notification-popup");
        popup.style.display = "none";

        if (option === "today") {
            setCookie("popup_closed_version", NOTIFICATION_VERSION);
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        let closedVersion = getCookie("popup_closed_version");
        
        if (!closedVersion || parseInt(closedVersion) < NOTIFICATION_VERSION) {
            document.getElementById("notification-popup").style.display = "block";
        }
    });
</script>

</body>
</html>
