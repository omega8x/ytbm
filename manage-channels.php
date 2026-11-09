<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/func/common-functions.php';
require_once __DIR__ . '/func/youtube-functions.php';

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$message = '';
$messageType = 'success';

/*
|--------------------------------------------------------------------------
| Hiển thị thông báo
|--------------------------------------------------------------------------
*/
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message']['message'];
    $messageType = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']);
}

/*
|--------------------------------------------------------------------------
| Chuyển hướng sau khi xử lý POST
|--------------------------------------------------------------------------
*/
function manageRedirect(): never
{
    header('Location: manage-channels.php');
    exit;
}

function manageFlash(string $message, string $type = 'success'): void
{
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

/*
|--------------------------------------------------------------------------
| Phân tích thông tin người dùng nhập
|
| Hỗ trợ:
|   UCxxxxxxxxxxxxxxxxxxxxxx
|   @handle
|   https://www.youtube.com/@handle
|   https://www.youtube.com/channel/UCxxxxxxxx...
|--------------------------------------------------------------------------
*/
function manageParseChannelInput(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        throw new RuntimeException('Vui lòng nhập Channel ID, Handle hoặc URL kênh.');
    }

    // Trường hợp người dùng nhập URL YouTube
    if (filter_var($input, FILTER_VALIDATE_URL)) {
        $url = parse_url($input);

        $host = strtolower($url['host'] ?? '');
        $path = trim($url['path'] ?? '', '/');

        $allowedHosts = [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com'
        ];

        if (!in_array($host, $allowedHosts, true)) {
            throw new RuntimeException('URL không phải địa chỉ kênh YouTube hợp lệ.');
        }

        // URL dạng /channel/UC...
        if (preg_match('~^channel/(UC[A-Za-z0-9_-]{22})$~', $path, $matches)) {
            return [
                'type' => 'id',
                'value' => $matches[1],
                'handle' => null
            ];
        }

        // URL dạng /@handle
        if (preg_match('~^@([A-Za-z0-9._-]+)$~', $path, $matches)) {
            return [
                'type' => 'handle',
                'value' => $matches[1],
                'handle' => $matches[1]
            ];
        }

        throw new RuntimeException(
            'Chưa hỗ trợ dạng URL này. Hãy dùng URL /@handle hoặc /channel/UC...'
        );
    }

    // Channel ID
    if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $input)) {
        return [
            'type' => 'id',
            'value' => $input,
            'handle' => null
        ];
    }

    // Handle, có thể có hoặc không có ký tự @
    $handle = ltrim($input, '@');

    if (preg_match('/^[A-Za-z0-9._-]{3,30}$/', $handle)) {
        return [
            'type' => 'handle',
            'value' => $handle,
            'handle' => $handle
        ];
    }

    throw new RuntimeException('Thông tin kênh không đúng định dạng.');
}

/*
|--------------------------------------------------------------------------
| Xử lý các thao tác POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Kiểm tra CSRF
        $postedToken = $_POST['csrf_token'] ?? '';

        if (!hash_equals($csrfToken, $postedToken)) {
            throw new RuntimeException('Phiên làm việc không hợp lệ. Vui lòng tải lại trang.');
        }

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | Thêm kênh
        |--------------------------------------------------------------------------
        */
        if ($action === 'add') {
            $input = trim($_POST['channel_input'] ?? '');

            $parsed = manageParseChannelInput($input);

            // Gọi YouTube API để xác định thông tin kênh
            $params = [
                'part' => 'snippet,contentDetails'
            ];

            if ($parsed['type'] === 'id') {
                $params['id'] = $parsed['value'];
            } else {
                $params['forHandle'] = $parsed['value'];
            }

            $response = youtubeApiRequest('channels', $params);

            $item = $response['items'][0] ?? null;

            if (!$item) {
                throw new RuntimeException('Không tìm thấy kênh trên YouTube.');
            }

            $channelId = $item['id'] ?? '';
            $snippet = $item['snippet'] ?? [];
            $contentDetails = $item['contentDetails'] ?? [];

            $channelTitle = trim($snippet['title'] ?? '');
            $uploadsPlaylistId = $contentDetails['relatedPlaylists']['uploads'] ?? '';

            if (
                $channelId === '' ||
                $channelTitle === '' ||
                $uploadsPlaylistId === ''
            ) {
                throw new RuntimeException('YouTube không trả về đủ thông tin cần thiết của kênh.');
            }

            // Lấy Handle. Ưu tiên Handle người dùng đã nhập.
            $channelHandle = $parsed['handle'];

            if (!$channelHandle) {
                $customUrl = $snippet['customUrl'] ?? '';

                if ($customUrl !== '') {
                    $channelHandle = ltrim($customUrl, '@');
                }
            }

            // Lấy thumbnail
            $thumbnails = $snippet['thumbnails'] ?? [];

            $channelThumbnail =
                $thumbnails['high']['url']
                ?? $thumbnails['medium']['url']
                ?? $thumbnails['default']['url']
                ?? null;

            /*
             * Kiểm tra trùng Channel ID hoặc Handle.
             *
             * Nếu đã tồn tại:
             * - Không cập nhật tên.
             * - Không cập nhật Handle.
             * - Không cập nhật ảnh.
             * - Không thay đổi trạng thái.
             * - Không thay đổi thứ tự.
             */
            $check = $conn->prepare("
                SELECT id, channel_title, is_active
                FROM youtube_channels
                WHERE channel_id = ?
                   OR (? IS NOT NULL AND channel_handle = ?)
                LIMIT 1
            ");

            $check->bind_param(
                'sss',
                $channelId,
                $channelHandle,
                $channelHandle
            );

            $check->execute();

            $existing = $check->get_result()->fetch_assoc();

            $check->close();

            if ($existing) {
                throw new RuntimeException(
                    'Kênh đã tồn tại trong danh sách: ' .
                    $existing['channel_title'] .
                    '. Không có dữ liệu nào bị thay đổi.'
                );
            }

            /*
             * Tính sort_order cho kênh mới.
             */
            $result = $conn->query("
                SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order
                FROM youtube_channels
            ");

            $sortOrder = (int)($result->fetch_assoc()['next_order'] ?? 1);

            // Handle có thể NULL nếu không xác định được
            if ($channelHandle === '') {
                $channelHandle = null;
            }

            /*
             * Chỉ INSERT, tuyệt đối không dùng ON DUPLICATE KEY UPDATE.
             */
            $stmt = $conn->prepare("
                INSERT INTO youtube_channels (
                    channel_id,
                    channel_handle,
                    channel_title,
                    uploads_playlist_id,
                    channel_thumbnail,
                    is_active,
                    sort_order
                ) VALUES (?, ?, ?, ?, ?, 1, ?)
            ");

            $stmt->bind_param(
                'sssssi',
                $channelId,
                $channelHandle,
                $channelTitle,
                $uploadsPlaylistId,
                $channelThumbnail,
                $sortOrder
            );

            $stmt->execute(); // thêm kênh vào database
            $stmt->close();

            manageFlash('Đã thêm kênh: ' . $channelTitle);
            manageRedirect();
        }

        /*
        |--------------------------------------------------------------------------
        | Bật / tắt kênh
        |--------------------------------------------------------------------------
        */
        if ($action === 'toggle') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                throw new RuntimeException('ID kênh không hợp lệ.');
            }

            $stmt = $conn->prepare("
                UPDATE youtube_channels
                SET is_active = IF(is_active = 1, 0, 1)
                WHERE id = ?
            ");

            $stmt->bind_param('i', $id);
            $stmt->execute();

            $affected = $stmt->affected_rows;
            $stmt->close();

            manageFlash(
                $affected > 0
                    ? 'Đã thay đổi trạng thái kênh.'
                    : 'Không tìm thấy kênh cần thay đổi.',
                $affected > 0 ? 'success' : 'warning'
            );

            manageRedirect();
        }

        /*
        |--------------------------------------------------------------------------
        | Xóa kênh
        |--------------------------------------------------------------------------
        */
        if ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                throw new RuntimeException('ID kênh không hợp lệ.');
            }

            $stmt = $conn->prepare("
                DELETE FROM youtube_channels
                WHERE id = ?
            ");

            $stmt->bind_param('i', $id);
            $stmt->execute();  // xóa kênh khỏi database

            $affected = $stmt->affected_rows;
            $stmt->close();

            manageFlash(
                $affected > 0
                    ? 'Đã xóa kênh khỏi danh sách.'
                    : 'Không tìm thấy kênh cần xóa.',
                $affected > 0 ? 'success' : 'warning'
            );

            manageRedirect();
        }

        /*
        |--------------------------------------------------------------------------
        | Di chuyển thứ tự kênh lên / xuống
        |--------------------------------------------------------------------------
        */
        if ($action === 'move') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $direction = $_POST['direction'] ?? '';

            if (!$id || !in_array($direction, ['up', 'down'], true)) {
                throw new RuntimeException('Thông tin sắp xếp không hợp lệ.');
            }

            $result = $conn->query("
                SELECT id, sort_order
                FROM youtube_channels
                ORDER BY sort_order ASC, id ASC
            ");

            $channels = $result->fetch_all(MYSQLI_ASSOC);

            $currentIndex = -1;

            foreach ($channels as $index => $channel) {
                if ((int)$channel['id'] === $id) {
                    $currentIndex = $index;
                    break;
                }
            }

            if ($currentIndex === -1) {
                throw new RuntimeException('Không tìm thấy kênh cần sắp xếp.');
            }

            $targetIndex = $direction === 'up'
                ? $currentIndex - 1
                : $currentIndex + 1;

            if (isset($channels[$targetIndex])) {
                $current = $channels[$currentIndex];
                $target = $channels[$targetIndex];

                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("
                        UPDATE youtube_channels
                        SET sort_order = ?
                        WHERE id = ?
                    ");

                    $currentOrder = (int)$current['sort_order'];
                    $targetOrder = (int)$target['sort_order'];

                    // Gán thứ tự tạm để tránh hai bản ghi bị cùng thứ tự
                    $temporaryOrder = max($currentOrder, $targetOrder) + 100000;

                    $stmt->bind_param('ii', $temporaryOrder, $id);
                    $stmt->execute();

                    $targetId = (int)$target['id'];

                    $stmt->bind_param('ii', $currentOrder, $targetId);
                    $stmt->execute();

                    $stmt->bind_param('ii', $targetOrder, $id);
                    $stmt->execute();

                    $stmt->close();

                    $conn->commit();

                    manageFlash('Đã thay đổi thứ tự kênh.');
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            } else {
                manageFlash('Kênh đã ở vị trí ngoài cùng.');
            }

            manageRedirect();
        }

        throw new RuntimeException('Thao tác không hợp lệ.');

    } catch (Throwable $e) {
        error_log('Manage Channels Error: ' . $e->getMessage());

        manageFlash($e->getMessage(), 'danger');
        manageRedirect();
    }
}

/*
|--------------------------------------------------------------------------
| Lấy danh sách kênh
|--------------------------------------------------------------------------
*/
$result = $conn->query("
    SELECT
        id,
        channel_id,
        channel_handle,
        channel_title,
        uploads_playlist_id,
        channel_thumbnail,
        is_active,
        sort_order,
        created_at
    FROM youtube_channels
    ORDER BY sort_order ASC, id ASC
");

$channels = $result->fetch_all(MYSQLI_ASSOC);

$totalChannels = count($channels);
$activeChannels = 0;

foreach ($channels as $channel) {
    if ((int)$channel['is_active'] === 1) {
        $activeChannels++;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Quản lý kênh YouTube</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f6f8;
        }

        .channel-thumb {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 50%;
            background: #eee;
        }

        .channel-row {
            background: #fff;
        }

        .channel-id {
            font-size: 12px;
            color: #777;
            word-break: break-all;
        }

        .action-buttons form {
            display: inline-block;
        }

        .channel-disabled {
            opacity: .55;
        }

        @media (max-width: 576px) {
            .channel-thumb {
                width: 48px;
                height: 48px;
            }

            .action-buttons .btn {
                margin-bottom: 4px;
            }
        }
    </style>
</head>

<body>

<div class="container py-4 px-3 px-lg-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="mb-1">Quản lý kênh YouTube</h3>
            <div class="text-muted small">
                Quản lý danh sách các kênh trong thư viện cá nhân
            </div>
        </div>

        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-house"></i> Trang chủ
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show">
            <?= h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Thống kê -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Tổng số kênh</div>
                    <div class="fs-3 fw-bold"><?= $totalChannels ?></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Đang hoạt động</div>
                    <div class="fs-3 fw-bold text-success"><?= $activeChannels ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Thêm kênh -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold">
            Thêm kênh YouTube
        </div>

        <div class="card-body">
            <form method="POST" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="add">

                <div class="col-12 col-md-9">
                    <input
                        type="text"
                        name="channel_input"
                        class="form-control"
                        placeholder="Nhập Channel ID, @handle hoặc URL kênh YouTube..."
                        required
                    >
                </div>

                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        + Thêm kênh
                    </button>
                </div>
            </form>

            <div class="form-text mt-2">
                Ví dụ: <code>@GoogleDevelopers</code>,
                <code>UC_x5XG1OV2P6uZZ5FSM9Ttw</code>
                hoặc URL kênh YouTube.
            </div>

            <div class="form-text">
                Nếu kênh đã tồn tại, hệ thống chỉ thông báo và không thay đổi dữ liệu cũ.
            </div>
        </div>
    </div>

    <!-- Danh sách kênh -->
    <div class="card shadow-sm">
        <div class="card-header fw-bold d-flex justify-content-between align-items-center">
            <span>Danh sách kênh</span>
            <span class="badge bg-secondary"><?= $totalChannels ?> kênh</span>
        </div>

        <div class="card-body p-0">

            <?php if (empty($channels)): ?>

                <div class="text-center text-muted py-5">
                    Chưa có kênh nào. Hãy thêm kênh YouTube ở phía trên.
                </div>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th style="width: 55px;">#</th>
                                <th>Kênh</th>
                                <th style="width: 110px;">Trạng thái</th>
                                <th style="width: 190px;">Thứ tự</th>
                                <th style="width: 230px;">Thao tác</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($channels as $index => $channel): ?>

                            <?php
                            $isActive = (int)$channel['is_active'] === 1;
                            ?>

                            <tr class="channel-row <?= !$isActive ? 'channel-disabled' : '' ?>">

                                <td class="text-muted">
                                    <?= $index + 1 ?>
                                </td>

                                <td>
                                    <div class="d-flex align-items-center gap-3">

                                        <?php if (!empty($channel['channel_thumbnail'])): ?>
                                            <img
                                                src="<?= h($channel['channel_thumbnail']) ?>"
                                                class="channel-thumb"
                                                alt=""
                                                loading="lazy"
                                            >
                                        <?php else: ?>
                                            <div class="channel-thumb d-flex align-items-center justify-content-center">
                                                <span class="text-muted">?</span>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <div class="fw-semibold">
                                                <?= h($channel['channel_title']) ?>
                                            </div>

                                            <?php if (!empty($channel['channel_handle'])): ?>
                                                <div class="small text-muted">
                                                    @<?= h($channel['channel_handle']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="channel-id">
                                                <?= h($channel['channel_id']) ?>
                                            </div>
                                        </div>

                                    </div>
                                </td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success">Đang bật</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Đã tắt</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-1">

                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="id" value="<?= (int)$channel['id'] ?>">
                                            <input type="hidden" name="direction" value="up">

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-secondary"
                                                title="Di chuyển lên"
                                                <?= $index === 0 ? 'disabled' : '' ?>
                                            >
                                                ↑
                                            </button>
                                        </form>

                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="id" value="<?= (int)$channel['id'] ?>">
                                            <input type="hidden" name="direction" value="down">

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-secondary"
                                                title="Di chuyển xuống"
                                                <?= $index === $totalChannels - 1 ? 'disabled' : '' ?>
                                            >
                                                ↓
                                            </button>
                                        </form>

                                    </div>
                                </td>

                                <td class="action-buttons">

                                    <a
                                        href="view-channel.php?channel_id=<?= urlencode($channel['channel_id']) ?>"
                                        class="btn btn-sm btn-outline-primary"
                                        title="Xem kênh"
                                    >
                                        Xem
                                    </a>

                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$channel['id'] ?>">

                                        <button
                                            type="submit"
                                            class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                        >
                                            <?= $isActive ? 'Tắt' : 'Bật' ?>
                                        </button>
                                    </form>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Bạn có chắc muốn xóa kênh này khỏi thư viện?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$channel['id'] ?>">

                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Xóa
                                        </button>
                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>