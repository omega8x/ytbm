(function () {
    'use strict';

    const config = window.YT_PLAYER_CONFIG;

    if (!config) {
        console.error('Không tìm thấy cấu hình YouTube Player.');
        return;
    }

    let player = null;
    let saveTimer = null;
    let playerReady = false;
    let lastSavedPosition = -1;

    // Trạng thái tiến độ ban đầu
    let startPosition = Number(config.position) || 0;
    const videoDuration = Number(config.duration) || 0;

    // --------------------------------------------------
    // Tải YouTube IFrame Player API
    // --------------------------------------------------
    const tag = document.createElement('script');
    tag.src = 'https://www.youtube.com/iframe_api';

    document.head.appendChild(tag);

    // YouTube gọi hàm này khi API đã tải xong
    window.onYouTubeIframeAPIReady = function () {

        player = new YT.Player('youtubePlayer', {
            videoId: config.videoId,

            playerVars: {
                autoplay: 1,
                rel: 0,
                modestbranding: 1
            },

            events: {
                onReady: onPlayerReady,
                onStateChange: onPlayerStateChange,
                onError: onPlayerError
            }
        });
    };

    // --------------------------------------------------
    // Player đã sẵn sàng
    // --------------------------------------------------
    function onPlayerReady(event) {
        playerReady = true;

        // Tự động tiếp tục tại vị trí cũ
        if (startPosition > 0) {
            event.target.seekTo(startPosition, true);
        }

        // Tự động phát
        event.target.playVideo();

        // Bắt đầu chu kỳ lưu tiến độ 30 giây
        saveTimer = setInterval(function () {
            saveProgress(false);
        }, 15000);
    }

    // --------------------------------------------------
    // Theo dõi trạng thái phát / tạm dừng / kết thúc
    // --------------------------------------------------
    function onPlayerStateChange(event) {

        // Đang phát
        if (event.data === YT.PlayerState.PLAYING) {
            console.log('[Player] Đang phát video.');
        }

        // Tạm dừng
        if (event.data === YT.PlayerState.PAUSED) {
            saveProgress(false);
        }

        // Video kết thúc
        if (event.data === YT.PlayerState.ENDED) {

            console.log('[Player] Video đã phát hết.');

            // Dừng bộ đếm lưu tiến độ thông thường
            if (saveTimer) {
                clearInterval(saveTimer);
                saveTimer = null;
            }

            // Lưu trạng thái hoàn thành
            saveProgress(true);
        }
    }

    function onPlayerError(event) {
        console.error('[Player] YouTube error:', event.data);
    }

    // --------------------------------------------------
    // Lấy thời gian hiện tại của video
    // --------------------------------------------------
    function getCurrentPosition() {
        if (!playerReady || !player) {
            return 0;
        }

        const value = Number(player.getCurrentTime()) || 0;
        return Math.max(0, Math.floor(value));
    }

// Lấy thời lượng thực tế từ YouTube Player
function getCurrentDuration() {
    if (!playerReady || !player) {
        return videoDuration;
    }

    const duration = Number(player.getDuration()) || 0;

    // Ưu tiên thời lượng YouTube trả về
    return duration > 0 ? Math.floor(duration) : videoDuration;
}

    // --------------------------------------------------
    // Gửi dữ liệu lưu tiến độ
    // --------------------------------------------------
    function buildProgressData(completed) {

        let position = getCurrentPosition();
        let duration = getCurrentDuration();

        if (completed) {
            // Khi video kết thúc, lưu đúng thời lượng thực tế
            position = duration;
        }

        return {
            video_id: config.videoId,
            channel_id: config.channelId,
            position_seconds: position,
            duration_seconds: duration,
            is_completed: completed ? 1 : 0
        };
    }

    function saveProgress(completed, useBeacon = false) {

        if (!playerReady || !player) {
            return;
        }

        const data = buildProgressData(completed);

        // Không gửi lặp lại nếu vị trí không thay đổi
        if (
            !completed &&
            data.position_seconds === lastSavedPosition
        ) {
            return;
        }

        lastSavedPosition = data.position_seconds;

        const payload = JSON.stringify(data);

        // Khi rời trang: ưu tiên sendBeacon để tránh request bị hủy
        if (useBeacon && navigator.sendBeacon) {

            const blob = new Blob(
                [payload],
                { type: 'application/json' }
            );

            navigator.sendBeacon(config.saveUrl, blob);
            return;
        }

        fetch(config.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: payload,

            // Giữ request trong một số tình huống trang đang đóng
            keepalive: useBeacon
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {

                console.log(
                    '[Progress] Đã lưu:',
                    data.position_seconds,
                    'giây'
                );

                // Hiển thị thông báo ngay khi lưu thành công
                    window.showSaveNotification();

            } else {
                console.error('[Progress] Lưu thất bại:', result.message);
            }
        })
        .catch(error => {
            console.error('[Progress] Lỗi lưu tiến độ:', error);
        });
    }

    // --------------------------------------------------
    // Lưu khi người dùng rời trang / chuyển tab
    // --------------------------------------------------
    window.addEventListener('pagehide', function () {
        saveProgress(false, true);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            saveProgress(false, true);
        }
    });

    // Có thể gọi từ console để kiểm tra
    window.saveYouTubeProgress = function () {
        saveProgress(false);
    };

    // ==================================================
    // HIỂN THỊ THÔNG BÁO ĐÃ LƯU TIẾN ĐỘ
    // ==================================================
    window.showSaveNotification = function () {

        const notification = document.getElementById('saveNotification');

        console.log('[Notification] Element:', notification);

        if (!notification) {
            console.error('[Notification] Không tìm thấy saveNotification!');
            return;
        }

        // Ép hiển thị
        notification.style.setProperty('display', 'inline-block', 'important');
        notification.style.setProperty('visibility', 'visible', 'important');
        notification.style.setProperty('opacity', '1', 'important');

        // Hủy bộ đếm cũ
        clearTimeout(window.saveNotificationTimer);

        // Tự ẩn sau 3 giây
        window.saveNotificationTimer = setTimeout(function () {
            notification.style.setProperty('display', 'none', 'important');
        }, 3000);
    };

})();

// --------------------------------------------------
// Hiển thị thông báo đã lưu tiến độ
// Tự ẩn sau 3 giây
// --------------------------------------------------
// let notificationTimer = null;

// function showSaveNotification() {

//     const notification = document.getElementById('saveNotification');

//     if (!notification) return;

//     // Hiển thị thông báo
//     notification.style.display = 'inline';

//     // Nếu có thông báo cũ đang chờ ẩn thì hủy
//     clearTimeout(notificationTimer);

//     // Tự ẩn sau 3 giây
//     notificationTimer = setTimeout(function () {
//         notification.style.display = 'none';
//     }, 3000);
// }