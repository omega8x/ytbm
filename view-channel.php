<?php
/**
 * VIEW CHANNEL
 *
 * Chức năng:
 * - Lấy thông tin kênh từ MySQL.
 * - Lấy danh sách video từ YouTube Uploads Playlist.
 * - Hỗ trợ Grid / List.
 * - AJAX Load More.
 * - Click video chuyển sang play-video.php.
 */


// =====================================================
// 1. LOAD CONFIG VÀ FUNCTIONS
// =====================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/func/common-functions.php';
require_once __DIR__ . '/func/youtube-functions.php';
require_once __DIR__ . '/func/progress-functions.php';

// =====================================================
// 2. KHỞI TẠO DỮ LIỆU
// =====================================================

$error = '';

$channel = null;

$videos = [];

$nextPageToken = '';


// =====================================================
// 3. LẤY THÔNG TIN KÊNH VÀ VIDEO
// =====================================================

try {

    // Nhận Channel ID hoặc Handle từ URL
    //
    // Ví dụ:
    // view-channel.php?channel_id=UCTBQCqgOvNNT70wiJ3tECew
    //
    // Hoặc:
    // view-channel.php?channel=@tenkenh

    $channelInput = trim(
        $_GET['channel_id']
        ?? $_GET['channel']
        ?? ''
    );

    if ($channelInput === '') {
        throw new Exception('Thiếu thông tin kênh YouTube.');
    }


    // -------------------------------------------------
    // 3.1. Lấy thông tin kênh từ DATABASE
    // -------------------------------------------------

    $channel = getChannelFromDatabase(
        $conn,
        $channelInput
    );

    if (!$channel) {
        throw new Exception(
            'Không tìm thấy kênh hoặc kênh đã bị vô hiệu hóa.'
        );
    }


    // -------------------------------------------------
    // 3.2. Kiểm tra Uploads Playlist ID
    // -------------------------------------------------

    $playlistId = $channel['uploads_playlist_id'] ?? '';

    if (
        $playlistId === '' ||
        !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $playlistId)
    ) {
        throw new Exception(
            'Uploads Playlist ID của kênh không hợp lệ.'
        );
    }


    // -------------------------------------------------
    // 3.3. Lấy video trang đầu tiên từ YouTube API
    // -------------------------------------------------

    $result = getChannelVideos($playlistId);

    $videos = formatVideos(
        $result['items'] ?? []
    );

    $nextPageToken = $result['nextPageToken'] ?? '';


} catch (Throwable $e) {

    error_log(
        'View Channel Error: ' . $e->getMessage()
    );

    $error = $e->getMessage();
}


// =====================================================
// 4. URL AJAX
// =====================================================

$ajaxUrl = 'ajax/load-channel-videos.php';


// =====================================================
// 5. URL PLAY VIDEO
// =====================================================

$playVideoUrl = 'play-video.php';

?>

<!DOCTYPE html>
<html lang="vi">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= $channel
            ? h($channel['channel_title'])
            : 'YouTube Channel'
        ?>
    </title>


    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet"
      href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    
    <style>

        /* =========================================
           VIDEO CARD
        ========================================= */

        .video-card {
            height: 100%;
            cursor: pointer;
            transition: transform .15s;
        }

        .video-card:hover {
            transform: translateY(-3px);
        }

        .video-thumbnail {
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
        }

        .video-title {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
        }

        .video-date {
            font-size: 12px;
            color: #777;
        }


        /* =========================================
           LIST VIEW
        ========================================= */

        #videoContainer.view-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        #videoContainer.view-list > .video-item {
            padding: 0 !important;
            margin: 0 !important;
            width: 100%;
        }

        #videoContainer.view-list .card {
            margin: 0 !important;
        }

        #videoContainer.view-list .video-card {
            display: flex;
            flex-direction: row;
            height: auto;
        }

        #videoContainer.view-list .video-thumbnail {
            width: 180px;
            min-width: 180px;
            height: 90px;
            aspect-ratio: unset;
            object-fit: cover;
        }

        #videoContainer.view-list .card-body {
            flex: 1;
            padding: 6px 10px !important;
        }


        /* =========================================
           RESPONSIVE LIST VIEW
        ========================================= */

        @media (max-width: 576px) {

            #videoContainer.view-list .video-thumbnail {
                width: 125px;
                min-width: 125px;
                height: 75px;
            }

            #videoContainer.view-list .video-title {
                font-size: 12px;
            }

        }


        /* =========================================
           VIDEO CARD TITLE
        ========================================= */

        .video-card .video-title {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            overflow: hidden;
        }

    </style>

</head>


<body class="bg-light">

<div class="container py-4">


    <?php if ($error): ?>

        <!-- =======================================
             THÔNG BÁO LỖI
        ======================================== -->

        <div class="alert alert-danger">

            <strong>Có lỗi xảy ra:</strong>

            <?= h($error) ?>

        </div>

        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i>
            Quay lại trang chủ
        </a>


    <?php else: ?>


        <!-- =======================================
             THÔNG TIN CHANNEL
        ======================================== -->

        <div class="d-flex align-items-center gap-3 mb-4">

            <?php if (!empty($channel['channel_thumbnail'])): ?>

                <img
                    src="<?= h($channel['channel_thumbnail']) ?>"
                    width="60"
                    height="60"
                    class="rounded-circle"
                    alt=""
                >

            <?php endif; ?>


            <div>

                <h4 class="mb-1">

                    <?= h($channel['channel_title']) ?>

                </h4>

                <small class="text-muted">

                    Channel ID:
                    <?= h($channel['channel_id']) ?>

                </small>

            </div>

        </div>


        <!-- =======================================
             TIÊU ĐỀ + GRID / LIST
        ======================================== -->

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div class="fw-semibold">

                Danh sách video

            </div>


            <div class="btn-group btn-group-sm">

                <button
                    type="button"
                    class="btn btn-outline-primary"
                    id="btnGrid"
                >
                    <i class="bi bi-grid-3x3-gap"></i>
                    Grid
                </button>


                <button
                    type="button"
                    class="btn btn-outline-primary"
                    id="btnList"
                >
                    <i class="bi bi-list-ul"></i>
                    List
                </button>

            </div>

        </div>


        <!-- =======================================
             DANH SÁCH VIDEO
        ======================================== -->

        <div
            class="row g-3 view-grid"
            id="videoContainer"
        >
            <?php
            $progressMap = getVideoProgressMap($conn, $videos);
            ?>

            <?php foreach ($videos as $video): ?>

                <div class="col-6 col-md-4 col-lg-3 video-item">

                    <div
                        class="card video-card shadow-sm"

                        data-video-id="<?= h($video['video_id']) ?>"

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


                        <div class="card-body p-2">

                            <div class="video-title mb-2">

                                <?= h($video['title']) ?>

                            </div>


                            <div class="video-date">

                                <?php if (!empty($video['published_at'])): ?>

                                    <?= date(
                                        'd/m/Y',
                                        strtotime($video['published_at'])
                                    ) ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <!-- =======================================
             AJAX LOAD MORE
        ======================================== -->

        <?php if ($nextPageToken): ?>

            <div
                class="text-center mt-4"
                id="loadMoreArea"
            >

                <button
                    type="button"
                    class="btn btn-primary"
                    id="btnLoadMore"
                >

                    <i class="bi bi-plus-circle"></i>

                    Xem thêm video

                </button>


                <div
                    class="text-muted small mt-2"
                    id="loadMoreMessage"
                ></div>

            </div>

        <?php endif; ?>


        <!-- =======================================
             KHÔNG CÓ VIDEO
        ======================================== -->

        <?php if (empty($videos)): ?>

            <div class="alert alert-info mt-3">

                Không tìm thấy video nào trong danh sách.

            </div>

        <?php endif; ?>


    <?php endif; ?>


</div>


<?php if (!$error && $channel): ?>

<script>

// =====================================================
// 1. CÁC THÀNH PHẦN HTML
// =====================================================

const videoContainer = document.getElementById('videoContainer');

const btnGrid = document.getElementById('btnGrid');

const btnList = document.getElementById('btnList');

const btnLoadMore = document.getElementById('btnLoadMore');

const loadMoreMessage = document.getElementById('loadMoreMessage');


// =====================================================
// 2. DỮ LIỆU KÊNH
// =====================================================

// Uploads Playlist ID được lấy từ database.
// Không có API Key trong JavaScript.

const playlistId = <?= json_encode(
    $channel['uploads_playlist_id'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;


// Channel ID

const channelId = <?= json_encode(
    $channel['channel_id'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;


// Token trang tiếp theo

let nextPageToken = <?= json_encode(
    $nextPageToken,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;


// URL AJAX

const ajaxUrl = <?= json_encode($ajaxUrl) ?>;


// URL trang phát video

const playVideoUrl = <?= json_encode($playVideoUrl) ?>;


// Trạng thái tải

let isLoading = false;


// =====================================================
// 3. GRID / LIST VIEW
// =====================================================

function setVideoView(view) {

    if (!videoContainer) return;

    videoContainer.classList.remove(
        'view-grid',
        'view-list'
    );

    videoContainer.classList.add('view-' + view);


    btnGrid.classList.toggle(
        'active',
        view === 'grid'
    );

    btnList.classList.toggle(
        'active',
        view === 'list'
    );


    // Lưu lựa chọn vào trình duyệt
    localStorage.setItem(
        'youtube_video_view',
        view
    );

}


// Click Grid

btnGrid.addEventListener('click', function () {

    setVideoView('grid');

});


// Click List

btnList.addEventListener('click', function () {

    setVideoView('list');

});


// Khôi phục giao diện đã chọn

const savedView =
    localStorage.getItem('youtube_video_view') || 'grid';

setVideoView(
    savedView === 'list' ? 'list' : 'grid'
);


// =====================================================
// 4. TẠO HTML CHO VIDEO MỚI
// =====================================================


// =====================================================
// TẠO HTML CHO MỘT VIDEO
// Có hiển thị thanh tiến độ xem
// =====================================================

function createVideoElement(video) {

    const col = document.createElement('div');

    // Giữ nguyên cấu trúc Grid hiện tại
    col.className = 'col-6 col-md-4 col-lg-3 video-item';

    const progress = video.progress || {};

    // Phần trăm đã xem
    const percent = Math.min(
        100,
        Math.max(0, Number(progress.percent) || 0)
    );

    // Tạo thanh tiến độ nếu video đã xem
    const progressBar = percent > 0
        ? `
            <div class="video-progress-track">
                <div
                    class="video-progress-fill"
                    style="width: ${percent}%"
                ></div>
            </div>
        `
        : '';

    col.innerHTML = `
        <div class="card video-card shadow-sm">

            <a
                href="play-video.php?video_id=${encodeURIComponent(video.video_id)}&channel_id=${encodeURIComponent(channelId)}"
                class="text-decoration-none text-dark"
            >

                <div class="video-thumbnail-wrap">

                    <img
                        src="${escapeHtml(video.thumbnail || '')}"
                        class="card-img-top video-thumbnail"
                        alt=""
                        loading="lazy"
                    >

                    ${progressBar}

                </div>

                <div class="card-body p-2">

                    <div class="video-title mb-2">
                        ${escapeHtml(video.title || '')}
                    </div>

                    <div class="video-date">
                        ${video.published_at
                            ? new Date(video.published_at).toLocaleDateString('vi-VN')
                            : ''
                        }
                    </div>

                </div>

            </a>

        </div>
    `;

    return col;
}


// =====================================================
// ESCAPE HTML
// Tránh chèn HTML không mong muốn từ dữ liệu video
// =====================================================

function escapeHtml(value) {

    return String(value).replace(/[&<>"']/g, function(char) {

        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];

    });

}



// =====================================================
// 5. AJAX LOAD MORE
// =====================================================

async function loadMoreVideos() {

    // Không cho tải nhiều lần cùng lúc

    if (isLoading || !nextPageToken) {
        return;
    }


    isLoading = true;

    btnLoadMore.disabled = true;

    btnLoadMore.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2"></span>' +
        'Đang tải video...';

    loadMoreMessage.textContent = 'Vui lòng chờ...';


    try {

        // Gửi yêu cầu đến PHP

        const response = await fetch(ajaxUrl, {

            method: 'POST',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify({

                playlist_id: playlistId,

                pageToken: nextPageToken

            })

        });


        const result = await response.json();


        // Kiểm tra lỗi

        if (!response.ok || !result.success) {

            throw new Error(
                result.message || 'Không thể tải video.'
            );

        }


        // Danh sách video nhận được

        const videos = result.data.videos || [];


        // Nối video vào cuối danh sách

        videos.forEach(video => {

            const element = createVideoElement(video);

            videoContainer.appendChild(element);

        });


        // Cập nhật token

        nextPageToken = result.data.nextPageToken || '';


        // Hết video

        if (!nextPageToken || !result.data.hasMore) {

            document.getElementById('loadMoreArea')?.remove();

        } else {

            btnLoadMore.disabled = false;

            btnLoadMore.innerHTML =
                '<i class="bi bi-plus-circle"></i> Xem thêm video';

            loadMoreMessage.textContent =
                'Đã tải thêm ' + videos.length + ' video.';

        }


    } catch (error) {

        console.error('Load More Error:', error);

        loadMoreMessage.textContent = error.message;

        btnLoadMore.disabled = false;

        btnLoadMore.innerHTML =
            '<i class="bi bi-arrow-clockwise"></i> Thử tải lại';


    } finally {

        isLoading = false;

    }

}


// =====================================================
// 6. CLICK NÚT XEM THÊM
// =====================================================

if (btnLoadMore) {

    btnLoadMore.addEventListener(
        'click',
        loadMoreVideos
    );

}


// =====================================================
// 7. CLICK VIDEO -> PLAY VIDEO PAGE
// =====================================================

// Event Delegation:
// Video được thêm bằng AJAX cũng hoạt động.

videoContainer.addEventListener('click', function (event) {

    const card = event.target.closest('.video-card');

    if (!card) return;


    const videoId = card.dataset.videoId;

    if (!videoId) return;


    // Chuyển sang trang phát video

    const url =
        playVideoUrl +
        '?video_id=' + encodeURIComponent(videoId) +
        '&channel_id=' + encodeURIComponent(channelId);

    window.location.href = url;

});

</script>

<?php endif; ?>

</body>
</html>
