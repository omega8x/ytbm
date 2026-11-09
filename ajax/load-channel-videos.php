
<?php
/**
 * AJAX LOAD CHANNEL VIDEOS
 *
 * Chức năng:
 * - Nhận uploads_playlist_id và pageToken.
 * - Gọi YouTube Data API lấy video tiếp theo.
 * - Lấy tiến độ xem từ youtube_video_progress.
 * - Trả dữ liệu JSON kèm tiến độ xem cho view-channel.php.
 *
 * Phương thức: POST
 */

// =====================================================
// 1. CẤU HÌNH
// =====================================================

require_once __DIR__ . '/../config/youtube.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../func/progress-functions.php';

header('Content-Type: application/json; charset=utf-8');


// =====================================================
// 2. HÀM TRẢ RESPONSE JSON
// =====================================================

function jsonResponse($success, $data = [], $message = '')
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


// =====================================================
// 3. CHỈ CHO PHÉP POST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, [], 'Phương thức không được hỗ trợ.');
}


// =====================================================
// 4. ĐỌC DỮ LIỆU JAVASCRIPT GỬI LÊN
// =====================================================

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    jsonResponse(false, [], 'Dữ liệu gửi lên không hợp lệ.');
}

$playlistId = trim($input['playlist_id'] ?? '');
$pageToken  = trim($input['pageToken'] ?? '');


// Kiểm tra Uploads Playlist ID
if (
    $playlistId === '' ||
    !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $playlistId)
) {
    http_response_code(400);
    jsonResponse(false, [], 'Uploads Playlist ID không hợp lệ.');
}


// Kiểm tra Page Token
if ($pageToken === '' || strlen($pageToken) > 2000) {
    http_response_code(400);
    jsonResponse(false, [], 'Page Token không hợp lệ.');
}


// =====================================================
// 5. HÀM GỌI YOUTUBE DATA API
// =====================================================

function youtubeApiRequest($endpoint, $params = [])
{
    $params['key'] = YOUTUBE_API_KEY;

    $url = 'https://www.googleapis.com/youtube/v3/'
         . $endpoint . '?'
         . http_build_query($params);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new Exception('Lỗi kết nối YouTube API: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $message = $data['error']['message'] ?? 'YouTube API Error';

        throw new Exception(
            'YouTube API HTTP ' . $httpCode . ': ' . $message
        );
    }

    if (!is_array($data)) {
        throw new Exception('Dữ liệu YouTube API không hợp lệ.');
    }

    return $data;
}


// =====================================================
// 6. LẤY VIDEO VÀ TIẾN ĐỘ XEM
// =====================================================

try {

    // -----------------------------------------------
    // 6.1. Lấy video từ YouTube
    // -----------------------------------------------

    $result = youtubeApiRequest('playlistItems', [
        'part'       => 'snippet,contentDetails',
        'playlistId' => $playlistId,
        'maxResults' => YOUTUBE_MAX_RESULTS,
        'pageToken'  => $pageToken
    ]);


    // -----------------------------------------------
    // 6.2. Chuẩn hóa danh sách video
    // -----------------------------------------------

    $videos = [];

    foreach (($result['items'] ?? []) as $item) {

        $videoId = $item['snippet']['resourceId']['videoId'] ?? '';

        // Bỏ qua video bị xóa hoặc không khả dụng
        if ($videoId === '') {
            continue;
        }

        $snippet = $item['snippet'] ?? [];

        $thumbnail =
            $snippet['thumbnails']['medium']['url']
            ?? $snippet['thumbnails']['high']['url']
            ?? $snippet['thumbnails']['default']['url']
            ?? '';

        $videos[] = [
            'video_id' => $videoId,
            'title' => $snippet['title'] ?? 'Không có tiêu đề',
            'thumbnail' => $thumbnail,
            'published_at' => $snippet['publishedAt'] ?? ''
        ];
    }


    // -----------------------------------------------
    // 6.3. Lấy tiến độ xem của tất cả video
    // -----------------------------------------------

    // Chỉ truy vấn database một lần cho cả danh sách
    $progressMap = getVideoProgressMap($conn, $videos);


    // -----------------------------------------------
    // 6.4. Gắn tiến độ vào từng video
    // -----------------------------------------------

    foreach ($videos as &$video) {

        $progress = $progressMap[$video['video_id']] ?? null;

        // Video chưa xem hoặc chưa có dữ liệu tiến độ
        $video['progress'] = [
            'position_seconds' => $progress['position_seconds'] ?? 0,
            'duration_seconds' => $progress['duration_seconds'] ?? 0,
            'is_completed'     => $progress['is_completed'] ?? 0,
            'percent'          => $progress['percent'] ?? 0
        ];
    }

    unset($video);


    // =================================================
    // 7. TRẢ KẾT QUẢ JSON
    // =================================================

    jsonResponse(true, [
        'videos' => $videos,
        'nextPageToken' => $result['nextPageToken'] ?? '',
        'hasMore' => !empty($result['nextPageToken'])
    ]);

} catch (Throwable $e) {

    // Ghi lỗi chi tiết vào PHP error log
    error_log('YouTube AJAX Error: ' . $e->getMessage());

    http_response_code(500);

    jsonResponse(
        false,
        [],
        'Không thể tải video. Vui lòng thử lại sau.'
    );
}
