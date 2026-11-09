<?php
/**
 * YOUTUBE FUNCTIONS
 *
 * Các chức năng:
 * - Gọi YouTube Data API.
 * - Lấy thông tin kênh từ MySQL.
 * - Lấy video từ Uploads Playlist.
 * - Chuẩn hóa dữ liệu video.
 */

require_once __DIR__ . '/../config/youtube.php';


// =====================================================
// 1. GỌI YOUTUBE DATA API
// =====================================================

if (!function_exists('youtubeApiRequest')) {

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

            throw new Exception(
                'Không thể kết nối YouTube API: ' . $error
            );
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {

            $message = $data['error']['message']
                ?? 'YouTube API Error';

            throw new Exception(
                'YouTube API HTTP ' . $httpCode . ': ' . $message
            );
        }

        if (!is_array($data)) {
            throw new Exception('Dữ liệu YouTube API không hợp lệ.');
        }

        return $data;
    }

}


// =====================================================
// 2. LẤY THÔNG TIN KÊNH TỪ DATABASE
// =====================================================

if (!function_exists('getChannelFromDatabase')) {

    /**
     * Tìm kênh bằng:
     * - YouTube Channel ID
     * - Channel Handle (có hoặc không có @)
     *
     * Chỉ lấy những kênh đang được kích hoạt.
     */

    function getChannelFromDatabase(mysqli $conn, $input)
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        // Chuẩn hóa Handle
        $handle = ltrim($input, '@');

        $sql = "
            SELECT
                id,
                channel_id,
                channel_handle,
                channel_title,
                uploads_playlist_id,
                channel_thumbnail
            FROM youtube_channels
            WHERE
                is_active = 1
                AND (
                    channel_id = ?
                    OR channel_handle = ?
                )
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            'ss',
            $input,
            $handle
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $channel = $result->fetch_assoc();

        $stmt->close();

        return $channel ?: null;
    }

}


// =====================================================
// 3. LẤY DANH SÁCH VIDEO TỪ UPLOADS PLAYLIST
// =====================================================

if (!function_exists('getChannelVideos')) {

    /**
     * $playlistId: Uploads Playlist ID của kênh.
     * $pageToken : Token phân trang, để trống ở trang đầu.
     */

    function getChannelVideos(
        $playlistId,
        $pageToken = '',
        ?int $maxResults = null
    ) {
        $maxResults = $maxResults ?? YOUTUBE_MAX_RESULTS;
        $maxResults = min(50, max(1, $maxResults));

        $params = [
            'part' => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => $maxResults
        ];

        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        // Cache riêng theo playlist, pageToken và số lượng video
        $cacheKey = md5(
            $playlistId . '|' . $pageToken . '|' . $maxResults
        );

        $cacheDir = sys_get_temp_dir() . '/youtube_library_cache';

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

        // Cache còn hạn: trả kết quả ngay, không gọi API
        if (is_file($cacheFile)) {
            $cacheData = json_decode(
                (string)file_get_contents($cacheFile),
                true
            );

            if (
                is_array($cacheData) &&
                isset($cacheData['saved_at'], $cacheData['data']) &&
                time() - $cacheData['saved_at'] < 300
            ) {
                return $cacheData['data'];
            }
        }

        // Cache hết hạn hoặc chưa có: gọi YouTube API
        $response = youtubeApiRequest('playlistItems', $params);

        // Lưu cache nếu thư mục cho phép ghi
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents(
                $cacheFile,
                json_encode([
                    'saved_at' => time(),
                    'data' => $response
                ], JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }

        return $response;
    }

}


// =====================================================
// 4. CHUẨN HÓA DANH SÁCH VIDEO
// =====================================================

if (!function_exists('formatVideos')) {

    function formatVideos($items)
    {
        $videos = [];

        foreach ($items as $item) {

            $videoId = $item['snippet']['resourceId']['videoId'] ?? '';

            // Bỏ qua video đã bị xóa hoặc không khả dụng
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

                'title' => $snippet['title']
                    ?? 'Không có tiêu đề',

                'thumbnail' => $thumbnail,

                'published_at' => $snippet['publishedAt'] ?? ''
            ];
        }

        return $videos;
    }

}
