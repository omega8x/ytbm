<?php
/**
 * AJAX LOAD WATCHED VIDEOS
 *
 * - Lấy danh sách video đã xem từ database.
 * - Bao gồm cả video xem dở và video đã xem hết.
 * - Phân trang bằng LIMIT / OFFSET.
 * - Lấy tiêu đề, thumbnail từ YouTube Data API.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../func/common-functions.php';
require_once __DIR__ . '/../func/youtube-functions.php';

header('Content-Type: application/json; charset=utf-8');

// =====================================================
// 1. HÀM TRẢ JSON
// =====================================================

function watchedJsonResponse($success, $data = [], $message = '')
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// =====================================================
// 2. KIỂM TRA POST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    watchedJsonResponse(false, [], 'Phương thức không được hỗ trợ.');
}

// =====================================================
// 3. ĐỌC THAM SỐ
// =====================================================

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    watchedJsonResponse(false, [], 'Dữ liệu gửi lên không hợp lệ.');
}

$page = max(1, (int)($input['page'] ?? 1));

$perPage = (int)($input['perPage'] ?? 24);

// Giới hạn số video mỗi trang
$perPage = max(1, min(50, $perPage));

$offset = ($page - 1) * $perPage;

// =====================================================
// 4. ĐẾM TỔNG SỐ VIDEO ĐÃ XEM
// =====================================================

try {

    $countSql = "
        SELECT COUNT(*) AS total
        FROM youtube_video_progress
    ";

    $countResult = $conn->query($countSql);
    $totalVideos = (int)$countResult->fetch_assoc()['total'];

    $totalPages = max(1, (int)ceil($totalVideos / $perPage));

    // Nếu trang yêu cầu vượt quá tổng số trang
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    // =================================================
    // 5. LẤY VIDEO TỪ DATABASE
    // =================================================

    $sql = "
        SELECT
            p.video_id,
            p.channel_id,
            p.position_seconds,
            p.duration_seconds,
            p.is_completed,
            p.last_watched_at,
            c.channel_title

        FROM youtube_video_progress AS p

        LEFT JOIN youtube_channels AS c
            ON c.channel_id = p.channel_id

        ORDER BY p.last_watched_at DESC

        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();

    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {

        $position = (int)$row['position_seconds'];
        $duration = (int)$row['duration_seconds'];
        $completed = (int)$row['is_completed'];

        // Tính phần trăm đã xem
        if ($completed) {
            $percent = 100;
        } elseif ($duration > 0) {
            $percent = round(($position / $duration) * 100, 1);
            $percent = min(100, max(0, $percent));
        } else {
            $percent = 0;
        }

        $row['position_seconds'] = $position;
        $row['duration_seconds'] = $duration;
        $row['is_completed'] = $completed;
        $row['percent'] = $percent;

        $rows[] = $row;
    }

    $stmt->close();

    // =================================================
    // 6. LẤY THÔNG TIN VIDEO TỪ YOUTUBE API
    // =================================================

    $videoIds = [];

    foreach ($rows as $row) {
        $videoIds[] = $row['video_id'];
    }

    $videoInfoMap = [];

    if (!empty($videoIds)) {

        // videos.list hỗ trợ tối đa 50 ID mỗi lần
        $youtubeResult = youtubeApiRequest('videos', [
            'part' => 'snippet',
            'id' => implode(',', $videoIds),
            'maxResults' => 50
        ]);

        foreach (($youtubeResult['items'] ?? []) as $item) {

            $id = $item['id'] ?? '';

            if ($id === '') {
                continue;
            }

            $snippet = $item['snippet'] ?? [];

            $thumbnail =
                $snippet['medium']['url']
                ?? $snippet['thumbnails']['medium']['url']
                ?? $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? '';

            $videoInfoMap[$id] = [
                'title' => $snippet['title'] ?? 'Không có tiêu đề',
                'thumbnail' => $thumbnail
            ];
        }
    }

    // =================================================
    // 7. GHÉP THÔNG TIN VIDEO VÀ TIẾN ĐỘ
    // =================================================

    $videos = [];

    foreach ($rows as $row) {

        $videoId = $row['video_id'];

        $info = $videoInfoMap[$videoId] ?? null;

        // Vẫn giữ bản ghi nếu video đã bị xóa hoặc không còn công khai
        $videos[] = [
            'video_id' => $videoId,
            'channel_id' => $row['channel_id'],
            'channel_title' => $row['channel_title'] ?? 'Kênh không xác định',

            'title' => $info['title'] ?? 'Video không còn khả dụng',
            'thumbnail' => $info['thumbnail'] ?? '',

            'position_seconds' => $row['position_seconds'],
            'duration_seconds' => $row['duration_seconds'],
            'is_completed' => $row['is_completed'],
            'percent' => $row['percent'],

            'last_watched_at' => $row['last_watched_at']
        ];
    }

    // =================================================
    // 8. TRẢ KẾT QUẢ
    // =================================================

    watchedJsonResponse(true, [
        'videos' => $videos,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'totalVideos' => $totalVideos,
            'totalPages' => $totalPages,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $totalPages
        ]
    ]);

} catch (Throwable $e) {

    error_log('Load Watched Videos Error: ' . $e->getMessage());

    http_response_code(500);

    watchedJsonResponse(
        false,
        [],
        'Không thể tải lịch sử xem video. Vui lòng thử lại.'
    );
}
