jQuery(document).ready(function($) {
    let canvas = null;
    let ctx = null;
    let prizes = [];
    let spinning = false;
    let currentAngle = 0;

    // 初始化画布
    function initCanvas() {
        canvas = document.getElementById('xb_lottery_wheel');
        if (!canvas) {
            setTimeout(initCanvas, 100);
            return;
        }
        ctx = canvas.getContext('2d');
        if (!ctx) {
            console.error('Canvas context not available');
            return;
        }
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        loadPrizes();
    }

    // 加载奖品数据
    function loadPrizes() {
        $.ajax({
            url: xb_lottery.ajax_url,
            type: 'POST',
            data: {
                action: 'xb_lottery_get_prizes',
                nonce: xb_lottery.nonce
            },
            success: function(response) {
                if (response.success && Array.isArray(response.data)) {
                    prizes = response.data;
                    drawWheel();
                } else {
                    console.error('Invalid prize data received:', response);
                    prizes = [];
                    drawWheel();
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to load prizes:', error);
                prizes = [];
                drawWheel();
            }
        });
    }

    // 绘制转盘
    function drawWheel() {
        if (!canvas || !ctx) return;
        const centerX = canvas.width / 2;
        const centerY = canvas.height / 2;
        const radius = canvas.width / 2 - 15;
        
        // 如果没有奖品，显示空转盘
        if (!prizes.length) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = '#ff6b6b';
            ctx.lineWidth = 5;
            ctx.stroke();
            ctx.fillStyle = '#000000';
            ctx.font = 'bold 16px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('暂无奖品', centerX, centerY);
            return;
        }

        const arc = Math.PI * 2 / prizes.length;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // 绘制转盘背景
        ctx.beginPath();
        ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#ff6b6b';
        ctx.lineWidth = 5;
        ctx.stroke();

        // 绘制奖品分区
        prizes.forEach((prize, index) => {
            const angle = index * arc;
            ctx.beginPath();
            ctx.fillStyle = index % 2 ? '#4ecdc4' : '#ff8e53';
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, angle, angle + arc);
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();

            // 绘制奖品名称
            ctx.save();
            ctx.translate(centerX, centerY);
            ctx.rotate(angle + arc / 2);
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 16px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(prize.prize_name, radius / 1.5, 0);
            ctx.restore();
        });

        // 绘制中心装饰
        ctx.beginPath();
        ctx.arc(centerX, centerY, 30, 0, Math.PI * 2);
        ctx.fillStyle = '#ff6b6b';
        ctx.fill();
    }

    // 更新中奖记录
    function updateRecords(prizeData) {
        $.ajax({
            url: xb_lottery.ajax_url,
            type: 'POST',
            data: {
                action: 'xb_lottery_get_latest_records',
                nonce: xb_lottery.nonce
            },
            success: function(response) {
                if (response.success) {
                    // 更新公共中奖记录
                    let winnersHtml = '';
                    response.data.records.forEach(record => {
                        const name = record.display_name.substring(0, 3) + '**';
                        let winnerItem = `${name} - ${record.prize_name} - ${record.activity_name}`;
                        if (record.is_physical && record.shipping_number) {
                            winnerItem += ` - 快递单号: ${record.shipping_number}`;
                        }
                        winnersHtml += `<div class="xb_lottery_winner_item">${winnerItem}</div>`;
                    });
                    $('#xb_lottery_winners_list').html(winnersHtml);

                    // 更新用户个人中奖记录
                    if (xb_lottery.is_user_logged_in && response.data.user_records) {
                        let userWinsHtml = '';
                        response.data.user_records.forEach(record => {
                            // 调整时间显示
                            const awardTime = new Date(new Date(record.award_time).getTime() + xb_lottery.timezone_offset * 1000);
                            const formattedTime = awardTime.toISOString().slice(0, 19).replace('T', ' ');
                            
                            let userWinItem = `${record.activity_name} - ${record.prize_name} - ${formattedTime}`;
                            if (record.is_physical && record.prize_type === 'physical') {
                                if (record.shipping_address) {
                                    userWinItem += ` - 收货地址: ${record.shipping_address}`;
                                    if (record.shipping_number) {
                                        userWinItem += ` - 快递单号: ${record.shipping_number}`;
                                    }
                                } else {
                                    userWinItem += ` - <button class="xb_lottery_address_button" data-record-id="${record.id}">填写收货地址</button>`;
                                }
                            } else if (record.virtual_info && record.prize_type === 'virtual') {
                                userWinItem += ` - 虚拟资源: ${record.virtual_info}`;
                            }
                            userWinsHtml += `<div class="xb_lottery_winner_item">${userWinItem}</div>`;
                        });
                        $('#xb_lottery_user_wins_list').html(userWinsHtml);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to update records:', error);
            }
        });
    }

    // 处理抽奖按钮点击
    $('#xb_lottery_spin').on('click', function() {
        if (spinning || !canvas || !ctx || $(this).prop('disabled')) return;
        if (!xb_lottery.is_user_logged_in) {
            showPopup('请先登录参与抽奖');
            return;
        }

        spinning = true;
        $(this).prop('disabled', true);

        $.ajax({
            url: xb_lottery.ajax_url,
            type: 'POST',
            data: {
                action: 'xb_lottery_spin',
                nonce: xb_lottery.nonce
            },
            success: function(response) {
                if (response.success) {
                    spinWheel(response.data.target_angle, response.data);
                } else if (response.data.no_spin) {
                    showPopup(response.data.message || '抽奖失败，请稍后重试');
                    spinning = false;
                    $('#xb_lottery_spin').prop('disabled', false);
                } else {
                    spinWheel(response.data.target_angle || (Math.random() * 360 + 720), {
                        message: response.data.message || '抽奖失败，请稍后重试',
                        prize_name: response.data.prize_name,
                        is_physical: response.data.is_physical,
                        prize_image: response.data.prize_image,
                        virtual_info: response.data.virtual_info,
                        prize_type: response.data.prize_type,
                        activity_name: response.data.activity_name,
                        record_id: response.data.record_id,
                        award_time: response.data.award_time
                    });
                }
            },
            error: function(xhr, status, error) {
                showPopup('抽奖失败，请稍后重试');
                spinning = false;
                $('#xb_lottery_spin').prop('disabled', false);
                console.error('Spin error:', error);
            }
        });
    });

    // 转盘动画
    function spinWheel(targetAngle, prizeData) {
        const duration = 4000;
        const startTime = Date.now();

        function animate() {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easeOut = 1 - Math.pow(1 - progress, 3);

            currentAngle = easeOut * targetAngle;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.save();
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(currentAngle * Math.PI / 180);
            ctx.translate(-canvas.width / 2, -canvas.height / 2);
            drawWheel();
            ctx.restore();

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                spinning = false;
                $('#xb_lottery_spin').prop('disabled', false);
                if (prizeData.message) {
                    showPopup(prizeData.message);
                } else {
                    showPrizeResult(prizeData);
                    updateRecords(prizeData);
                }
            }
        }

        requestAnimationFrame(animate);
    }

    // 显示中奖结果
    function showPrizeResult(prizeData) {
        $('#xb_lottery_popup_image').hide();
        $('#xb_lottery_popup_virtual').hide();
        $('#xb_lottery_popup_no_prize').hide();
        $('#xb_lottery_address_form').hide();
        $('#xb_lottery_record_id').val('');

        if (prizeData.prize_name === '谢谢参与') {
            $('#xb_lottery_popup_no_prize').show();
            $('#xb_lottery_popup_message').text('');
        } else {
            const message = `恭喜您在${prizeData.activity_name}活动中获得：${prizeData.prize_name}`;
            $('#xb_lottery_popup_message').text(message);

            if (prizeData.is_physical && prizeData.prize_type === 'physical') {
                if (prizeData.prize_image) {
                    $('#xb_lottery_popup_image img').attr('src', prizeData.prize_image);
                    $('#xb_lottery_popup_image').show();
                }
                $('#xb_lottery_address_form').show();
                $('#xb_lottery_record_id').val(prizeData.record_id || '');
            } else if (prizeData.virtual_info && prizeData.prize_type === 'virtual') {
                $('#xb_lottery_popup_virtual').text(`虚拟资源信息: ${prizeData.virtual_info}`).show();
            }
        }

        $('#xb_lottery_popup').css({
            display: 'flex',
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            'z-index': 10000,
            'justify-content': 'center',
            'align-items': 'center'
        });
    }

    // 显示弹出消息
    function showPopup(message) {
        $('#xb_lottery_popup_message').text(message);
        $('#xb_lottery_popup_image').hide();
        $('#xb_lottery_popup_virtual').hide();
        $('#xb_lottery_popup_no_prize').hide();
        $('#xb_lottery_address_form').hide();
        $('#xb_lottery_record_id').val('');

        $('#xb_lottery_popup').css({
            display: 'flex',
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            'z-index': 10000,
            'justify-content': 'center',
            'align-items': 'center'
        });
    }

    // 关闭弹出框
    $('.xb_lottery_popup_close').on('click', function() {
        $('#xb_lottery_popup').css('display', 'none');
        $('#xb_lottery_popup_image').hide();
        $('#xb_lottery_popup_virtual').hide();
        $('#xb_lottery_popup_no_prize').hide();
        $('#xb_lottery_address_form').hide();
        $('#xb_lottery_address').val('');
        $('#xb_lottery_record_id').val('');
    });

    // 处理填写地址按钮
    $(document).on('click', '.xb_lottery_address_button', function() {
        const recordId = $(this).data('record-id');
        if (!recordId) {
            showPopup('无效的记录ID');
            return;
        }
        $('#xb_lottery_popup_message').text('请填写您的收货地址');
        $('#xb_lottery_popup_image').hide();
        $('#xb_lottery_popup_virtual').hide();
        $('#xb_lottery_popup_no_prize').hide();
        $('#xb_lottery_address_form').show();
        $('#xb_lottery_record_id').val(recordId);
        $('#xb_lottery_popup').css({
            display: 'flex',
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            'z-index': 10000,
            'justify-content': 'center',
            'align-items': 'center'
        });
    });

    // 提交地址
    $('#xb_lottery_submit_address').on('click', function() {
        const address = $('#xb_lottery_address').val().trim();
        const recordId = $('#xb_lottery_record_id').val();

        if (!address) {
            showPopup('请输入有效的地址');
            return;
        }
        if (!recordId) {
            showPopup('无效的记录ID');
            return;
        }

        $.ajax({
            url: xb_lottery.ajax_url,
            type: 'POST',
            data: {
                action: 'xb_lottery_submit_address',
                nonce: xb_lottery.nonce,
                address: address,
                record_id: recordId
            },
            success: function(response) {
                if (response.success) {
                    showPopup(response.data.message);
                    setTimeout(() => {
                        $('#xb_lottery_popup').css('display', 'none');
                        updateRecords();
                    }, 2000);
                } else {
                    showPopup(response.data.message || '地址提交失败，请稍后重试');
                }
            },
            error: function(xhr, status, error) {
                showPopup('地址提交失败，请稍后重试');
                console.error('Address submission error:', error);
            }
        });
    });

    // 初始化
    initCanvas();
});