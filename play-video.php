<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/func/common-functions.php';
require_once __DIR__ . '/func/youtube-functions.php';
require_once __DIR__ . '/func/progress-functions.php';

// --------------------------------------------------
// 1. Lấy tham số video và kênh
// --------------------------------------------------
$videoId = trim($_GET['video_id'] ?? '');
$channelId = trim($_GET['channel_id'] ?? '');

if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
    http_response_code(400);
    exit('Video ID không hợp lệ.');
}

if ($channelId === '') {
    http_response_code(400);
    exit('Thiếu Channel ID.');
}

// Lấy thông tin kênh từ database
$channel = getChannelFromDatabase($conn, $channelId);

if (!$channel) {
    http_response_code(404);
    exit('Không tìm thấy kênh hoặc kênh đã bị vô hiệu hóa.');
}

// --------------------------------------------------
// 2. Lấy thông tin video từ YouTube API
// --------------------------------------------------
try {
    $videoResponse = youtubeApiRequest('videos', [
        'part' => 'snippet,contentDetails',
        'id'   => $videoId
    ]);

    if (empty($videoResponse['items'][0])) {
        http_response_code(404);
        exit('Không tìm thấy video trên YouTube.');
    }

    $videoInfo = $videoResponse['items'][0];

    // Kiểm tra video có thuộc kênh đang xem không
    if (($videoInfo['snippet']['channelId'] ?? '') !== $channel['channel_id']) {
        http_response_code(400);
        exit('Video không thuộc kênh đã chọn.');
    }

    $videoTitle = $videoInfo['snippet']['title'] ?? 'Video không có tiêu đề';
    $videoDescription = $videoInfo['snippet']['description'] ?? '';
    $videoPublished = $videoInfo['snippet']['publishedAt'] ?? '';

    // Chuyển ISO 8601 duration (PT10M30S) thành giây
    $durationText = $videoInfo['contentDetails']['duration'] ?? 'PT0S';
    $durationObject = new DateInterval($durationText);
    $durationSeconds =
        ($durationObject->d * 86400) +
        ($durationObject->h * 3600) +
        ($durationObject->i * 60) +
        $durationObject->s;

} catch (Throwable $e) {
    error_log('YouTube video error: ' . $e->getMessage());
    http_response_code(500);
    exit('Không thể tải thông tin video.');
}

// --------------------------------------------------
// 3. Đọc tiến độ xem đã lưu
// --------------------------------------------------
$positionSeconds = 0;
$isCompleted = 0;

$stmt = $conn->prepare("
    SELECT position_seconds, is_completed
    FROM youtube_video_progress
    WHERE video_id = ?
    LIMIT 1
");

$stmt->bind_param('s', $videoId);
$stmt->execute();

$progress = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($progress) {
    $isCompleted = (int)$progress['is_completed'];

    // Video đã xem xong thì phát lại từ đầu
    if (!$isCompleted) {
        $positionSeconds = (int)$progress['position_seconds'];
    }
}

// Không cho vị trí vượt quá thời lượng video
$positionSeconds = min($positionSeconds, max(0, $durationSeconds));

// --------------------------------------------------
// 4. Lấy danh sách video cùng kênh
// --------------------------------------------------
$relatedVideos = [];

try {
    $playlistResponse = getChannelVideos(
        $channel['uploads_playlist_id'],
        '',
        10
    );

    $relatedVideos = formatVideos($playlistResponse['items'] ?? []);

    // Loại video đang phát khỏi danh sách bên phải
    $relatedVideos = array_values(array_filter(
        $relatedVideos,
        fn($item) => ($item['video_id'] ?? '') !== $videoId
    ));

    $relatedProgressMap = getVideoProgressMap($conn, $relatedVideos);

} catch (Throwable $e) {
    error_log('Related videos error: ' . $e->getMessage());
}

// --------------------------------------------------
// 5. Cấu hình truyền sang JavaScript
// --------------------------------------------------
$playerConfig = [
    'videoId'        => $videoId,
    'channelId'      => $channel['channel_id'],
    'position'       => $positionSeconds,
    'duration'       => $durationSeconds,
    'isCompleted'    => $isCompleted,
    'saveUrl'        => 'ajax/save-video-progress.php'
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($videoTitle) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet"
      href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">

<style>
    * {
        box-sizing: border-box;
    }

    html, body {
        width: 100%;
        max-width: 100%;
        margin: 0;
        overflow-x: hidden;
    }

    body {
        background: #f5f6f8;
    }

    .player-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 360px);
        gap: 20px;
        width: 100%;
        min-width: 0;
    }

    .player-layout > div {
        min-width: 0;
    }

    .player-wrapper {
        position: relative;
        width: 100%;
        max-width: 100%;
        aspect-ratio: 16 / 9;
        background: #000;
        border-radius: 8px;
        overflow: hidden;
    }

    #youtubePlayer,
    #youtubePlayer iframe {
        width: 100%;
        height: 100%;
        max-width: 100%;
    }

    .related-item {
        display: flex;
        gap: 10px;
        padding: 8px;
        color: inherit;
        text-decoration: none;
        border-radius: 6px;
        min-width: 0;
    }

    .related-item:hover {
        background: #e9ecef;
    }

    .related-item img {
        width: 130px;
        height: 74px;
        object-fit: cover;
        border-radius: 5px;
        flex-shrink: 0;
    }

    .related-title {
        font-size: 13px;
        font-weight: 500;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    @media (max-width: 900px) {
        .player-layout {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>
</head>

<body>
<div class="container py-3">

    <div class="mb-3 d-flex align-items-center flex-wrap gap-2">

        <a href="view-channel.php?channel_id=<?= urlencode($channel['channel_id']) ?>"
        class="btn btn-sm btn-outline-secondary">
            &larr; Quay lại kênh
        </a>

        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            Trang chủ
        </a>

        <!-- Thông báo lưu tiến độ -->
        <span id="saveNotification"
            class="small text-success ms-auto"
            style="display:none;">
            <i class="bi bi-check-circle-fill"></i>
            Time view saved
        </span>

    </div>

    <div class="player-layout">

        <!-- CỘT TRÁI: VIDEO -->
        <div>
            <div class="player-wrapper">
                <div id="youtubePlayer"></div>
            </div>

            <h5 class="mt-3"><?= h($videoTitle) ?></h5>

            <div class="text-muted small mb-2">
                <?= h($channel['channel_title']) ?>
                <?php if ($videoPublished): ?>
                    · <?= h(date('d/m/Y', strtotime($videoPublished))) ?>
                <?php endif; ?>
            </div>

            <div id="progressMessage" class="small text-success mb-2">
                <?php if ($positionSeconds > 0): ?>
                    Tiếp tục xem từ <?= gmdate('H:i:s', $positionSeconds) ?>.
                <?php elseif ($isCompleted): ?>
                    Video đã xem hoàn thành. Phát lại từ đầu.
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="fw-semibold mb-2">Mô tả video</div>
                    <div style="white-space:pre-wrap; max-height:300px; overflow:auto;"
                         class="small"><?= h($videoDescription) ?></div>
                </div>
            </div>
        </div>

        <!-- CỘT PHẢI: VIDEO CÙNG KÊNH -->
        <div>
            <div class="card">
                <div class="card-header fw-semibold">
                    Video khác của <?= h($channel['channel_title']) ?>
                </div>

                <div class="card-body p-2">
                    <?php foreach ($relatedVideos as $item): ?>
                        <a class="related-item"
                           href="play-video.php?video_id=<?= urlencode($item['video_id']) ?>&channel_id=<?= urlencode($channel['channel_id']) ?>">

<div class="video-thumbnail-wrap"
     style="width:130px; flex-shrink:0;">

    <img
        src="<?= h($item['thumbnail']) ?>"
        alt=""
        loading="lazy"
        style="width:130px; height:74px; object-fit:cover;"
    >

    <?php
    $progress = $relatedProgressMap[$item['video_id']] ?? null;
    ?>

    <?php if ($progress && $progress['percent'] > 0): ?>
        <div class="video-progress-track">
            <div
                class="video-progress-fill"
                style="width: <?= $progress['percent'] ?>%;"
            ></div>
        </div>
    <?php endif; ?>

</div>

                            <div class="related-title">
                                <?= h($item['title']) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>

                    <?php if (!$relatedVideos): ?>
                        <div class="text-muted small p-2">
                            Không có video khác.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    window.YT_PLAYER_CONFIG = <?= json_encode(
        $playerConfig,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;
</script>

<script src="assets/js/player.js?v=<?= filemtime(__DIR__ . '/assets/js/player.js') ?>"></script>

</body>
</html>
