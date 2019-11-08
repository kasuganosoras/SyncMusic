<?php
/**
 *    __________    ____                   __  __           _      
 *   |  _____   |  / ___| _   _ _ __   ___|  \/  |_   _ ___(_) ___ 
 *   |  |    |  |  \___ \| | | | '_ \ / __| |\/| | | | / __| |/ __|
 *   |  |    |  |   ___) | |_| | | | | (__| |  | | |_| \__ \ | (__ 
 *  /   |   /   |  |____/ \__, |_| |_|\___|_|  |_|\__,_|___/_|\___|
 * |___/   |___/          |___/                                    
 *
 * 此项目使用 GPL v3 协议开放源代码，您可以在遵守本协议的前提下自由修改使用
 *
 * 作者：Akkariin Meiko | QQ：204034 | Telegram：Akkariins
 *
 **/

require('SyncMusic.php');

/**
 *
 *  服务器配置，每一项都有说明，请根据自己的需要调整
 *
 */

// 工作目录，默认是当前路径，一般不需要修改
define("ROOT", __DIR__);

// WebSocket 服务器监听地址，默认 0.0.0.0 无需修改
define("BIND_HOST", "0.0.0.0");

// WebSocket 服务器监听端口，默认 811
define("BIND_PORT", 811);

// 房管密码
define("ADMIN_PASS", "123456789");

// 执行任务的 Workers 数量，不要太小也不要太大，一般 32 左右
define("WORKERNUM", 32);

// 是否启用调试模式，可以输出详细信息
define("DEBUG", false);

// 是否使用 X-Real-IP 来获取客户端 IP，适用于 Nginx 反代后的 WebSocket
define("USE_X_REAL_IP", true);

// 是否使用 Redis 来储存歌单数据
define("USE_REDIS", true);

// Redis 地址
define("REDIS_HOST", "127.0.0.1");

// Redis 端口
define("REDIS_PORT", "6379");

// Redis 密码（留空禁用）
define("REDIS_PASS", "");

// 音乐信息获取 API，默认是 ZeroDream 的 API
// 可自行搭建，参考：https://github.com/mengkunsoft/MKOnlineMusicPlayer
define("MUSIC_API", "https://cdn.zerodream.net/netease");

// Python3 可执行文件位置
define("PYTHON_EXEC", "/usr/bin/python3");

// 客户端聊天冷却时间，单位秒
define("MIN_CHATWAIT", 3);

// 聊天内容的最大长度
define("MAX_CHATLENGTH", 200);

// 音乐的最大长度，单位秒，超过不能点
define("MAX_MUSICLENGTH", 300);

// 每个用户最多可以点多少首歌
define("MAX_USERMUSIC", 5);

/**
 *
 *  开始运行服务器，请勿修改
 *
 */
$syncMusic = new SyncMusic(BIND_HOST, BIND_PORT, ADMIN_PASS, WORKERNUM, DEBUG, USE_X_REAL_IP, MUSIC_API);
$syncMusic->init();
$syncMusic->run();
