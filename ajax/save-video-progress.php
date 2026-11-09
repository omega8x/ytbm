<?php
require_once dirname(__DIR__) . '/config/database.php';

// Endpoint chỉ trả JSON
header('Content-Type: application/json; charset=utf-8');

function jsonResponse($success, $message = '') {
    echo json_encode([
        'success' => $success,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, 'Method không hợp lệ.');
}

// Hỗ trợ cả fetch JSON và sendBeacon
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    $data = $_POST;
}

$videoId = trim($data['video_id'] ?? '');
$channelId = trim($data['channel_id'] ?? '');

$position = filter_var(
    $data['position_seconds'] ?? null,
    FILTER_VALIDATE_INT
);

$duration = filter_var(
    $data['duration_seconds'] ?? null,
    FILTER_VALIDATE_INT
);

$isCompleted = !empty($data['is_completed']) ? 1 : 0;

// Kiểm tra dữ liệu
if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
    http_response_code(400);
    jsonResponse(false, 'Video ID không hợp lệ.');
}

if ($channelId === '' || strlen($channelId) > 64) {
    http_response_code(400);
    jsonResponse(false, 'Channel ID không hợp lệ.');
}

if ($position === false || $duration === false || $position < 0 || $duration < 0) {
    http_response_code(400);
    jsonResponse(false, 'Thời gian không hợp lệ.');
}

$position = min($position, $duration);

if ($isCompleted) {
    $position = $duration;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO youtube_video_progress
            (channel_id, video_id, position_seconds,
             duration_seconds, is_completed, last_watched_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            channel_id = VALUES(channel_id),
            position_seconds = VALUES(position_seconds),
            duration_seconds = VALUES(duration_seconds),
            is_completed = VALUES(is_completed),
            last_watched_at = NOW()
    ");

    $stmt->bind_param(
        'ssiii',
        $channelId,
        $videoId,
        $position,
        $duration,
        $isCompleted
    );

    $stmt->execute();
    $stmt->close();

    jsonResponse(true, 'Đã lưu tiến độ.');

} catch (Throwable $e) {
    error_log('Save video progress error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, 'Không thể lưu tiến độ.');
}
