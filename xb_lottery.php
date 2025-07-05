<?php
/*
Plugin Name: 小半大转盘抽奖插件
Description: 大转盘抽奖插件，支持自定义奖品、抽奖次数、中奖率等
Plugin URI: https://www.jingxialai.com/5011.html
Version: 1.0.3
Author: Summer
License: GPL License
Author URI: https://www.jingxialai.com/
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('XB_LOTTERY_VERSION', '1.0.3');
define('XB_LOTTERY_PATH', plugin_dir_path(__FILE__));
define('XB_LOTTERY_URL', plugin_dir_url(__FILE__));

// 创建数据库表
function xb_lottery_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'xb_lottery';
    $records_table = $wpdb->prefix . 'xb_lottery_records';
    $activities_table = $wpdb->prefix . 'xb_lottery_activities';
    $attempts_table = $wpdb->prefix . 'xb_lottery_attempts';

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        activity_id bigint(20) NOT NULL,
        prize_name varchar(255) NOT NULL,
        prize_type varchar(20) NOT NULL,
        probability float NOT NULL,
        is_physical boolean NOT NULL DEFAULT 0,
        prize_image varchar(255) DEFAULT '',
        virtual_info text,
        max_wins int NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;

    CREATE TABLE $records_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        activity_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        prize_id bigint(20) NOT NULL,
        award_time datetime NOT NULL,
        shipping_address text,
        shipping_status varchar(50) DEFAULT 'pending',
        shipping_number varchar(100) DEFAULT '',
        virtual_info text,
        PRIMARY KEY (id)
    ) $charset_collate;

    CREATE TABLE $activities_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        activity_name varchar(255) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;

    CREATE TABLE $attempts_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        activity_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        attempt_count int NOT NULL DEFAULT 0,
        last_attempt datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_activity (user_id, activity_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // 创建默认活动
    $activity_id = $wpdb->get_var("SELECT id FROM $activities_table ORDER BY created_at DESC LIMIT 1");
    if (!$activity_id) {
        $wpdb->insert(
            $activities_table,
            array(
                'activity_name' => '默认活动',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s')
        );
    }
}

// 创建抽奖页面
function xb_lottery_create_page() {
    $pages = get_posts(array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        's'           => '[xb_lottery]',
        'numberposts' => 1,
    ));

    if (empty($pages)) {
        $page_id = wp_insert_post(array(
            'post_title'    => '大转盘抽奖活动',
            'post_content'  => '[xb_lottery]',
            'post_status'   => 'publish',
            'post_author'   => 1,
            'post_type'     => 'page',
        ));
    }
}

// 加载前端资源
function xb_lottery_enqueue_assets() {
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'xb_lottery')) {
        wp_enqueue_style(
            'xb_lottery_style',
            XB_LOTTERY_URL . 'xb_lottery.css',
            array(),
            XB_LOTTERY_VERSION
        );
        wp_enqueue_script(
            'xb_lottery_script',
            XB_LOTTERY_URL . 'xb_lottery.js',
            array('jquery'),
            XB_LOTTERY_VERSION,
            true
        );
        
        // 传递必要数据到前端
        wp_localize_script('xb_lottery_script', 'xb_lottery', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('xb_lottery_nonce'),
            'is_user_logged_in' => is_user_logged_in(),
            'timezone_offset' => get_option('gmt_offset') * 3600
        ));
    }
}
add_action('wp_enqueue_scripts', 'xb_lottery_enqueue_assets');

// 注册短代码
function xb_lottery_shortcode() {
    global $wpdb;
    $user_id = get_current_user_id();
    
    // 获取当前活动
    $current_activity = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
    $activity_id = $current_activity ? $current_activity->id : 0;
    $activity_name = $current_activity ? $current_activity->activity_name : '抽奖活动';
    
    // 获取设置
    $settings = get_option('xb_lottery_settings', array(
        'max_attempts' => 2,
        'guest_message' => '请先登录参与抽奖',
        'notification_type' => 'popup',
        'max_wins' => 1,
        'start_time' => current_time('mysql'),
        'end_time' => date('Y-m-d H:i:s', strtotime('+1 month')),
        'ad_content' => '',
        'is_active' => 0,
        'activity_name' => $current_activity ? $current_activity->activity_name : '抽奖活动' // 使用数据库中的活动名称
    ));

    $is_active = $settings['is_active'];
    $now = current_time('timestamp');
    $start_time = strtotime($settings['start_time']);
    $end_time = strtotime($settings['end_time']);
    $is_within_time = $now >= $start_time && $now <= $end_time;

    // 检查奖品是否抽完
    $prizes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}xb_lottery WHERE activity_id = %d AND max_wins > 0", $activity_id));
    $is_prizes_depleted = true;
    foreach ($prizes as $prize) {
        $win_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}xb_lottery_records WHERE prize_id = %d AND activity_id = %d",
            $prize->id, $activity_id
        ));
        if ($win_count < $prize->max_wins) {
            $is_prizes_depleted = false;
            break;
        }
    }

    ob_start();
    ?>
    <div class="xb_lottery_container">
        <div class="xb_lottery_title"><?php echo esc_html($activity_name); ?></div>
        <div class="xb_lottery_wheel_container">
            <canvas id="xb_lottery_wheel" width="400" height="400"></canvas>
            <?php if ($user_id && $is_active && $is_within_time && !$is_prizes_depleted) : ?>
            <button id="xb_lottery_spin" class="xb_lottery_button">开始抽奖</button>
            <?php elseif ($user_id && $is_active && $is_within_time && $is_prizes_depleted) : ?>
            <div class="xb_lottery_message">奖品已经抽完，活动已结束</div>
            <?php elseif (!$user_id && $is_active && $is_within_time) : ?>
            <div class="xb_lottery_message"><?php echo esc_html($settings['guest_message']); ?></div>
            <?php endif; ?>
            <div class="xb_lottery_time_container">
                 <span>活动时间：<?php echo esc_html(date_i18n('Y-m-d H:i', $start_time)); ?> 至 <?php echo esc_html(date_i18n('Y-m-d H:i', $end_time)); ?></span>
            </div>
        </div>
        
        <div class="xb_lottery_prize_list">
            <div class="xb_lottery_subtitle">奖品列表</div>
            <div class="xb_lottery_prize_items">
                <?php
                $prizes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}xb_lottery WHERE activity_id = %d", $activity_id));
                $count = 0;
                foreach ($prizes as $prize) {
                    if ($count % 5 == 0 && $count > 0) {
                        echo '</div><div class="xb_lottery_prize_items">';
                    }
                    ?>
                    <div class="xb_lottery_prize_item">
                        <span class="xb_lottery_prize_name"><?php echo esc_html($prize->prize_name); ?></span>
                        <?php if ($prize->prize_image) : ?>
                        <img src="<?php echo esc_url($prize->prize_image); ?>" class="xb_lottery_prize_image" alt="<?php echo esc_attr($prize->prize_name); ?>">
                        <?php endif; ?>
                    </div>
                    <?php
                    $count++;
                }
                ?>
            </div>
        </div>

        <?php if (!$is_active || !$is_within_time) : ?>
        <div class="xb_lottery_message">
            <?php 
            if ($is_active) {
                echo '活动未开始或已结束';
            } else {
                echo '活动未启用';
            }
            ?>
        </div>
        <?php endif; ?>

        <div class="xb_lottery_winners">
            <div class="xb_lottery_subtitle">中奖记录</div>
            <div id="xb_lottery_winners_list">
                <?php
                $records = $wpdb->get_results($wpdb->prepare(
                    "SELECT r.*, p.prize_name, p.prize_type, p.virtual_info, u.display_name, a.activity_name 
                    FROM {$wpdb->prefix}xb_lottery_records r 
                    JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
                    JOIN {$wpdb->prefix}users u ON r.user_id = u.ID 
                    JOIN {$wpdb->prefix}xb_lottery_activities a ON r.activity_id = a.id
                    WHERE (p.prize_type != 'virtual' OR (p.prize_type = 'virtual' AND p.virtual_info != '')) AND r.activity_id = %d
                    ORDER BY r.award_time DESC LIMIT 10",
                    $activity_id
                ));
                
                foreach ($records as $record) {
                    $name = substr($record->display_name, 0, 3) . '**';
                    ?>
                    <div class="xb_lottery_winner_item">
                        <?php 
                        echo esc_html($name) . ' - ' . esc_html($record->prize_name) . 
                             ' - ' . esc_html($record->activity_name);
                        if ($record->is_physical && $record->shipping_number) {
                            echo ' - 快递单号: ' . esc_html($record->shipping_number);
                        }
                        ?>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>

        <?php if ($user_id) : ?>
        <div class="xb_lottery_user_wins">
            <div class="xb_lottery_subtitle">我的中奖记录</div>
            <div id="xb_lottery_user_wins_list">
                <?php
                $user_records = $wpdb->get_results($wpdb->prepare(
                    "SELECT r.*, p.prize_name, p.virtual_info, p.is_physical, p.prize_type, a.activity_name 
                    FROM {$wpdb->prefix}xb_lottery_records r 
                    JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
                    JOIN {$wpdb->prefix}xb_lottery_activities a ON r.activity_id = a.id
                    WHERE r.user_id = %d AND (p.prize_type != 'virtual' OR (p.prize_type = 'virtual' AND p.virtual_info != ''))", 
                    $user_id
                ));
                
                foreach ($user_records as $record) {
                    ?>
                    <div class="xb_lottery_winner_item">
                        <?php 
                        $award_time = gmdate('Y-m-d H:i:s', strtotime($record->award_time) + get_option('gmt_offset') * 3600);
                        echo esc_html($record->activity_name) . ' - ' .
                             esc_html($record->prize_name) . ' - ' . 
                             esc_html($award_time);
                        if ($record->is_physical && $record->prize_type === 'physical' && empty($record->shipping_address)) {
                            echo ' - <button class="xb_lottery_address_button" data-record-id="' . esc_attr($record->id) . '">填写收货地址</button>';
                        } elseif ($record->is_physical && $record->prize_type === 'physical' && $record->shipping_address) {
                            echo ' - 收货地址: ' . esc_html($record->shipping_address);
                            if ($record->shipping_number) {
                                echo ' - 快递单号: ' . esc_html($record->shipping_number);
                            }
                        } elseif ($record->prize_type === 'virtual' && $record->virtual_info) {
                            echo ' - 虚拟资源: ' . esc_html($record->virtual_info);
                        }
                        ?>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="xb_lottery_ad_content">
            <?php echo wp_kses_post($settings['ad_content']); ?>
        </div>

        <div id="xb_lottery_popup" class="xb_lottery_popup">
            <div class="xb_lottery_popup_content">
                <span class="xb_lottery_popup_close">×</span>
                <div id="xb_lottery_popup_message"></div>
                <div id="xb_lottery_popup_image" style="display:none;">
                    <img src="" alt="奖品图片" class="xb_lottery_prize_image">
                </div>
                <div id="xb_lottery_popup_virtual" style="display:none;"></div>
                <div id="xb_lottery_popup_no_prize" style="display:none;">谢谢参与</div>
                <div id="xb_lottery_address_form" style="display:none;">
                    <input type="text" id="xb_lottery_address" placeholder="请输入收货地址">
                    <input type="hidden" id="xb_lottery_record_id">
                    <button id="xb_lottery_submit_address">提交</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('xb_lottery', 'xb_lottery_shortcode');

// 后台菜单
function xb_lottery_admin_menu() {
    add_menu_page(
        '抽奖设置',
        '抽奖设置',
        'manage_options',
        'xb_lottery_settings',
        'xb_lottery_admin_page',
        'dashicons-star-filled',
        80
    );
}
add_action('admin_menu', 'xb_lottery_admin_menu');

// 后台页面
function xb_lottery_admin_page() {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        wp_die('无权限访问');
    }

    // 初始化消息变量，确保每次操作都有独立的提示
    $message = '';

    // 处理清除所有数据
    if (isset($_GET['clear_all_data']) && check_admin_referer('clear_all_data')) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}xb_lottery");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}xb_lottery_records");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}xb_lottery_activities");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}xb_lottery_attempts");
        
        // 创建默认活动
        $wpdb->insert(
            $wpdb->prefix . 'xb_lottery_activities',
            array(
                'activity_name' => '默认活动',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s')
        );
        
        $message = '<div class="notice notice-success is-dismissible xb-lottery-notice"><p>所有数据已清除</p></div>';
    }
    // 处理清除用户参与记录
    elseif (isset($_GET['clear_user_records']) && check_admin_referer('clear_user_records')) {
        $activity_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
        
        // 清除用户抽奖次数记录
        $wpdb->delete(
            $wpdb->prefix . 'xb_lottery_attempts',
            array('activity_id' => $activity_id),
            array('%d')
        );

        // 清除用户中奖记录
        $wpdb->delete(
            $wpdb->prefix . 'xb_lottery_records',
            array('activity_id' => $activity_id),
            array('%d')
        );
        
        $message = '<div class="notice notice-success is-dismissible xb-lottery10n-lottery-notice"><p>用户记录已清除</p></div>';
    }
    // 处理保存设置
    elseif (isset($_POST['xb_lottery_settings_nonce']) && wp_verify_nonce($_POST['xb_lottery_settings_nonce'], 'xb_lottery_settings')) {
        $settings = array(
            'max_attempts' => absint($_POST['max_attempts']),
            'guest_message' => sanitize_text_field($_POST['guest_message']),
            'notification_type' => sanitize_text_field($_POST['notification_type']),
            'max_wins' => absint($_POST['max_wins']),
            'start_time' => sanitize_text_field($_POST['start_time']),
            'end_time' => sanitize_text_field($_POST['end_time']),
            'ad_content' => wp_kses_post($_POST['ad_content']),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        );
        update_option('xb_lottery_settings', $settings);

        // 更新活动名称到数据库
        $activity_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
        if ($activity_id && !empty($_POST['activity_name'])) {
            $wpdb->update(
                $wpdb->prefix . 'xb_lottery_activities',
                array('activity_name' => sanitize_text_field($_POST['activity_name'])),
                array('id' => $activity_id),
                array('%s'),
                array('%d')
            );
        }
        
        $message = '<div class="notice notice-success is-dismissible xb-lottery-notice"><p>设置已保存</p></div>';
    }
    // 处理保存奖品
    elseif (isset($_POST['xb_lottery_prizes_nonce']) && wp_verify_nonce($_POST['xb_lottery_prizes_nonce'], 'xb_lottery_prizes')) {
        $total_probability = 0;
        $activity_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");

        if (!empty($_POST['prizes']) && is_array($_POST['prizes'])) {
            foreach ($_POST['prizes'] as $index => $prize) {
                if (empty($prize['name']) || !isset($prize['probability'])) {
                    continue;
                }
                $probability = floatval($prize['probability']);
                $total_probability += $probability;
                
                if ($total_probability > 100) {
                    $message = '<div class="notice notice-error is-dismissible xb-lottery-notice"><p>总中奖率不能超过100%</p></div>';
                    break;
                }
                
                // 更新或插入奖品
                if (is_numeric($index)) {
                    // 更新现有奖品
                    $wpdb->update(
                        $wpdb->prefix . 'xb_lottery',
                        array(
                            'prize_name' => sanitize_text_field($prize['name']),
                            'prize_type' => sanitize_text_field($prize['type']),
                            'probability' => $probability,
                            'is_physical' => isset($prize['is_physical']) ? 1 : 0,
                            'prize_image' => isset($prize['image']) ? esc_url_raw($prize['image']) : '',
                            'virtual_info' => isset($prize['virtual_info']) ? sanitize_text_field($prize['virtual_info']) : '',
                            'max_wins' => absint($prize['max_wins'])
                        ),
                        array('id' => $index, 'activity_id' => $activity_id),
                        array('%s', '%s', '%f', '%d', '%s', '%s', '%d'),
                        array('%d', '%d')
                    );
                } else {
                    // 插入新奖品
                    $wpdb->insert(
                        $wpdb->prefix . 'xb_lottery',
                        array(
                            'activity_id' => $activity_id,
                            'prize_name' => sanitize_text_field($prize['name']),
                            'prize_type' => sanitize_text_field($prize['type']),
                            'probability' => $probability,
                            'is_physical' => isset($prize['is_physical']) ? 1 : 0,
                            'prize_image' => isset($prize['image']) ? esc_url_raw($prize['image']) : '',
                            'virtual_info' => isset($prize['virtual_info']) ? sanitize_text_field($prize['virtual_info']) : '',
                            'max_wins' => absint($prize['max_wins'])
                        ),
                        array('%d', '%s', '%s', '%f', '%d', '%s', '%s', '%d')
                    );
                }
            }
            if ($total_probability <= 100 && empty($message)) {
                $message = '<div class="notice notice-success is-dismissible xb-lottery-notice"><p>奖品已保存</p></div>';
            }
        }
    }
    // 处理删除奖品
    elseif (isset($_GET['delete_prize']) && check_admin_referer('delete_prize')) {
        $prize_id = absint($_GET['delete_prize']);
        $wpdb->delete(
            $wpdb->prefix . 'xb_lottery',
            array('id' => $prize_id),
            array('%d')
        );
        $message = '<div class="notice notice-success is-dismissible xb-lottery-notice"><p>奖品已删除</p></div>';
    }
    // 处理删除记录
    elseif (isset($_GET['delete_record']) && check_admin_referer('delete_record')) {
        $wpdb->delete(
            $wpdb->prefix . 'xb_lottery_records',
            array('id' => absint($_GET['delete_record'])),
            array('%d')
        );
        $message = '<div class="notice notice-success is-dismissible xb-lottery-notice"><p>记录已删除</p></div>';
    }

    $settings = get_option('xb_lottery_settings', array(
        'max_attempts' => 2,
        'guest_message' => '请先登录参与抽奖',
        'notification_type' => 'popup',
        'max_wins' => 1,
        'start_time' => current_time('mysql'),
        'end_time' => date('Y-m-d H:i:s', strtotime('+1 month')),
        'ad_content' => '',
        'is_active' => 0
    ));

    $current_activity = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
    $activity_id = $current_activity ? $current_activity->id : 0;
    $settings['activity_name'] = $current_activity ? $current_activity->activity_name : '抽奖活动';
    ?>
    <div class="wrap">
        <style>
            .xb-lottery-notice {
                opacity: 1;
                transition: opacity 0.5s ease-out;
            }
            .xb-lottery-notice.fade-out {
                opacity: 0;
            }
            .xb-lottery-new-prize input[type="text"],
            .xb-lottery-new-prize input[type="number"],
            .xb-lottery-new-prize select,
            .xb-lottery-new-prize textarea {
                width: 100%;
                max-width: 200px;
            }
            .xb-lottery-new-prize input[type="checkbox"] {
                vertical-align: middle;
            }
            .xb-lottery-remove-prize {
                background: #d63638;
                color: white;
                border: none;
                padding: 5px 10px;
                cursor: pointer;
            }
            .xb-lottery-remove-prize:hover {
                background: #b32d2e;
            }
            .xb-lottery-prize-image {
                width: 100px;
                height: 100px;
                object-fit: contain;
                margin-top: 5px;
            }
            .xb-lottery-shipping-numberonie {
                width: 100%;
                max-width: 200px;
                padding: 5px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .xb-lottery-clear-all {
                background: #d63638;
                color: white;
                border: none;
                padding: 8px 16px;
                margin: 10px 0;
                cursor: pointer;
            }
            .xb-lottery-clear-all:hover {
                background: #b32d2e;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // 隐藏通知
                function hideNotice() {
                    $('.xb-lottery-notice').addClass('fade-out');
                    setTimeout(function() {
                        $('.xb-lottery-notice').remove();
                    }, 500);
                }
                
                if ($('.xb-lottery-notice').length) {
                    setTimeout(hideNotice, 3000);
                }

                // 添加奖品行
                $('#xb_lottery_add_prize').click(function() {
                    var uniqueId = Date.now() + Math.random().toString(36).substr(2, 9);
                    var newRow = '<tr class="xb-lottery-new-prize">' +
                        '<td><input type="text" name="prizes[' + uniqueId + '][name]" placeholder="奖品名称" required></td>' +
                        '<td><select name="prizes[' + uniqueId + '][type]">' +
                            '<option value="physical">实物</option>' +
                            '<option value="virtual">虚拟</option>' +
                        '</select></td>' +
                        '<td><input type="number" name="prizes[' + uniqueId + '][probability]" placeholder="中奖率" step="0.01" min="0" max="100" required></td>' +
                        '<td><input type="checkbox" name="prizes[' + uniqueId + '][is_physical]" value="1"> 实物</td>' +
                        '<td><input type="text" name="prizes[' + uniqueId + '][image]" placeholder="奖品图片URL"></td>' +
                        '<td><textarea name="prizes[' + uniqueId + '][virtual_info]" placeholder="虚拟资源信息"></textarea></td>' +
                        '<td><input type="number" name="prizes[' + uniqueId + '][max_wins]" placeholder="最大中奖次数" min="0"></td>' +
                        '<td></td>' +
                        '<td><button type="button" class="xb-lottery-remove-prize">移除</button></td>' +
                    '</tr>';
                    $('#xb_lottery_prize_table tbody').append(newRow);
                });

                // 移除奖品行
                $(document).on('click', '.xb-lottery-remove-prize', function() {
                    $(this).closest('tr').remove();
                });

                // 清除所有数据确认
                $('.xb-lottery-clear-all').click(function(e) {
                    if (!confirm('确定要清除所有抽奖数据吗？此操作不可恢复！')) {
                        e.preventDefault();
                    }
                });

                // 更新发货状态和快递单号
                $('.xb_lottery_shipping_status').on('change', function() {
                    var record_id = $(this).data('record-id');
                    var status = $(this).val();
                    var shipping_number = $(this).closest('tr').find('.xb-lottery-shipping-number').val();

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        method: 'POST',
                        data: {
                            action: 'xb_lottery_update_shipping',
                            nonce: '<?php echo wp_create_nonce('xb_lottery_nonce'); ?>',
                            record_id: record_id,
                            status: status,
                            shipping_number: shipping_number
                        },
                        success: function(response) {
                            if (response.success) {
                                var $notice = $('<div class="notice notice-success is-dismissible xb-lottery-notice"><p>' + response.data.message + '</p></div>');
                                $('.wrap').prepend($notice);
                                setTimeout(hideNotice, 3000);
                            } else {
                                var $notice = $('<div class="notice notice-error is-dismissible xb-lottery-notice"><p>状态更新失败</p></div>');
                                $('.wrap').prepend($notice);
                                setTimeout(hideNotice, 3000);
                            }
                        },
                        error: function() {
                            var $notice = $('<div class="notice notice-error is-dismissible xb-lottery-notice"><p>状态更新失败，请稍后重试</p></div>');
                            $('.wrap').prepend($notice);
                            setTimeout(hideNotice, 3000);
                        }
                    });
                });
            });
        </script>
        <h1>抽奖设置</h1>
        <?php if (!empty($message)) echo $message; ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('xb_lottery_settings', 'xb_lottery_settings_nonce'); ?>
            <h2>基本设置</h2>
            <table class="form-table">
                <tr>
                    <th><label for="activity_name">活动名称</label></th>
                    <td><input type="text" id="activity_name" name="activity_name" value="<?php echo esc_attr($settings['activity_name']); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="max_attempts">每人抽奖次数</label></th>
                    <td><input type="number" id="max_attempts" name="max_attempts" value="<?php echo esc_attr($settings['max_attempts']); ?>" min="1" required></td>
                </tr>
                <tr>
                    <th><label for="guest_message">游客提示</label></th>
                    <td><input type="text" id="guest_message" name="guest_message" value="<?php echo esc_attr($settings['guest_message']); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="notification_type">中奖提示方式</label></th>
                    <td>
                        <select id="notification_type" name="notification_type">
                            <option value="popup" <?php selected($settings['notification_type'], 'popup'); ?>>弹窗</option>
                            <option value="email" <?php selected($settings['notification_type'], 'email'); ?>>邮件</option>
                            <option value="both" <?php selected($settings['notification_type'], 'both'); ?>>两者皆有</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="max_wins">每人最多中奖次数</label></th>
                    <td><input type="number" id="max_wins" name="max_wins" value="<?php echo esc_attr($settings['max_wins']); ?>" min="1" required></td>
                </tr>
                <tr>
                    <th><label for="start_time">活动开始时间</label></th>
                    <td><input type="datetime-local" id="start_time" name="start_time" value="<?php echo esc_attr($settings['start_time']); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="end_time">活动结束时间</label></th>
                    <td><input type="datetime-local" id="end_time" name="end_time" value="<?php echo esc_attr($settings['end_time']); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="is_active">启用活动</label></th>
                    <td><input type="checkbox" id="is_active" name="is_active" value="1" <?php checked($settings['is_active'], 1); ?>></td>
                </tr>
                <tr>
                    <th><label for="ad_content">广告内容</label></th>
                    <td><textarea id="ad_content" name="ad_content" rows="5" class="large-text"><?php echo esc_textarea($settings['ad_content']); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button('保存设置'); ?>
        </form>

        <h2>奖品设置</h2>
        <form method="post" action="">
            <?php wp_nonce_field('xb_lottery_prizes', 'xb_lottery_prizes_nonce'); ?>
            <table id="xb_lottery_prize_table" class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>奖品名称</th>
                        <th>奖品类型</th>
                        <th>中奖率(%)</th>
                        <th>实物奖品</th>
                        <th>奖品图片</th>
                        <th>虚拟资源信息</th>
                        <th>最大中奖次数</th>
                        <th>剩余中奖次数</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $prizes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}xb_lottery WHERE activity_id = %d", $activity_id));
                    foreach ($prizes as $prize) {
                        $remaining_wins = $prize->max_wins > 0 ? $prize->max_wins - $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}xb_lottery_records WHERE prize_id = %d AND activity_id = %d",
                            $prize->id, $activity_id
                        )) : '不限';
                        ?>
                        <tr>
                            <td><input type="text" name="prizes[<?php echo $prize->id; ?>][name]" value="<?php echo esc_attr($prize->prize_name); ?>" required></td>
                            <td>
                                <select name="prizes[<?php echo $prize->id; ?>][type]">
                                    <option value="physical" <?php selected($prize->prize_type, 'physical'); ?>>实物</option>
                                    <option value="virtual" <?php selected($prize->prize_type, 'virtual'); ?>>虚拟</option>
                                </select>
                            </td>
                            <td><input type="number" name="prizes[<?php echo $prize->id; ?>][probability]" value="<?php echo esc_attr($prize->probability); ?>" step="0.01" min="0" max="100" required></td>
                            <td>
                                <input type="checkbox" name="prizes[<?php echo $prize->id; ?>][is_physical]" value="1" <?php checked($prize->is_physical, 1); ?>>
                            </td>
                            <td>
                                <input type="text" name="prizes[<?php echo $prize->id; ?>][image]" value="<?php echo esc_attr($prize->prize_image); ?>" placeholder="奖品图片URL">
                                <?php if ($prize->prize_image) : ?>
                                <img src="<?php echo esc_url($prize->prize_image); ?>" class="xb-lottery-prize-image">
                                <?php endif; ?>
                            </td>
                            <td>
                                <textarea name="prizes[<?php echo $prize->id; ?>][virtual_info]" placeholder="虚拟资源信息"><?php echo esc_textarea($prize->virtual_info); ?></textarea>
                            </td>
                            <td>
                                <input type="number" name="prizes[<?php echo $prize->id; ?>][max_wins]" value="<?php echo esc_attr($prize->max_wins); ?>" min="0">
                            </td>
                            <td><?php echo esc_html($remaining_wins); ?></td>
                            <td>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=xb_lottery_settings&delete_prize=' . $prize->id), 'delete_prize'); ?>">删除</a>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
            <button type="button" id="xb_lottery_add_prize" class="button">添加奖品</button>
            <?php submit_button('保存奖品'); ?>
        </form>

        <h2>中奖记录</h2>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=xb_lottery_settings&clear_user_records=1'), 'clear_user_records'); ?>" class="button button-secondary">清除用户参与记录</a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=xb_lottery_settings&clear_all_data=1'), 'clear_all_data'); ?>" class="xb-lottery-clear-all">清除所有数据</a>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>活动名称</th>
                    <th>用户</th>
                    <th>奖品</th>
                    <th>中奖时间</th>
                    <th>收货地址</th>
                    <th>虚拟资源信息</th>
                    <th>发货状态</th>
                    <th>快递单号</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $records = $wpdb->get_results("SELECT r.*, p.prize_name, p.virtual_info, p.is_physical, p.prize_type, u.display_name, a.activity_name 
                    FROM {$wpdb->prefix}xb_lottery_records r 
                    JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
                    JOIN {$wpdb->prefix}users u ON r.user_id = u.ID 
                    JOIN {$wpdb->prefix}xb_lottery_activities a ON r.activity_id = a.id
                    WHERE p.prize_type != 'virtual' OR (p.prize_type = 'virtual' AND p.virtual_info != '')");
                
                foreach ($records as $record) {
                    ?>
                    <tr>
                        <td><?php echo esc_html($record->activity_name); ?></td>
                        <td><?php echo esc_html($record->display_name); ?></td>
                        <td><?php echo esc_html($record->prize_name); ?></td>
                        <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($record->award_time))); ?></td>
                        <td><?php echo $record->is_physical ? esc_html($record->shipping_address) : '不适用'; ?></td>
                        <td><?php echo $record->virtual_info ? esc_html($record->virtual_info) : '无'; ?></td>
                        <td>
                            <?php if ($record->is_physical) : ?>
                            <select class="xb_lottery_shipping_status" data-record-id="<?php echo $record->id; ?>">
                                <option value="pending" <?php selected($record->shipping_status, 'pending'); ?>>待处理</option>
                                <option value="shipped" <?php selected($record->shipping_status, 'shipped'); ?>>已发货</option>
                            </select>
                            <?php else : ?>
                            不适用
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($record->is_physical) : ?>
                            <input type="text" class="xb-lottery-shipping-number" value="<?php echo esc_attr($record->shipping_number); ?>" placeholder="请输入快递单号">
                            <?php else : ?>
                            不适用
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=xb_lottery_settings&delete_record=' . $record->id), 'delete_record'); ?>">删除</a></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

// AJAX 处理抽奖
function xb_lottery_spin() {
    check_ajax_referer('xb_lottery_nonce', 'nonce');
    
    global $wpdb;
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        wp_send_json_error(array(
            'message' => get_option('xb_lottery_settings')['guest_message'],
            'no_spin' => true
        ));
    }

    $settings = get_option('xb_lottery_settings');
    $activity_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
    $activity_name = $wpdb->get_var($wpdb->prepare("SELECT activity_name FROM {$wpdb->prefix}xb_lottery_activities WHERE id = %d", $activity_id));
    
    // 检查活动是否启用和时间
    $now = current_time('timestamp');
    $start_time = strtotime($settings['start_time']);
    $end_time = strtotime($settings['end_time']);
    if (!$settings['is_active'] || $now < $start_time || $now > $end_time) {
        wp_send_json_error(array(
            'message' => '活动未启用或不在活动时间内',
            'no_spin' => true
        ));
    }

    // 检查用户抽奖次数
    $attempt_count = $wpdb->get_var($wpdb->prepare(
        "SELECT attempt_count FROM {$wpdb->prefix}xb_lottery_attempts 
         WHERE user_id = %d AND activity_id = %d", 
        $user_id, $activity_id
    ));
    
    if ($attempt_count >= $settings['max_attempts']) {
        wp_send_json_error(array(
            'message' => '您的抽奖次数已用完',
            'no_spin' => true
        ));
    }

    // 检查用户中奖次数（仅统计有效中奖记录）
    $wins = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}xb_lottery_records r 
         JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
         WHERE r.user_id = %d AND r.activity_id = %d 
         AND (p.prize_type != 'virtual' OR (p.prize_type = 'virtual' AND p.virtual_info != ''))", 
        $user_id, $activity_id
    ));
    
    if ($wins >= $settings['max_wins']) {
        wp_send_json_error(array(
            'message' => '您已达到最大中奖次数',
            'no_spin' => true
        ));
    }

    // 检查奖品是否抽完
    $prizes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}xb_lottery WHERE activity_id = %d AND max_wins > 0", $activity_id));
    $is_prizes_depleted = true;
    foreach ($prizes as $prize) {
        $win_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}xb_lottery_records WHERE prize_id = %d AND activity_id = %d",
            $prize->id, $activity_id
        ));
        if ($win_count < $prize->max_wins) {
            $is_prizes_depleted = false;
            break;
        }
    }
    
    if ($is_prizes_depleted) {
        wp_send_json_error(array(
            'message' => '奖品已经抽完，活动已结束',
            'no_spin' => true
        ));
    }

    // 获取奖品并检查最大中奖次数
    $prizes = $wpdb->get_results($wpdb->prepare("SELECT p.*, 
        (SELECT COUNT(*) FROM {$wpdb->prefix}xb_lottery_records r WHERE r.prize_id = p.id AND r.activity_id = %d) as win_count 
        FROM {$wpdb->prefix}xb_lottery p WHERE p.activity_id = %d", $activity_id, $activity_id));
    
    $available_prizes = array_filter($prizes, function($prize) {
        return $prize->max_wins == 0 || $prize->win_count < $prize->max_wins;
    });
    
    if (empty($available_prizes)) {
        wp_send_json_error(array(
            'message' => '没有可用的奖品',
            'no_spin' => true
        ));
    }

    $rand = mt_rand(0, 10000) / 100;
    $current = 0;
    $selected_prize = null;
    
    foreach ($available_prizes as $prize) {
        $current += $prize->probability;
        if ($rand <= $current) {
            $selected_prize = $prize;
            break;
        }
    }

    // 更新抽奖次数
    $award_time = current_time('mysql');
    $wpdb->replace(
        $wpdb->prefix . 'xb_lottery_attempts',
        array(
            'activity_id' => $activity_id,
            'user_id' => $user_id,
            'attempt_count' => $attempt_count + 1,
            'last_attempt' => $award_time
        ),
        array('%d', '%d', '%d', '%s')
    );

    // 记录中奖信息（仅记录有效中奖）
    if ($selected_prize && ($selected_prize->prize_type !== 'virtual' || ($selected_prize->prize_type === 'virtual' && $selected_prize->virtual_info))) {
        $wpdb->insert(
            $wpdb->prefix . 'xb_lottery_records',
            array(
                'activity_id' => $activity_id,
                'user_id' => $user_id,
                'prize_id' => $selected_prize->id,
                'award_time' => $award_time,
                'virtual_info' => $selected_prize->virtual_info
            ),
            array('%d', '%d', '%d', '%s', '%s')
        );
        $record_id = $wpdb->insert_id;
    } else {
        $record_id = 0;
    }

    // 计算旋转角度
    $total_prob = array_sum(array_map(function($prize) { return $prize->probability; }, $prizes));
    $angles = [];
    $current_angle = 0;
    
    foreach ($prizes as $index => $prize) {
        $angles[$prize->id] = [
            'start' => $current_angle,
            'end' => $current_angle + ($prize->probability / 100 * 360)
        ];
        $current_angle += $prize->probability / 100 * 360;
    }

    $target_angle = 0;
    if ($selected_prize) {
        $angle_range = $angles[$selected_prize->id];
        $target_angle = $angle_range['start'] + ($angle_range['end'] - $angle_range['start']) / 2;
    } else {
        wp_send_json_error(array(
            'message' => '谢谢参与',
            'no_spin' => false,
            'prize_name' => '谢谢参与',
            'is_physical' => false,
            'prize_image' => '',
            'virtual_info' => '',
            'prize_type' => '',
            'activity_name' => $activity_name,
            'record_id' => $record_id,
            'award_time' => $award_time,
            'target_angle' => $current_angle + 720 // 多转两圈
        ));
    }

    // 发送邮件通知
    if ($selected_prize && ($settings['notification_type'] === 'email' || $settings['notification_type'] === 'both')) {
        $user = get_userdata($user_id);
        $site_title = get_bloginfo('name');
        $site_url = get_site_url();
        $lottery_page = get_permalink(get_page_by_path('抽奖活动'));
        $subject = '【' . $site_title . '】活动获奖通知 - ' . $activity_name;
        $message = "尊敬的 " . $user->display_name . "，\n\n";
        $message .= "感谢您参与 " . $site_title . " 举办的《" . $activity_name . "》抽奖活动！\n\n";
        $message .= "恭喜您获得：" . $selected_prize->prize_name . "\n\n";
        if ($selected_prize->is_physical) {
            $message .= "请访问以下链接填写您的收货地址以便我们为您寄送奖品：\n" . $lottery_page . "\n\n";
        } elseif ($selected_prize->virtual_info) {
            $message .= "虚拟资源信息: " . $selected_prize->virtual_info . "\n\n";
        }
        $message .= "活动详情请访问：\n" . $lottery_page . "\n\n";
        $message .= "如有任何疑问，请通过 " . get_option('admin_email') . " 联系我们。\n\n";
        $message .= "祝您好运！\n" . $site_title . " 团队";
        wp_mail($user->user_email, $subject, $message);
    }

    wp_send_json_success(array(
        'prize_name' => $selected_prize->prize_name,
        'is_physical' => $selected_prize->is_physical,
        'prize_image' => $selected_prize->prize_image,
        'virtual_info' => $selected_prize->virtual_info,
        'prize_type' => $selected_prize->prize_type,
        'activity_name' => $activity_name,
        'record_id' => $record_id,
        'award_time' => $award_time,
        'target_angle' => $target_angle + 720 // 多转两圈
    ));
}
add_action('wp_ajax_xb_lottery_spin', 'xb_lottery_spin');

// AJAX 处理地址提交
function xb_lottery_submit_address() {
    check_ajax_referer('xb_lottery_nonce', 'nonce');
    
    global $wpdb;
    $user_id = get_current_user_id();
    $address = sanitize_text_field($_POST['address']);
    $record_id = absint($_POST['record_id']);
    
    // 验证记录是否有效且为实物奖品
    $valid_record = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) 
         FROM {$wpdb->prefix}xb_lottery_records r 
         JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
         WHERE r.id = %d AND r.user_id = %d AND p.is_physical = 1 AND p.prize_type = 'physical'",
        $record_id, $user_id
    ));

    if ($valid_record) {
        $wpdb->update(
            $wpdb->prefix . 'xb_lottery_records',
            array('shipping_address' => $address, 'shipping_status' => 'pending'),
            array('id' => $record_id, 'user_id' => $user_id),
            array('%s', '%s'),
            array('%d', '%d')
        );
        wp_send_json_success(array('message' => '地址提交成功'));
    } else {
        wp_send_json_error(array('message' => '无效的记录或非实物奖品'));
    }
}
add_action('wp_ajax_xb_lottery_submit_address', 'xb_lottery_submit_address');

// AJAX 更新发货状态和快递单号
function xb_lottery_update_shipping() {
    check_ajax_referer('xb_lottery_nonce', 'nonce');
    
    global $wpdb;
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '无权限'));
    }

    $record_id = absint($_POST['record_id']);
    $status = sanitize_text_field($_POST['status']);
    $shipping_number = sanitize_text_field($_POST['shipping_number']);
    
    // 验证是否为实物奖品
    $is_physical = $wpdb->get_var($wpdb->prepare(
        "SELECT p.is_physical 
         FROM {$wpdb->prefix}xb_lottery_records r 
         JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
         WHERE r.id = %d",
        $record_id
    ));

    if (!$is_physical) {
        wp_send_json_error(array('message' => '非实物奖品无需更新发货状态'));
    }

    if ($status === 'shipped' && empty($shipping_number)) {
        wp_send_json_error(array('message' => '已发货状态必须填写快递单号'));
    }

    $wpdb->update(
        $wpdb->prefix . 'xb_lottery_records',
        array(
            'shipping_status' => $status,
            'shipping_number' => $shipping_number
        ),
        array('id' => $record_id),
        array('%s', '%s'),
        array('%d')
    );
    
    wp_send_json_success(array('message' => '状态更新成功'));
}
add_action('wp_ajax_xb_lottery_update_shipping', 'xb_lottery_update_shipping');

// AJAX 获取奖品列表
function xb_lottery_get_prizes() {
    global $wpdb;
    $activity_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
    $prizes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}xb_lottery WHERE activity_id = %d", $activity_id));
    
    wp_send_json_success($prizes);
}
add_action('wp_ajax_xb_lottery_get_prizes', 'xb_lottery_get_prizes');
add_action('wp_ajax_nopriv_xb_lottery_get_prizes', 'xb_lottery_get_prizes');

// AJAX 获取最新中奖记录
function xb_lottery_get_latest_records() {
    check_ajax_referer('xb_lottery_nonce', 'nonce');
    
    global $wpdb;
    $user_id = get_current_user_id();
    $activity_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}xb_lottery_activities ORDER BY created_at DESC LIMIT 1");
    
    // 获取公共中奖记录
    $records = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, p.prize_name, p.prize_type, p.virtual_info, u.display_name, a.activity_name 
        FROM {$wpdb->prefix}xb_lottery_records r 
        JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
        JOIN {$wpdb->prefix}users u ON r.user_id = u.ID 
        JOIN {$wpdb->prefix}xb_lottery_activities a ON r.activity_id = a.id
        WHERE (p.prize_type != 'virtual' OR (p.prize_type = 'virtual' AND p.virtual_info != '')) AND r.activity_id = %d
        ORDER BY r.award_time DESC LIMIT 10",
        $activity_id
    ));

    // 获取用户个人中奖记录
    $user_records = $user_id ? $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, p.prize_name, p.virtual_info, p.is_physical, p.prize_type, a.activity_name 
        FROM {$wpdb->prefix}xb_lottery_records r 
        JOIN {$wpdb->prefix}xb_lottery p ON r.prize_id = p.id 
        JOIN {$wpdb->prefix}xb_lottery_activities a ON r.activity_id = a.id
        WHERE r.user_id = %d AND (p.prizetype != 'virtual' OR (p.prize_type = 'virtual' AND p.virtual_info != ''))",
        $user_id
    )) : array();

    wp_send_json_success(array(
        'records' => $records,
        'user_records' => $user_records
    ));
}
add_action('wp_ajax_xb_lottery_get_latest_records', 'xb_lottery_get_latest_records');
add_action('wp_ajax_nopriv_xb_lottery_get_latest_records', 'xb_lottery_get_latest_records');

// 插件激活钩子
register_activation_hook(__FILE__, 'xb_lottery_create_tables');
register_activation_hook(__FILE__, 'xb_lottery_create_page');
?>
