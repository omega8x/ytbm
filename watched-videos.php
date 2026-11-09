<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/func/common-functions.php';

$pageTitle = 'Video đã xem';
?>

<!DOCTYPE html>
<html lang="vi">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= h($pageTitle) ?></title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>"
    >

    <style>
        .watched-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .watched-card {
            height: 100%;
            min-width: 0;
            overflow: hidden;
        }

        .watched-card .video-link {
            display: block;
            color: inherit;
            text-decoration: none;
        }

        .watched-card .video-thumbnail-wrap {
            aspect-ratio: 16 / 9;
        }

        .watched-card .video-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .watched-card .card-body {
            padding: 10px;
        }

        .watched-title {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 40px;
            overflow-wrap: anywhere;
        }

        .watched-channel {
            font-size: 12px;
            color: #6c757d;
            margin-top: 6px;
            overflow-wrap: anywhere;
        }

        .watched-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 5px;
            margin-top: 8px;
            font-size: 12px;
            color: #6c757d;
        }

        .watched-status {
            font-size: 11px;
            white-space: nowrap;
        }

        .watched-empty {
            padding: 50px 15px;
            text-align: center;
            color: #6c757d;
        }

        .watched-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            margin: 25px 0;
        }

        #watchedLoading {
            padding: 35px 10px;
            text-align: center;
        }
    </style>

</head>

<body>

<div class="container px-3 px-lg-4 py-4">

    <!-- ==============================
         HEADER
    =============================== -->

    <div class="d-flex align-items-center flex-wrap gap-2 mb-4">

        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-house"></i>
            Trang chủ
        </a>

        <h4 class="mb-0 ms-2">
            <i class="bi bi-clock-history"></i>
            Video đã xem
        </h4>

    </div>


    <!-- ==============================
         TOOLBAR
    =============================== -->

    <div class="watched-toolbar">

        <div class="text-muted small" id="watchedSummary">
            Đang tải lịch sử xem...
        </div>

        <div class="d-flex align-items-center gap-2">

            <label for="perPageSelect" class="small text-muted">
                Hiển thị:
            </label>

            <select
                id="perPageSelect"
                class="form-select form-select-sm"
                style="width: auto;"
            >
                <option value="12">12 video</option>
                <option value="24" selected>24 video</option>
                <option value="36">36 video</option>
                <option value="48">48 video</option>
            </select>

        </div>

    </div>


    <!-- ==============================
         VIDEO LIST
    =============================== -->

    <div id="watchedLoading" style="display:none;">

        <div class="spinner-border text-primary"></div>

        <div class="small text-muted mt-2">
            Đang tải video...
        </div>

    </div>


    <div id="watchedContainer" class="row g-3">
    </div>


    <!-- ==============================
         EMPTY / ERROR MESSAGE
    =============================== -->

    <div id="watchedMessage" class="watched-empty" style="display:none;">
    </div>


    <!-- ==============================
         PAGINATION
    =============================== -->

    <div id="watchedPagination" class="watched-pagination" style="display:none;">

        <button
            type="button"
            id="btnPrevious"
            class="btn btn-sm btn-outline-secondary"
        >
            <i class="bi bi-chevron-left"></i>
            Trước
        </button>

        <span id="pageInfo" class="small text-muted"></span>

        <button
            type="button"
            id="btnNext"
            class="btn btn-sm btn-outline-secondary"
        >
            Sau
            <i class="bi bi-chevron-right"></i>
        </button>

    </div>

</div>


<script>

// =====================================================
// 1. CẤU HÌNH
// =====================================================

const ajaxUrl = 'ajax/load-watched-videos.php';

let currentPage = 1;

let perPage = 24;

let totalPages = 1;

let isLoading = false;


// =====================================================
// 2. ELEMENT
// =====================================================

const watchedContainer = document.getElementById('watchedContainer');

const watchedLoading = document.getElementById('watchedLoading');

const watchedMessage = document.getElementById('watchedMessage');

const watchedSummary = document.getElementById('watchedSummary');

const watchedPagination = document.getElementById('watchedPagination');

const pageInfo = document.getElementById('pageInfo');

const btnPrevious = document.getElementById('btnPrevious');

const btnNext = document.getElementById('btnNext');

const perPageSelect = document.getElementById('perPageSelect');


// =====================================================
// 3. ESCAPE HTML
// =====================================================

function escapeHtml(value) {

    return String(value ?? '').replace(/[&<>"']/g, function(char) {

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
// 4. FORMAT NGÀY GIỜ
// =====================================================

function formatDateTime(value) {

    if (!value) return '';

    // MySQL DATETIME có dạng YYYY-MM-DD HH:mm:ss
    const date = new Date(value.replace(' ', 'T'));

    if (isNaN(date.getTime())) return '';

    return date.toLocaleString('vi-VN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });

}


// =====================================================
// 5. TẠO HTML VIDEO
// =====================================================

function createWatchedVideoElement(video) {

    const col = document.createElement('div');

    col.className = 'col-6 col-md-4 col-lg-3';

    const percent = Math.min(
        100,
        Math.max(0, Number(video.percent) || 0)
    );

    const completed = Number(video.is_completed) === 1;

    const progressBar = percent > 0
        ? `
            <div class="video-progress-track">
                <div
                    class="video-progress-fill"
                    style="width:${percent}%"
                ></div>
            </div>
        `
        : '';

    const status = completed
        ? '<span class="badge bg-success watched-status">Đã xem hết</span>'
        : '<span class="badge bg-secondary watched-status">Đang xem</span>';

    const thumbnail = video.thumbnail
        ? escapeHtml(video.thumbnail)
        : '';

    const title = escapeHtml(video.title);

    const channel = escapeHtml(video.channel_title);

    const watchedAt = escapeHtml(
        formatDateTime(video.last_watched_at)
    );

    const videoUrl =
        'play-video.php?video_id=' +
        encodeURIComponent(video.video_id) +
        '&channel_id=' +
        encodeURIComponent(video.channel_id);

    col.innerHTML = `
        <div class="card watched-card shadow-sm">

            <a href="${videoUrl}" class="video-link">

                <div class="video-thumbnail-wrap">

                    ${thumbnail
                        ? `<img
                            src="${thumbnail}"
                            class="video-thumbnail"
                            alt=""
                            loading="lazy"
                        >`
                        : `<div class="video-thumbnail bg-light d-flex align-items-center justify-content-center">
                            <i class="bi bi-play-circle text-secondary fs-1"></i>
                        </div>`
                    }

                    ${progressBar}

                </div>

                <div class="card-body">

                    <div class="watched-title">
                        ${title}
                    </div>

                    <div class="watched-channel">
                        <i class="bi bi-collection-play"></i>
                        ${channel}
                    </div>

                    <div class="watched-meta">

                        <span>
                            <i class="bi bi-clock"></i>
                            ${watchedAt}
                        </span>

                        ${status}

                    </div>

                </div>

            </a>

        </div>
    `;

    return col;

}


// =====================================================
// 6. LOAD VIDEO BẰNG AJAX
// =====================================================

async function loadWatchedVideos(page = 1) {

    if (isLoading) return;

    isLoading = true;

    currentPage = page;

    watchedLoading.style.display = 'block';

    watchedContainer.style.display = 'none';

    watchedMessage.style.display = 'none';

    watchedPagination.style.display = 'none';

    try {

        const response = await fetch(ajaxUrl, {

            method: 'POST',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify({
                page: currentPage,
                perPage: perPage
            })

        });


        const result = await response.json();


        if (!response.ok || !result.success) {

            throw new Error(
                result.message || 'Không thể tải lịch sử xem.'
            );

        }


        const videos = result.data.videos || [];

        const pagination = result.data.pagination;


        // Xóa danh sách cũ
        watchedContainer.innerHTML = '';


        // Render video
        videos.forEach(video => {

            watchedContainer.appendChild(
                createWatchedVideoElement(video)
            );

        });


        // Cập nhật tổng số
        watchedSummary.textContent =
            'Tổng cộng ' +
            Number(pagination.totalVideos).toLocaleString('vi-VN') +
            ' video đã xem';


        totalPages = pagination.totalPages;

        currentPage = pagination.page;


        // Không có video
        if (videos.length === 0) {

            watchedMessage.textContent =
                'Bạn chưa có video nào trong lịch sử xem.';

            watchedMessage.style.display = 'block';

            watchedContainer.style.display = 'none';

            return;

        }


        watchedContainer.style.display = 'flex';


        // Cập nhật phân trang
        pageInfo.textContent =
            'Trang ' + currentPage + ' / ' + totalPages;


        btnPrevious.disabled = !pagination.hasPrevious;

        btnNext.disabled = !pagination.hasNext;


        watchedPagination.style.display = 'flex';


    } catch (error) {

        console.error('Watched Videos Error:', error);

        watchedMessage.textContent = error.message;

        watchedMessage.style.display = 'block';

        watchedContainer.innerHTML = '';

        watchedContainer.style.display = 'none';

    } finally {

        watchedLoading.style.display = 'none';

        isLoading = false;

    }

}


// =====================================================
// 7. SỰ KIỆN PHÂN TRANG
// =====================================================

btnPrevious.addEventListener('click', function() {

    if (currentPage > 1) {
        loadWatchedVideos(currentPage - 1);
    }

});


btnNext.addEventListener('click', function() {

    if (currentPage < totalPages) {
        loadWatchedVideos(currentPage + 1);
    }

});


// =====================================================
// 8. THAY ĐỔI SỐ VIDEO MỖI TRANG
// =====================================================

perPageSelect.addEventListener('change', function() {

    perPage = parseInt(this.value, 10) || 24;

    loadWatchedVideos(1);

});


// =====================================================
// 9. TẢI TRANG ĐẦU TIÊN
// =====================================================

loadWatchedVideos(1);

</script>

</body>
</html>
