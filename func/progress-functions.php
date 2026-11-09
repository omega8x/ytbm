<?php
/**
 * Lấy tiến độ xem của nhiều video trong một lần truy vấn.
 *
 * @param mysqli $conn
 * @param array  $videos Danh sách video có trường video_id
 * @return array Tiến độ được đánh chỉ mục theo video_id
 */
if (!function_exists('getVideoProgressMap')) {

    function getVideoProgressMap(mysqli $conn, array $videos): array
    {
        $videoIds = [];

        foreach ($videos as $video) {
            $id = $video['video_id'] ?? '';

            if ($id !== '') {
                $videoIds[] = $id;
            }
        }

        // Loại bỏ ID trùng lặp
        $videoIds = array_values(array_unique($videoIds));

        if (empty($videoIds)) {
            return [];
        }

        // Tạo danh sách dấu ? cho câu SQL
        $placeholders = implode(
            ',',
            array_fill(0, count($videoIds), '?')
        );

        $sql = "
            SELECT
                video_id,
                position_seconds,
                duration_seconds,
                is_completed
            FROM youtube_video_progress
            WHERE video_id IN ($placeholders)
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($videoIds);

        $result = $stmt->get_result();

        $progressMap = [];

        while ($row = $result->fetch_assoc()) {

            $position = (int)$row['position_seconds'];
            $duration = (int)$row['duration_seconds'];
            $completed = (int)$row['is_completed'];

            // Tính phần trăm
            if ($completed) {
                $percent = 100;
            } elseif ($duration > 0 && $position > 0) {
                $percent = ($position / $duration) * 100;
                $percent = min(100, max(0, $percent));
            } else {
                $percent = 0;
            }

            $progressMap[$row['video_id']] = [
                'position_seconds' => $position,
                'duration_seconds' => $duration,
                'is_completed'     => $completed,
                'percent'           => round($percent, 1)
            ];
        }

        $stmt->close();

        return $progressMap;
    }
}

