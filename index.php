<?php
// ==========================================
// Cargo Uploader Configuration
// ==========================================

// Security Key (Must match the key set in Bot's /set-server)
// セキュリティキー (Botの /set-server で設定したキーと同じにする必要があります)
$API_KEY = 'CHANGE_THIS_TO_A_SECURE_KEY';

// アップロードディレクトリ
$UPLOAD_DIR = __DIR__ . '/files';

// チャンクアップロードの一時ディレクトリ
$TEMP_DIR = __DIR__ . '/temp';

// ベースURL (自動取得できない場合は手動設定してください)
$BASE_URL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['REQUEST_URI']);

// ==========================================
// Backend Logic
// ==========================================

// エラー表示設定 (本番環境ではオフにすることを推奨)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ディレクトリ作成
if (!file_exists($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
if (!file_exists($TEMP_DIR)) mkdir($TEMP_DIR, 0755, true);

// ヘルパー関数: JSONレスポンス
function json_response($data, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// ヘルパー関数: 署名検証 (URLパラメータ用)
function verify_signature($data, $sig, $key) {
    $calculated_sig = hash_hmac('sha256', $data, $key);
    return hash_equals($calculated_sig, $sig);
}

// ヘルパー関数: 署名検証 (Header用 - Info API)
function verify_header_signature($key) {
    $headers = getallheaders();
    $ts = $headers['X-Cargo-Timestamp'] ?? '';
    $sig = $headers['X-Cargo-Signature'] ?? '';
    
    if (!$ts || !$sig) return false;
    if (abs(time() - intval($ts)) > 300) return false; // 5分以内のリクエストのみ許可
    
    $payload = "info:$ts";
    $calculated = hash_hmac('sha256', $payload, $key);
    return hash_equals($calculated, $sig);
}

// ヘルパー関数: 容量確保 (自動削除)
function ensure_disk_space($required_bytes) {
    global $UPLOAD_DIR;
    $free = disk_free_space($UPLOAD_DIR);
    if ($free >= $required_bytes) return true;

    // ファイル一覧を取得して古い順にソート
    $files = glob($UPLOAD_DIR . '/*');
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    foreach ($files as $file) {
        if (is_file($file)) {
            $size = filesize($file);
            unlink($file);
            $free += $size;
            if ($free >= $required_bytes) return true;
        }
    }
    return false;
}

// アクション分岐
$action = $_GET['action'] ?? '';

// 1. サーバー情報API (Bot用)
if ($action === 'info') {
    if (!verify_header_signature($API_KEY)) {
        json_response(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    
    $total = disk_total_space($UPLOAD_DIR);
    $free = disk_free_space($UPLOAD_DIR);
    
    json_response([
        'status' => 'success',
        'data' => [
            'total_space' => $total,
            'free_space' => $free,
            'upload_dir' => $UPLOAD_DIR
        ]
    ]);
}

// 2. アップロード処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data_param = $_POST['data'] ?? '';
    $sig_param = $_POST['sig'] ?? '';
    
    if (!verify_signature($data_param, $sig_param, $API_KEY)) {
        json_response(['status' => 'error', 'message' => 'Invalid signature'], 403);
    }

    $user_data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $data_param)), true);
    if (!$user_data || $user_data['exp'] < time()) {
        json_response(['status' => 'error', 'message' => 'Token expired or invalid'], 403);
    }

    // Chunk Upload処理
    $file_id = $_POST['file_id'] ?? '';
    $chunk_index = intval($_POST['chunk_index'] ?? 0);
    $total_chunks = intval($_POST['total_chunks'] ?? 1);
    $filename = basename($_POST['filename'] ?? 'unknown');
    
    // ファイル名サニタイズ
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    // 拡張子チェック (php等は禁止)
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['php', 'php5', 'phtml', 'exe', 'sh', 'htaccess'])) {
        json_response(['status' => 'error', 'message' => 'File type not allowed'], 400);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_response(['status' => 'error', 'message' => 'File upload failed'], 400);
    }

    $temp_file_path = $TEMP_DIR . '/' . $file_id;
    
    // チャンクを追記
    $chunk_data = file_get_contents($_FILES['file']['tmp_name']);
    file_put_contents($temp_file_path, $chunk_data, FILE_APPEND);

    // 最終チャンクの場合
    if ($chunk_index === $total_chunks - 1) {
        $final_filename = time() . '_' . $filename;
        $final_path = $UPLOAD_DIR . '/' . $final_filename;
        $file_size = filesize($temp_file_path);

        // 自動削除チェック
        if ($user_data['auto_delete']) {
            if (!ensure_disk_space($file_size)) {
                unlink($temp_file_path);
                json_response(['status' => 'error', 'message' => 'Server storage full'], 507);
            }
        } else {
             if (disk_free_space($UPLOAD_DIR) < $file_size) {
                unlink($temp_file_path);
                json_response(['status' => 'error', 'message' => 'Server storage full'], 507);
             }
        }

        rename($temp_file_path, $final_path);
        
        // Webhook通知
        $file_url = $BASE_URL . '/files/' . $final_filename;
        $webhook_url = $user_data['webhook_url'];
        
        $discord_payload = [
            'username' => $user_data['username'],
            'avatar_url' => $user_data['avatar_url'],
            'content' => $file_url
        ];

        $ch = curl_init($webhook_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($discord_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);

        json_response(['status' => 'success', 'url' => $file_url]);
    }

    json_response(['status' => 'continue', 'chunk' => $chunk_index]);
}

// 3. Frontend (GET)
$data_param = $_GET['data'] ?? '';
$sig_param = $_GET['sig'] ?? '';
$valid_token = false;
$error_msg = '';

if ($data_param && $sig_param) {
    if (verify_signature($data_param, $sig_param, $API_KEY)) {
        $user_data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $data_param)), true);
        if ($user_data && $user_data['exp'] >= time()) {
            $valid_token = true;
        } else {
            $error_msg = 'Token expired.';
        }
    } else {
        $error_msg = 'Invalid signature.';
    }
} else {
    $error_msg = 'Missing authentication info.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargo File Share</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #0f172a; color: #e2e8f0; }
        .drop-zone { transition: all 0.3s ease; }
        .drop-zone.dragover { border-color: #3b82f6; background-color: rgba(59, 130, 246, 0.1); }
        .progress-ring__circle {
            transition: stroke-dashoffset 0.35s;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }
    </style>
</head>
<body class="h-screen flex items-center justify-center">

    <div class="w-full max-w-md p-8 bg-slate-800 rounded-xl shadow-2xl">
        <?php if ($valid_token): ?>
            <div id="upload-view">
                <h1 class="text-2xl font-bold mb-6 text-center text-blue-400">Cargo File Share</h1>
                
                <div id="drop-zone" class="drop-zone border-2 border-dashed border-slate-600 rounded-lg p-12 text-center cursor-pointer hover:border-slate-500">
                    <div class="mb-4">
                        <svg class="w-12 h-12 mx-auto text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    </div>
                    <p class="text-slate-300 mb-2">Drag & Drop File Here</p>
                    <p class="text-sm text-slate-500">or click to select</p>
                    <input type="file" id="file-input" class="hidden">
                </div>

                <div id="progress-container" class="hidden mt-8 text-center">
                    <div class="relative w-32 h-32 mx-auto mb-4">
                        <svg class="w-full h-full" viewBox="0 0 100 100">
                            <circle class="text-slate-700 stroke-current" stroke-width="8" cx="50" cy="50" r="40" fill="transparent"></circle>
                            <circle id="progress-ring" class="text-blue-500 progress-ring__circle stroke-current" stroke-width="8" stroke-linecap="round" cx="50" cy="50" r="40" fill="transparent" stroke-dasharray="251.2" stroke-dashoffset="251.2"></circle>
                        </svg>
                        <div class="absolute top-0 left-0 w-full h-full flex items-center justify-center">
                            <span id="progress-text" class="text-xl font-bold">0%</span>
                        </div>
                    </div>
                    <p id="status-text" class="text-slate-400 text-sm">Preparing...</p>
                </div>
            </div>

            <div id="success-view" class="hidden text-center">
                <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Upload Complete!</h2>
                <p class="text-slate-400 mb-6">Notification sent to Discord.</p>
                <button onclick="location.reload()" class="px-6 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg transition">Upload Another</button>
            </div>

            <script>
                const dropZone = document.getElementById('drop-zone');
                const fileInput = document.getElementById('file-input');
                const uploadView = document.getElementById('upload-view');
                const successView = document.getElementById('success-view');
                const progressContainer = document.getElementById('progress-container');
                const progressRing = document.getElementById('progress-ring');
                const progressText = document.getElementById('progress-text');
                const statusText = document.getElementById('status-text');

                // Drag & Drop Events
                dropZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropZone.classList.add('dragover');
                });
                dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
                dropZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dropZone.classList.remove('dragover');
                    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
                });
                dropZone.addEventListener('click', () => fileInput.click());
                fileInput.addEventListener('change', (e) => {
                    if (e.target.files.length) handleFile(e.target.files[0]);
                });

                async function handleFile(file) {
                    dropZone.classList.add('hidden');
                    progressContainer.classList.remove('hidden');
                    
                    const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB chunks
                    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                    const fileId = Math.random().toString(36).substring(2) + Date.now();
                    
                    const urlParams = new URLSearchParams(window.location.search);
                    const dataParam = urlParams.get('data');
                    const sigParam = urlParams.get('sig');

                    let start = 0;
                    const startTime = Date.now();

                    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                        const chunk = file.slice(start, start + CHUNK_SIZE);
                        const formData = new FormData();
                        formData.append('file', chunk);
                        formData.append('file_id', fileId);
                        formData.append('chunk_index', chunkIndex);
                        formData.append('total_chunks', totalChunks);
                        formData.append('filename', file.name);
                        formData.append('data', dataParam);
                        formData.append('sig', sigParam);

                        try {
                            const response = await fetch(window.location.href, {
                                method: 'POST',
                                body: formData
                            });
                            
                            if (!response.ok) throw new Error('Upload failed');
                            const result = await response.json();
                            if (result.status === 'error') throw new Error(result.message);

                            // Update Progress
                            const percent = Math.round(((chunkIndex + 1) / totalChunks) * 100);
                            const circumference = 251.2;
                            const offset = circumference - (percent / 100) * circumference;
                            progressRing.style.strokeDashoffset = offset;
                            progressText.textContent = `${percent}%`;
                            
                            // Speed calc
                            const elapsed = (Date.now() - startTime) / 1000;
                            const uploaded = (chunkIndex + 1) * CHUNK_SIZE;
                            const speed = uploaded / elapsed; // bytes/sec
                            statusText.textContent = `${(speed / 1024 / 1024).toFixed(1)} MB/s - remaining ${Math.ceil((file.size - uploaded) / speed)}s`;

                        } catch (error) {
                            alert(`Error: ${error.message}`);
                            location.reload();
                            return;
                        }

                        start += CHUNK_SIZE;
                    }

                    uploadView.classList.add('hidden');
                    successView.classList.remove('hidden');
                }
            </script>

        <?php else: ?>
            <div class="text-center">
                <div class="w-16 h-16 bg-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </div>
                <h2 class="text-xl font-bold text-red-400 mb-2">Access Error</h2>
                <p class="text-slate-400"><?php echo htmlspecialchars($error_msg); ?></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
