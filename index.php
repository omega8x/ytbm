<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/func/common-functions.php';
require_once __DIR__ . '/func/youtube-functions.php';
require_once __DIR__ . '/func/progress-functions.php';
/*
|--------------------------------------------------------------------------
| Lấy danh sách kênh đang hoạt động
|--------------------------------------------------------------------------
*/
$result = $conn->query("
    SELECT
        id,
        channel_id,
        channel_title,
        channel_thumbnail,
        uploads_playlist_id
    FROM youtube_channels
    WHERE is_active = 1
    ORDER BY sort_order ASC, id ASC
");

$channels = $result->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Lấy 3 video mới nhất của từng kênh
|--------------------------------------------------------------------------
*/
$channelGroups = [];

foreach ($channels as $channel) {

    try {
        // Lấy video từ Uploads Playlist
        $response = getChannelVideos(
            $channel['uploads_playlist_id'],
            '',
            4
        );

        $items = $response['items'] ?? [];

        // Chuyển dữ liệu API thành định dạng sử dụng trên website
        $videos = formatVideos($items);

        // Chỉ lấy 3 video đầu tiên
        $videos = array_slice($videos, 0, 4);

        // Bỏ qua kênh chưa có video
        if (empty($videos)) {
            continue;
        }

        // Lấy thời gian đăng của video mới nhất
        $latestPublished = strtotime(
            $videos[0]['published_at'] ?? ''
        ) ?: 0;

        $channelGroups[] = [
            'channel' => $channel,
            'videos' => $videos,
            'latest_published' => $latestPublished,
            'error' => null
        ];

    } catch (Throwable $e) {

        // Không để lỗi một kênh làm hỏng toàn bộ trang chủ
        error_log(
            'YouTube channel error [' .
            $channel['channel_id'] . ']: ' .
            $e->getMessage()
        );

        $channelGroups[] = [
            'channel' => $channel,
            'videos' => [],
            'latest_published' => 0,
            'error' => 'Không thể tải video của kênh này.'
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Sắp xếp nhóm kênh theo video mới nhất
|--------------------------------------------------------------------------
*/
usort($channelGroups, function ($a, $b) {
    return $b['latest_published'] <=> $a['latest_published'];
});

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Thư viện YouTube cá nhân</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link rel="stylesheet"
      href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        body {
            background: #f5f6f8;
        }

        .page-header {
            background: #fff;
            border-bottom: 1px solid #e5e5e5;
        }

        .channel-section {
            background: #fff;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 20px;
            border: #c9c7c7 1px solid;
        }

        .channel-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 15px;
        }

        .channel-info {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .channel-avatar {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 50%;
            background: #eee;
            flex-shrink: 0;
        }

        .channel-name {
            font-size: 18px;
            font-weight: 600;
            color: #222;
            text-decoration: none;
        }

        .channel-name:hover {
            color: #0d6efd;
        }

        .video-card {
            height: 100%;
            border: 1px solid #c9c7c7;
            background: transparent;
            transition: transform .15s ease;
            border-radius: 8px;
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.25);
        }

        .video-card:hover {
            transform: translateY(-3px);
        }

        .video-link {
            display: block;
            color: inherit;
            text-decoration: none;
            height: 100%;
        }

        .video-thumbnail {
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            border-radius: 8px 8px 0px 0px;
            background: #eee;
        }

        .video-title {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.45;
            margin-top: 9px;
            margin-bottom: 5px;
            padding-left: 5px;
            padding-right: 5px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;

            min-height: 40px;
        }

        .video-date {
            color: #888;
            font-size: 12px;
            padding-left: 5px;
        }

        .empty-message {
            color: #888;
            padding: 12px 0;
        }

        @media (max-width: 576px) {
            .channel-section {
                padding: 12px;
                border-radius: 6px;
            }

            .channel-name {
                font-size: 16px;
            }

            .video-title {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>

<!-- Header -->
<header class="page-header py-3 mb-4">
    <div class="container px-3 px-lg-4">

        <div class="d-flex justify-content-between align-items-center">

            <div>
                <h4 class="mb-0">
                    <span class="text-danger">▶</span>
                    Thư viện YouTube
                </h4>

                <div class="small text-muted">
                    Video mới nhất từ các kênh đã đăng ký
                </div>
            </div>

            <a href="watched-videos.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-clock-history"></i>
                Video đã xem
            </a>

            <a
                href="manage-channels.php"
                class="btn btn-outline-secondary btn-sm"
            >
                Quản lý kênh
            </a>

        </div>

    </div>
</header>


<!-- Nội dung -->
<main class="container px-3 px-lg-4">

    <?php if (empty($channels)): ?>

        <div class="alert alert-info">
            Chưa có kênh nào đang hoạt động.
            <a href="manage-channels.php">Thêm kênh YouTube</a>
        </div>

    <?php elseif (empty($channelGroups)): ?>

        <div class="alert alert-warning">
            Chưa tải được video từ các kênh.
            Bạn hãy kiểm tra API Key và cấu hình YouTube API.
        </div>

    <?php else: ?>

        <?php foreach ($channelGroups as $group): ?>

            <?php
            $channel = $group['channel'];
            $videos = $group['videos'];
            ?>
            
            <section class="channel-section">

                <!-- Tiêu đề kênh -->
                <div class="channel-heading">

                    <div class="channel-info">

                        <?php if (!empty($channel['channel_thumbnail'])): ?>

                            <img
                                src="<?= h($channel['channel_thumbnail']) ?>"
                                class="channel-avatar"
                                alt=""
                                loading="lazy"
                            >

                        <?php endif; ?>

                        <div>
                            <a
                                href="view-channel.php?channel_id=<?= urlencode($channel['channel_id']) ?>"
                                class="channel-name"
                            >
                                <?= h($channel['channel_title']) ?>
                            </a>

                            <div class="small text-muted">
                                <?= count($videos) ?> video mới nhất
                            </div>
                        </div>

                    </div>

                    <a
                        href="view-channel.php?channel_id=<?= urlencode($channel['channel_id']) ?>"
                        class="btn btn-sm btn-outline-primary flex-shrink-0"
                    >
                        Xem tất cả →
                    </a>

                </div>


                <!-- Danh sách video -->
                <?php if (!empty($group['error'])): ?>

                    <div class="alert alert-warning mb-0">
                        <?= h($group['error']) ?>
                    </div>

                <?php else: ?>

                    <div class="row g-3">
                        <?php
                        $progressMap = getVideoProgressMap($conn, $videos);
                        ?>
                        <?php foreach ($videos as $video): ?>

                            <div class="col-12 col-sm-6 col-lg-3">

                                <div class="video-card">

                                    <a
                                        href="play-video.php?video_id=<?= urlencode($video['video_id']) ?>&channel_id=<?= urlencode($channel['channel_id']) ?>"
                                        class="video-link"
                                    >

                                    <div class="video-thumbnail-wrap">

                                        <img
                                            src="<?= h($video['thumbnail']) ?>"
                                            class="card-img-top video-thumbnail"
                                            alt=""
                                            loading="lazy"
                                        >

                                        <?php
                                        $progress = $progressMap[$video['video_id']] ?? null;
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

                                        <div class="video-title">
                                            <?= h($video['title']) ?>
                                        </div>

                                        <div class="video-date">
                                            <?php
                                            $published = strtotime(
                                                $video['published_at'] ?? ''
                                            );

                                            echo $published
                                                ? date('d/m/Y', $published)
                                                : '';
                                            ?>
                                        </div>

                                    </a>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
