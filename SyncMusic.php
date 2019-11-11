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
class SyncMusic {
	
	private $server;
	private $bindHost;
	private $bindPort;
	private $adminPass;
	private $workersNum;
	private $debug;
	private $getIpMethod;
	private $musicApi;
	
	/**
	 *
	 *  Construct 定义服务器基础信息
	 *
	 */
	public function __construct($bindHost, $bindPort, $adminPass, $workersNum, $debug, $getIpMethod, $musicApi)
	{
		$this->bindHost    = $bindHost;
		$this->bindPort    = $bindPort;
		$this->adminPass   = $adminPass;
		$this->workersNum  = $workersNum;
		$this->debug       = $debug;
		$this->getIpMethod = $getIpMethod;
		$this->musicApi    = $musicApi;
	}
	
	/**
	 *
	 *  checkDataFolder 检查并创建数据目录
	 *
	 */
	public function checkDataFolder()
	{
		if(!file_exists(ROOT . "/tmp/")) mkdir(ROOT . "/tmp/");
		if(!file_exists(ROOT . "/random.txt")) {
			$data = @file_get_contents("https://cdn.zerodream.net/download/music/random.txt");
			@file_put_contents(ROOT . "/random.txt", $data);
		}
	}
	
	/**
	 *
	 *  Init 初始化并配置服务器
	 *
	 */
	public function init()
	{
		$this->checkDataFolder();
		
		$this->server = new Swoole\WebSocket\Server($this->bindHost, $this->bindPort);
		$this->server->set([
			'task_worker_num' => $this->workersNum,
			'worker_num'      => 16,
		]);

		// Table 表，用于储存服务器的信息
		$table = new Swoole\Table(1024);
		$table->column('music_time', swoole_table::TYPE_FLOAT, 8);
		$table->column('music_play', swoole_table::TYPE_FLOAT, 8);
		$table->column('music_long', swoole_table::TYPE_FLOAT, 8);
		$table->column('downloaded', swoole_table::TYPE_FLOAT, 8);
		$table->column('needswitch', swoole_table::TYPE_STRING, 32768);
		$table->column('music_list', swoole_table::TYPE_STRING, 32768);
		$table->column('music_show', swoole_table::TYPE_STRING, 32768);
		$table->column('banned_ips', swoole_table::TYPE_STRING, 32768);
		$table->create();

		// Chats 表，用于储存用户的信息
		$chats = new Swoole\Table(1024);
		$chats->column('ip', swoole_table::TYPE_STRING, 256);
		$chats->column('last', swoole_table::TYPE_FLOAT, 8);
		$chats->create();

		// 初始化信息
		$this->server->table = $table;
		$this->server->chats = $chats;
		$this->server->started = false;
		$this->server->randomed = false;
		$this->server->adding = false;
		
		/**
		 *
		 *  Open Event 当客户端与服务器建立连接时触发此事件
		 *
		 */
		$this->server->on('open', function (Swoole\WebSocket\Server $server, $request) {
			
			// 当第一个客户端连接到服务器的时候就触发 Task 去处理事件
			if(!$server->started) {
				$server->task(["action" => "Start"]);
				$server->started = true;
			}
			
			// 获取客户端的 IP 地址
			if($this->getIpMethod) {
				$clientIp = $request->header['x-real-ip'] ?? "127.0.0.1";
			} else {
				$clientIp = $server->getClientInfo($request->fd)['remote_ip'] ?? "127.0.0.1";
			}
			
			// 将客户端 IP 储存到表中
			$server->chats->set($request->fd, ["ip" => $clientIp]);
			
			$this->consoleLog("客户端 {$request->fd} [{$clientIp}] 已连接到服务器", 1, true);
			
			$server->push($request->fd, json_encode([
				"type" => "msg",
				"data" => "你已经成功连接到服务器！"
			]));
			
			$musicPlay = $this->getMusicPlay();
			$musicList = $this->getMusicShow();
			
			// 如果当前列表中有音乐可播放
			if($musicList && !empty($musicList)) {
				
				// 获取音乐的信息和歌词
				$musicInfo = $musicList[0];
				$lrcs      = $this->getMusicLrcs($musicInfo['id']);
				
				// 推送给客户端
				$server->push($request->fd, json_encode([
					"type"    => "music",
					"id"      => $musicInfo['id'],
					"name"    => $musicInfo['name'],
					"file"    => $this->getMusicUrl($musicInfo['id']),
					"album"   => $musicInfo['album'],
					"artists" => $musicInfo['artists'],
					"image"   => $musicInfo['image'],
					"current" => $musicPlay + 1,
					"lrcs"    => $lrcs,
					"user"    => $musicInfo['user']
				]));
				
				// 播放列表更新
				$playList = $this->getPlayList($musicList);
				$server->push($request->fd, json_encode([
					"type" => "list",
					"data" => $playList
				]));
			}
		});

		/**
		 *
		 *  Message Event 当客户端发送数据到服务器时触发此事件
		 *
		 */
		$this->server->on('message', function (Swoole\WebSocket\Server $server, $frame) {
			
			$clients = $server->connections;
			$clientIp = $this->getClientIp($frame->fd);
			$adminIp = $this->getAdminIp();
			
			// 判断客户端是否已被封禁
			if($this->isBanned($clientIp)) {
				$server->push($frame->fd, json_encode([
					"type" => "msg",
					"data" => "你没有权限发言"
				]));
			} else {
				
				// 把客户端 IP 地址的 C 段和 D 段打码作为用户名显示
				$username = $this->getMarkName($clientIp);
				
				// 解析客户端发过来的消息
				$message = $frame->data;
				$json = json_decode($message, true);
				
				if($json && isset($json['type'])) {
					switch($json['type']) {
						case "msg":
							
							// 获取客户端最后发言的时间戳
							$lastChat = $this->getLastChat($frame->fd);
							
							//防止客户端刷屏
							if($lastChat && time() - $lastChat <= MIN_CHATWAIT) {
								$server->push($frame->fd, json_encode([
									"type" => "msg",
									"data" => "发言太快，请稍后再发送"
								]));
							} else {
								
								// 储存用户的最后发言时间
								$this->setLastChat($frame->fd, time());
								$this->consoleLog("客户端 {$frame->fd} 发送消息：{$json['data']}", 1, true);
								
								if($json['data'] == "切歌") {
									
									// 如果是切歌的命令，先判断是否是管理员
									if($this->isAdmin($clientIp)) {
										
										// 执行切歌操作，这里的 time + 1 是为了防止 bug
										$this->setMusicTime(time() + 1);
										$this->setMusicPlay(0);
										$this->setMusicLong(time());
										
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "成功切歌"
										]));
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你没有权限这么做"
										]));
									}
								} elseif($json['data'] == "投票切歌") {
									
									// 由所有用户投票切掉当前歌曲
									$needSwitch = $this->getNeedSwitch();
									$totalUsers = $this->getTotalUsers();
									
									// 判断用户是否已经投过票
									if($this->isAlreadySwtich($clientIp)) {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你已经投票过了"
										]));
									} else {
										
										// 如果是第一次投票
										if($needSwitch == 1) {
											
											// 广播给所有客户端
											foreach($server->connections as $id) {
												$server->push($id, json_encode([
													"type" => "msg",
													"data" => "有人希望切歌，支持请输入 “投票切歌”"
												]));
											}
										}
										
										// 判断投票的用户数是否超过在线用户数的 30%
										if($needSwitch / $totalUsers >= 0.3) {
											
											// 执行切歌操作
											$this->setMusicTime(time() + 1);
											$this->setMusicPlay(0);
											$this->setMusicLong(time());
											$this->setNeedSwitch("");
											
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "成功切歌"
											]));
										} else {
											$this->addNeedSwitch($clientIp);
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "投票成功"
											]));
											
											// 广播给所有客户端
											foreach($server->connections as $id) {
												$server->push($id, json_encode([
													"type" => "msg",
													"data" => "当前投票人数：{$needSwitch}/{$totalUsers}"
												]));
											}
										}
										
										// 广播给所有客户端
										$userNickName = $this->getUserNickname($clientIp);
										foreach($clients as $id) {
											$showUserName = $this->getClientIp($id) == $adminIp ? $clientIp : $username;
											if($userNickName) {
												$showUserName = "{$userNickName} ({$showUserName})";
											}
											$server->push($id, json_encode([
												"type" => "chat",
												"user" => htmlspecialchars($showUserName),
												"time" => date("Y-m-d H:i:s"),
												"data" => htmlspecialchars($json['data'])
											]));
										}
									}
								} elseif($json['data'] == "禁言列表") {
									
									// 查看已禁言的用户列表，先判断是否是管理员
									if($this->isAdmin($clientIp)) {
										$server->push($frame->fd, json_encode([
											"type" => "chat",
											"user" => "System",
											"time" => date("Y-m-d H:i:s"),
											"data" => htmlspecialchars("禁言 IP 列表：" . $this->getBannedIp())
										]));
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你没有权限这么做"
										]));
									}
								} elseif(mb_substr($json['data'], 0, 3) == "禁言 " && mb_strlen($json['data']) > 3) {
									
									// 如果是禁言客户端的命令，先判断是否是管理员
									if($this->isAdmin($clientIp)) {
										$banName = trim(mb_substr($json['data'], 3, 99999));
										if(!empty($banName)) {
											
											// 判断是否已经被禁言
											if($this->isBanned($banName)) {
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "这个 IP 已经被禁言了"
												]));
											} else {
												$this->banIp($banName);
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "成功禁止此 IP 点歌和发言"
												]));
											}
										} else {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "禁言的 IP 不能为空！"
											]));
										}
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你没有权限这么做"
										]));
									}
								} elseif(mb_substr($json['data'], 0, 3) == "解禁 " && mb_strlen($json['data']) > 3) {
									
									// 如果是解禁客户端的命令，先判断是否是管理员
									if($this->isAdmin($clientIp)) {
										$banName = trim(mb_substr($json['data'], 3, 99999));
										if(!empty($banName)) {
											
											// 如果用户没有被封禁
											if(!$this->isBanned($banName)) {
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "这个 IP 没有被禁言"
												]));
											} else {
												$this->unbanIp($banName);
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "成功解禁此 IP 的禁言"
												]));
											}
										} else {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "解禁的 IP 不能为空！"
											]));
										}
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你没有权限这么做"
										]));
									}
									
								} elseif(mb_substr($json['data'], 0, 3) == "换歌 " && mb_strlen($json['data']) > 3) {
									
									// 如果是交换歌曲顺序的命令，先判断是否是管理员
									if($this->isAdmin($clientIp)) {
										$switchMusic = trim(mb_substr($json['data'], 3, 99999));
										if(!empty($switchMusic)) {
											$switchMusic = Intval($switchMusic);
											
											// 不可以切换正在播放的歌曲
											if($switchMusic == 0) {
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "正在播放的音乐不能切换"
												]));
											} else {
												
												// 取得列表
												$musicList = $this->getMusicList();
												$sourceList = $this->getMusicShow();
												
												// 储存并交换两首音乐
												$waitSwitch = $musicList[$switchMusic - 1];
												$needSwitch = $musicList[0];
												$musicList[0] = $waitSwitch;
												$sourceList[1] = $waitSwitch;
												$musicList[$switchMusic - 1] = $needSwitch;
												$sourceList[$switchMusic] = $needSwitch;
												
												// 播放列表更新
												$playList = $this->getPlayList($sourceList);
												$this->setMusicList($musicList);
												$this->setMusicShow($sourceList);
												
												// 广播给所有客户端
												foreach($server->connections as $id) {
													$server->push($id, json_encode([
														"type" => "list",
														"data" => $playList
													]));
												}
												
												// 发送通知
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "音乐切换成功"
												]));
											}
										} else {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "要切换的歌曲不能为空"
											]));
										}
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你没有权限这么做"
										]));
									}
								} elseif(mb_substr($json['data'], 0, 5) == "删除音乐 " && mb_strlen($json['data']) > 5) {
									
									// 如果是删除某首音乐的命令
									$deleteMusic = trim(mb_substr($json['data'], 5, 99999));
									$deleteMusic = Intval($deleteMusic);
									
									// 判断操作者是否是管理员
									if($this->isAdmin($clientIp)) {
										
										// 如果正在播放的音乐是第一首
										if($deleteMusic <= 0) {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "正在播放的音乐不能删除"
											]));
										} else {
											
											// 获取播放列表
											$musicList  = $this->getMusicList();
											$sourceList = $this->getMusicShow();
											
											// 从列表中删除这首歌
											unset($musicList[$deleteMusic - 1]);
											unset($sourceList[$deleteMusic]);
											
											// 重新整理列表
											$musicList = array_values($musicList);
											$sourceList = array_values($sourceList);
											
											// 播放列表更新
											$playList = $this->getPlayList($sourceList);
											$this->setMusicList($musicList);
											$this->setMusicShow($sourceList);
											
											// 广播给所有客户端
											foreach($server->connections as $id) {
												$server->push($id, json_encode([
													"type" => "list",
													"data" => $playList
												]));
											}
											
											// 发送通知
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "音乐删除成功"
											]));
										}
									} else {
										
										// 如果正在播放的音乐是第一首
										if($deleteMusic <= 0) {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "正在播放的音乐不能删除"
											]));
										} else {
											
											// 获取播放列表
											$musicList  = $this->getMusicList();
											$sourceList = $this->getMusicShow();
											
											if(isset($musicList[$deleteMusic - 1]) && $musicList[$deleteMusic - 1]['user'] == $clientIp) {
											
												// 从列表中删除这首歌
												unset($musicList[$deleteMusic - 1]);
												unset($sourceList[$deleteMusic]);
												
												// 重新整理列表
												$musicList = array_values($musicList);
												$sourceList = array_values($sourceList);
												
												// 播放列表更新
												$playList = $this->getPlayList($sourceList);
												$this->setMusicList($musicList);
												$this->setMusicShow($sourceList);
												
												// 广播给所有客户端
												foreach($server->connections as $id) {
													$server->push($id, json_encode([
														"type" => "list",
														"data" => $playList
													]));
												}
												
												// 发送通知
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "音乐删除成功"
												]));
											} else {
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "你只能删除自己点的歌"
												]));
											}
										}
									}
									
								} elseif(mb_substr($json['data'], 0, 5) == "房管登录 " && mb_strlen($json['data']) > 5) {
									
									// 如果是房管登录操作
									$userPass = trim(mb_substr($json['data'], 5, 99999));
									
									// 判断密码是否正确
									if($userPass == $this->adminPass) {
										$this->setAdminIp($clientIp);
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "房管登录成功"
										]));
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "房管密码错误"
										]));
									}
									
								} elseif(mb_substr($json['data'], 0, 5) == "加黑名单 " && mb_strlen($json['data']) > 5) {
									
									// 如果是房管登录操作
									$blackList = trim(mb_substr($json['data'], 5, 99999));
									
									// 判断密码是否正确
									if($this->isAdmin($clientIp)) {
										$this->addBlackList($blackList);
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "已增加新的黑名单"
										]));
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "你没有权限这么做"
										]));
									}
									
								} elseif(mb_substr($json['data'], 0, 5) == "设置昵称 " && mb_strlen($json['data']) > 5) {
									
									// 如果是设置昵称
									$userNick = trim(mb_substr($json['data'], 5, 99999));
									
									// 正则判断用户名是否合法
									if(preg_match("/^[\x{4e00}-\x{9fa5}A-Za-z0-9_]+[^_]{3,20}$/u", $userNick)) {
										if($this->isBlackList($userNick)) {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "不允许的昵称"
											]));
										} elseif(mb_Strlen($userNick) <= 20) {
											$this->setUserNickname($clientIp, $userNick);
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "昵称设置成功"
											]));
											$server->push($frame->fd, json_encode([
												"type" => "setname",
												"data" => $userNick
											]));
										} else {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "昵称最多 20 个字符"
											]));
										}
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "只允许中英文数字下划线，最少 4 个字"
										]));
									}
									
								} elseif(mb_substr($json['data'], 0, 3) == "点歌 " && mb_strlen($json['data']) > 3) {
									
									// 如果是点歌命令
									$musicName = trim(mb_substr($json['data'], 3, 99999));
									if(!empty($musicName)) {
										
										// 判断是否已经有人在点歌中
										if(count($this->getUserMusic($clientIp)) > MAX_USERMUSIC) {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "你已经点了很多歌了，请先听完再点"
											]));
										} elseif($this->isLockedSearch()) {
											$server->push($frame->fd, json_encode([
												"type" => "msg",
												"data" => "当前有任务正在执行，请稍后再试"
											]));
										} else {
											if(mb_strlen($json['data']) > MAX_CHATLENGTH) {
												$server->push($frame->fd, json_encode([
													"type" => "msg",
													"data" => "消息过长，最多 " . MAX_CHATLENGTH . " 字符"
												]));
											} else {
												
												// 提交任务给服务器
												$server->task(["id" => $frame->fd, "action" => "Search", "data" => $musicName]);
												
												// 广播给所有客户端
												$userNickName = $this->getUserNickname($clientIp);
												foreach($clients as $id) {
													$showUserName = $this->getClientIp($id) == $adminIp ? $clientIp : $username;
													if($userNickName) {
														$showUserName = "{$userNickName} ({$showUserName})";
													}
													$server->push($id, json_encode([
														"type" => "chat",
														"user" => htmlspecialchars($showUserName),
														"time" => date("Y-m-d H:i:s"),
														"data" => htmlspecialchars($json['data'])
													]));
												}
											}
										}
									} else {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "歌曲名不能为空！"
										]));
									}
								} else {
									
									// 默认消息内容，即普通聊天，广播给所有客户端
									if(mb_strlen($json['data']) > MAX_CHATLENGTH) {
										$server->push($frame->fd, json_encode([
											"type" => "msg",
											"data" => "消息过长，最多 " . MAX_CHATLENGTH . " 字符"
										]));
									} else {
										if($this->isAdmin($clientIp)) {
											$username = "管理员";
										}
										$userNickName = $this->getUserNickname($clientIp);
										foreach($clients as $id) {
											$showUserName = $this->isAdmin($this->getClientIp($id)) ? $clientIp : $username;
											if($userNickName) {
												$showUserName = "{$userNickName} ({$showUserName})";
											}
											$server->push($id, json_encode([
												"type" => "chat",
												"user" => htmlspecialchars($showUserName),
												"time" => date("Y-m-d H:i:s"),
												"data" => htmlspecialchars($json['data'])
											]));
										}
									}
								}
							}
							break;
						case "heartbeat":
							// 处理客户端发过来心跳包的操作，返回在线人数给客户端
							$server->push($frame->fd, json_encode([
								"type" => "online",
								"data" => count($server->connections)
							]));
							break;
						default:
							// 如果客户端发过来未知的消息类型
							$this->consoleLog("客户端 {$frame->fd} 发送了未知消息：{$message}", 2, true);
					}
				}
			}
		});
		
		/**
		 *
		 *  Close Event 当客户端断开与服务器的连接时触发此事件
		 *
		 */
		$this->server->on('close', function ($server, $fd) {
			$this->consoleLog("客户端 {$fd} 已断开连接", 1, true);
		});
		
		/**
		 *
		 *  Task Event 当服务器运行任务时触发此事件
		 *
		 */
		$this->server->on('Task', function (Swoole\Server $server, $task_id, $from_id, $data) {
			
			// 如果是服务器初始化任务
			if($data['action'] == "Start") {
				
				// 设定死循环的目的是为了建立一个单独的线程用于执行数据更新
				while(true) {
					
					$musicList = $this->getMusicList();
					$musicShow = $this->getMusicShow();
					
					// 如果列表为空
					if(empty($musicList) || empty($musicShow)) {
						$musicList = empty($musicList) ? $this->getSavedMusicList() : $musicList;
						$musicShow = empty($musicShow) ? $this->getSavedMusicShow() : $musicShow;
					}
					
					// 如果音乐列表不为空
					if(!empty($musicList)) {
						
						$musicTime = $this->getMusicTime();
						
						// 如果音乐的结束时间小于当前时间，即播放完毕
						if($musicTime < time() + 3) {
							
							$server->randomed = false;
							
							// 获得下一首歌的信息
							$musicInfo  = $musicList[0];
							$sourceList = $musicList;
							
							// 从播放列表里移除第一首，因为已经开始播放了
							unset($musicList[0]);
							$musicList = array_values($musicList);
							
							$this->consoleLog("正在播放音乐：{$musicInfo['name']}", 1, true);
							
							// 储存信息
							$this->setMusicList($musicList);
							$this->setMusicShow($sourceList);
							$this->setMusicTime(time() + round($musicInfo['time']));
							$this->setMusicLong(time());
							$this->setMusicPlay(0);
							$this->setNeedSwitch("");
							
							// 获得播放列表
							$playList = $this->getPlayList($sourceList);
							$musicLrc = $this->getMusicLrcs($musicInfo['id']);
							
							// 广播给所有客户端
							if($server->connections) {
								$currentURL = $this->getMusicUrl($musicInfo['id']);
								foreach($server->connections as $id) {
									$server->push($id, json_encode([
										"type"    => "music",
										"id"      => $musicInfo['id'],
										"name"    => $musicInfo['name'],
										"file"    => $currentURL,
										"album"   => $musicInfo['album'],
										"artists" => $musicInfo['artists'],
										"image"   => $musicInfo['image'],
										"lrcs"    => $musicLrc,
										"user"    => $musicInfo['user']
									]));
									$server->push($id, json_encode([
										"type" => "list",
										"data" => $playList
									]));
								}
							}
						}
					} else {
						
						// 如果列表已经空了，先获取当前音乐是否还在播放
						$musicTime = $this->getMusicTime();
						
						// 判断音乐的结束时间是否小于当前时间，如果是则表示已经播放完了
						if($musicTime && $musicTime < time() + 3) {
							
							// 获取随机的音乐 ID
							$rlist = $this->getRandomList();
							if($rlist && !$server->randomed) {
								
								// 判断是否还有人在线，如果没人就不播放了，有人才播放
								if($server->connections && count($server->connections) > 0) {
									
									// 开始播放随机音乐
									$this->searchMusic($server, ["id" => false, "action" => "Search", "data" => $rlist]);
									$server->randomed = true;
								}
							}
						}
					}
					
					// 记录音乐已经播放的时间
					$musicLong = $this->getMusicLong();
					if($musicLong && is_numeric($musicLong)) {
						$this->setMusicPlay(time() - $musicLong);
					}
					
					// 将播放列表储存到硬盘
					$this->setSavedMusicList($musicList);
					$this->setSavedMusicShow($musicShow);
					
					// 每秒钟执行一次任务
					sleep(1);
				}
				
			} elseif($data['action'] == "Search") {
				// 如果是搜索音乐的任务
				$this->searchMusic($server, $data);
			}
		});
		
		/**
		 *
		 *  Finish Event 当服务器任务完成时触发此事件
		 *
		 */
		$this->server->on('Finish', function (Swoole\Server $server, $task_id, $data) {
			if($data['action'] == "msg" && $data['id']) {
				$server->push($data['id'], json_encode([
					"type" => "msg",
					"data" => $data['data']
				]));
			}
		});
	}
	
	/**
	 *
	 *  Run 启动服务器
	 *
	 */
	public function run()
	{
		$this->server->start();
	}
	
	/**
	 *
	 *  SearchMusic 搜索音乐
	 *
	 */
	private function searchMusic(Swoole\Server $server, $data)
	{
		$this->consoleLog("正在点歌：{$data['data']}", 1, true);
		
		$musicList  = $this->getMusicList();
		$sourceList = $this->getMusicShow();
		$this->lockSearch();
		
		// 开始搜索音乐
		$json = $this->fetchMusicApi($data['data']);
		
		if($json && !empty($json)) {
			if(isset($json[0]['id'])) {
				$m = $json[0];
				// 判断是否已经点过这首歌了
				if($this->isInArray($musicList, $m['id'])) {
					$this->unlockSearch();
					$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "这首歌已经在列表里了"]);
				} else {
					$artists = $this->getArtists($m['artist']);
					$musicUrl = $this->getMusicUrl($m['id']);
					// 如果能够正确获取到音乐 URL
					if($this->isBlackList($m['id']) || $this->isBlackList($m['name']) || $this->isBlackList($artists)) {
						$this->unlockSearch();
						$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "这首歌被设置不允许点播"]);
					} elseif($musicUrl !== "") {
						$musicId = Intval($m['id']);
						// 开始下载音乐
						$musicData = $this->fetchMusic($m, $musicUrl);
						$musicImage = $this->getMusicImage($m['pic_id']);
						// 如果音乐的文件大小不为 0
						if(strlen($musicData) > 0) {
							$musicTime = $this->getMusicLength($m['id']);
							// 如果音乐的长度为 0（说明下载失败或其他原因）
							if($musicTime == 0) {
								$this->unlockSearch();
								$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_TIME0"]);
							} elseif($musicTime > MAX_MUSICLENGTH) {
								$this->unlockSearch();
								$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲太长影响他人体验，不能超过 " . MAX_MUSICLENGTH . " 秒"]);
							} else {
								// 保存列表
								$clientIp = $data['id'] ? $this->getClientIp($data['id']) : "127.0.0.1";
								$musicList[] = [
									"id"      => $musicId,
									"name"    => $m['name'],
									"file"    => $musicUrl,
									"time"    => $musicTime,
									"album"   => $m['album'],
									"artists" => $artists,
									"image"   => $musicImage,
									"user"    => $clientIp
								];
								$sourceList[] = [
									"id"      => $musicId,
									"name"    => $m['name'],
									"file"    => $musicUrl,
									"time"    => $musicTime,
									"album"   => $m['album'],
									"artists" => $artists,
									"image"   => $musicImage,
									"user"    => $clientIp
								];
								$this->setMusicList($musicList);
								$this->setMusicShow($sourceList);
								// 播放列表更新
								$playList = $this->getPlayList($sourceList);
								// 广播给所有客户端
								if($data['id'] && $this->server->connections) {
									foreach($this->server->connections as $id) {
										$this->server->push($id, json_encode([
											"type" => "list",
											"data" => $playList
										]));
									}
								}
								$this->unlockSearch();
								$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "点歌成功"]);
							}
						} else {
							$this->unlockSearch();
							$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_FILE_EMPTY"]);
						}
					} else {
						$this->unlockSearch();
						$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_URL_EMPTY"]);
					}
				}
			} else {
				$this->unlockSearch();
				$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_ID_EMPTY"]);
			}
		} else {
			$this->unlockSearch();
			$this->server->finish(["id" => $data['id'], "action" => "msg", "data" => "未搜索到此歌曲"]);
		}
	}
	
	/**
	 *
	 *  BanIp 封禁指定 IP 地址
	 *
	 */
	private function banIp($ip)
	{
		$bannedIp = $this->getBannedIp() . "{$ip};";
		$this->server->table->set(0, ["banned_ips" => $bannedIp]);
	}
	
	/**
	 *
	 *  UnbanIp 解封指定 IP 地址
	 *
	 */
	private function unbanIp($ip)
	{
		$bannedIp = str_replace("{$ip};", "", $this->getBannedIp());
		$this->setBannedIp($bannedIp);
	}
	
	/**
	 *
	 *  LockSearch 禁止点歌
	 *
	 */
	private function lockSearch()
	{
		$this->server->table->set(0, ["downloaded" => 1]);
	}
	
	/**
	 *
	 *  UnlockSearch 允许点歌
	 *
	 */
	private function unlockSearch()
	{
		$this->server->table->set(0, ["downloaded" => 0]);
	}
	
	/**
	 *
	 *  AddNewSwitch 增加新的投票成员
	 *
	 */
	private function addNeedSwitch($ip)
	{
		$switchList = $this->server->table->get(0, "needswitch") . "{$ip};";
		$this->server->table->set(0, ["needswitch" => $switchList]);
	}
	
	/**
	 *
	 *  AddBlackList 增加新的黑名单关键字
	 *
	 */
	private function addBlackList($data)
	{
		$blackList = $this->getBlackList();
		$blackList[] = trim($data);
		$this->setBlackList($blackList);
	}
	
	/**
	 *
	 *  IsAdmin 判断是否是管理员
	 *
	 */
	private function isAdmin($ip)
	{
		$adminIp = $this->getAdminIp();
		return ($adminIp !== "" && $adminIp !== "127.0.0.1" && $adminIp == $ip);
	}
	
	/**
	 *
	 *  IsBanned 判断是否已被封禁
	 *
	 */
	private function isBanned($ip)
	{
		$bannedIp = $this->getBannedIp();
		return ($bannedIp && stristr($bannedIp, "{$ip};"));
	}
	
	/**
	 *
	 *  IsBlackList 判断是否在黑名单音乐中
	 *
	 */
	private function isBlackList($key)
	{
		$blackList = $this->getBlackList();
		for($i = 0;$i < count($blackList);$i++) {
			if(stristr($key, $blackList[$i])) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 *
	 *  IsLockedSearch 判断是否禁止点歌
	 *
	 */
	private function isLockedSearch()
	{
		return Intval($this->server->table->get(0, "downloaded")) == 1;
	}
	
	/**
	 *
	 *  IsAlreadySwitch 判断是否已经投票过了
	 *
	 */
	private function isAlreadySwtich($ip)
	{
		$switchList = $this->server->table->get(0, "needswitch");
		return stristr($switchList, "{$ip};") ? true : false;
	}
	
	/**
	 *
	 *  IsInArray 判断指定元素是否在数组中
	 *
	 */
	private function isInArray($array, $need, $key = 'id')
	{
		$found = false;
		foreach($array as $smi) {
			if($smi[$key] == $need) {
				$found = true;
				break;
			}
		}
		return $found;
	}
	
	/**
	 *
	 *  GetBlackList 获取音乐的黑名单列表
	 *
	 */
	private function getBlackList()
	{
		$data = @file_get_contents(ROOT . "/blacklist.txt");
		$exp = explode("\n", $data);
		$result = [];
		for($i = 0;$i < count($exp);$i++) {
			$tmpData = trim($exp[$i]);
			if(!empty($tmpData)) {
				$result[] = $tmpData;
			}
		}
		return $result;
	}
	
	/**
	 *
	 *  GetClientIp 获取客户端 IP 地址
	 *
	 */
	private function getClientIp($id)
	{
		return $this->server->chats->get($id, "ip") ?? "127.0.0.1";
	}
	
	/**
	 *
	 *  GetLastChat 获取客户端最后一次发言时间
	 *
	 */
	private function getLastChat($id)
	{
		return $this->server->chats->get($id, "last") ?? 0;
	}
	
	/**
	 *
	 *  GetMaskName 获取和谐过的客户端 IP 地址
	 *
	 */
	private function getMarkName($ip)
	{
		$username = $ip ?? "127.0.0.1";
		$uexp = explode(".", $username);
		if(count($uexp) >= 4) {
			$username = "{$uexp[0]}.{$uexp[1]}." . str_repeat("*", strlen($uexp[2])) . "." . str_repeat("*", strlen($uexp[3]));
		} else {
			$username = "Unknown";
		}
		return $username;
	}
	
	/**
	 *
	 *  GetRandomList 获取随机的音乐 ID
	 *
	 */
	private function getRandomList()
	{
		$data = @file_get_contents(ROOT . "/random.txt");
		$exp = explode("\n", $data);
		if(count($exp) > 0) {
			$rand = trim($exp[mt_rand(0, count($exp) - 1)]);
		} else {
			$rand = false;
		}
		return $rand;
	}
	
	/**
	 *
	 *  GetMusicUrl 获取音乐的下载地址
	 *
	 */
	private function getMusicUrl($id)
	{
		echo $this->debug ? $this->consoleLog("Http Request >> {$this->musicApi}/api.php?source=netease&types=url&id={$id}", 0) : "";
		$rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=url&id={$id}");
		$json    = json_decode($rawdata, true);
		echo $this->debug ? $this->consoleLog("Http Request << {$rawdata}", 0) : "";
		if($json && isset($json["url"])) {
			return str_replace("http://", "https://", $json["url"]);
		} else {
			return "";
		}
	}
	
	/**
	 *
	 *  GetMusicLrcs 获取音乐的歌词
	 *
	 */
	private function getMusicLrcs($id)
	{
		if(!file_exists(ROOT . "/tmp/{$id}.lrc")) {
			echo $this->debug ? $this->consoleLog("Http Request >> https://music.163.com/api/song/lyric?os=pc&lv=-1&id={$id}", 0) : "";
			$musicLrcs = @file_get_contents("https://music.163.com/api/song/lyric?os=pc&lv=-1&id={$id}");
			echo $this->debug ? $this->consoleLog("Http Request << " . substr($musicLrcs, 0, 256), 0) : "";
			if(strlen($musicLrcs) > 0) {
				@file_put_contents(ROOT . "/tmp/{$id}.lrc", $musicLrcs);
			}
		} else {
			$musicLrcs = @file_get_contents(ROOT . "/tmp/{$id}.lrc");
		}
		$lrcs = "[00:01.00]暂无歌词";
		$lrc = json_decode($musicLrcs, true);
		if($lrc) {
			if(isset($lrc['lrc'])) {
				$lrcs = $lrc['lrc']['lyric'];
			} else {
				$lrcs = "[00:01.00]暂无歌词";
			}
		}
		return $lrcs;
	}
	
	/**
	 *
	 *  GetMusicImage 获取音乐的专辑封面图片地址
	 *
	 */
	private function getMusicImage($picId)
	{
		echo $this->debug ? $this->consoleLog("Http Request >> {$this->musicApi}/api.php?source=netease&types=pic&id={$picId}", 0) : "";
		$rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=pic&id={$picId}");
		$imgdata = json_decode($rawdata, true);
		echo $this->debug ? $this->consoleLog("Http Request << {$rawdata}", 0) : "";
		return $imgdata['url'] ?? "";
	}
	
	/**
	 *
	 *  GetPlayList 获取格式化过的播放列表
	 *
	 */
	private function getPlayList($sourceList)
	{
		// 播放列表更新
		$playList = <<<EOF
<tr>
	<th>ID</th>
	<th>歌名</th>
	<th>歌手</th>
	<th>专辑</th>
	<th>点歌人</th>
</tr>
EOF;
		foreach($sourceList as $mid => $mi) {
			$userNick = $this->getUserNickname($mi['user']) ?? "匿名用户";
			$user = "{$userNick} (" . $this->getMarkName($mi['user']) . ")";
			$musicName = (mb_strlen($mi['name']) > 32) ? mb_substr($mi['name'], 0, 30) . "..." : $mi['name'];
			$playList .= <<<EOF
<tr>
	<td>{$mid}</td>
	<td>{$musicName}</td>
	<td>{$mi['artists']}</td>
	<td>{$mi['album']}</td>
	<td>{$user}</td>
</tr>
EOF;
		}
		return $playList;
	}
	
	/**
	 *
	 *  GetUserMusic 获取用户点播的音乐数量
	 *
	 */
	private function getUserMusic($ip)
	{
		$musicList = $this->getMusicList();
		$userMusic = [];
		foreach($musicList as $music) {
			if($music['user'] == $ip) {
				$userMusic[] = $music;
			}
		}
		return $userMusic;
	}
	
	/**
	 *
	 *  GetMusicList 获取等待播放的音乐列表
	 *
	 */
	private function getMusicList()
	{
		if(USE_REDIS) {
			$redis = new Redis();
			$redis->connect(REDIS_HOST, REDIS_PORT);
			if(!empty(REDIS_PASS)) {
				$redis->auth(REDIS_PASS);
			}
			$data = $redis->get("syncmusic-list");
			$musicList = json_decode($data, true);
		} else {
			$musicList = json_decode($this->server->table->get(0, "music_list"), true);
		}
		if(!$musicList || empty($musicList)) {
			$musicList = [];
		}
		return $musicList;
	}
	
	/**
	 *
	 *  GetMusicShow 获取用于显示在网页上的音乐列表
	 *
	 */
	private function getMusicShow()
	{
		if(USE_REDIS) {
			$redis = new Redis();
			$redis->connect(REDIS_HOST, REDIS_PORT);
			if(!empty(REDIS_PASS)) {
				$redis->auth(REDIS_PASS);
			}
			$data = $redis->get("syncmusic-show");
			$sourceList = json_decode($data, true);
		} else {
			$sourceList = json_decode($this->server->table->get(0, "music_show"), true);
		}
		if(!$sourceList || empty($sourceList)) {
			$sourceList = [];
		}
		return $sourceList;
	}
	
	/**
	 *
	 *  GetSavedMusicList 获取已经保存在硬盘的音乐列表
	 *
	 */
	private function getSavedMusicList()
	{
		$data = @file_get_contents(ROOT . "/musiclist.json");
		return empty($data) ? [] : json_decode($data, true);
	}
	
	/**
	 *
	 *  GetSavedMusicShow 获取已经保存在硬盘的音乐显示列表
	 *
	 */
	private function getSavedMusicShow()
	{
		$data = @file_get_contents(ROOT . "/musicshow.json");
		return empty($data) ? [] : json_decode($data, true);
	}
	
	/**
	 *
	 *  GetMusicTime 获取当前正在播放的音乐的结束时间
	 *
	 */
	private function getMusicTime()
	{
		return $this->server->table->get(0, "music_time") ?? 0;
	}
	
	/**
	 *
	 *  GetMusicLong 获取音乐开始播放的时间
	 *
	 */
	private function getMusicLong()
	{
		return $this->server->table->get(0, "music_long") ?? time();
	}
	
	/**
	 *
	 *  GetMusicPlay 获取音乐已经播放的时间
	 *
	 */
	private function getMusicPlay()
	{
		return $this->server->table->get(0, "music_play") ?? 0;
	}
	
	/**
	 *
	 *  GetAdminIp 获取管理员的 IP
	 *
	 */
	private function getAdminIp()
	{
		$adminIp = @file_get_contents(ROOT . "/admin.ip");
		return $adminIp ?? "127.0.0.1";
	}
	
	/**
	 *
	 *  GetBannedIp 获取已经被封禁的 IP
	 *
	 */
	private function getBannedIp()
	{
		return $this->server->table->get(0, "banned_ips") ?? "";
	}
	
	/**
	 *
	 *  GetMusicLength 获取音乐的总长度时间
	 *
	 */
	private function getMusicLength($id)
	{
		return FloatVal(shell_exec(PYTHON_EXEC . " getlength.py " . ROOT . "/tmp/{$id}.mp3"));
	}
	
	/**
	 *
	 *  GetArtists 获取音乐的歌手信息
	 *
	 */
	private function getArtists($data)
	{
		if(count($data) > 1) {
			$artists = "";
			foreach($data as $artist) {
				$artists .= $artist . ",";
			}
			$artists = $artists == "" ? "未知歌手" : mb_substr($artists, 0, mb_strlen($artists) - 1);
		} else {
			$artists = $data[0];
		}
		return $artists;
	}
	
	/**
	 *
	 *  GetLoggerLevel 获取输出日志的等级
	 *
	 */
	private function getLoggerLevel($level)
	{
		$levelGroup = ["DEBUG", "INFO", "WARNING", "ERROR"];
		return $levelGroup[$level] ?? "INFO";
	}
	
	/**
	 *
	 *  GetNeedSwitch 获取需要切歌的投票用户列表
	 *
	 */
	private function getNeedSwitch()
	{
		$switchList = $this->server->table->get(0, "needswitch");
		return is_string($switchList) ? count(explode(";", $switchList)) : 0;
	}
	
	/**
	 *
	 *  GetTotalUsers 获取当前所有在线的客户端数量
	 *
	 */
	private function getTotalUsers()
	{
		return $this->server->connections ? count($this->server->connections) : 0;
	}
	
	/**
	 *
	 *  GetUserNickname 获取用户的昵称
	 *
	 */
	private function getUserNickname($ip)
	{
		$data = $this->getUserNickData();
		return $data[$ip] ?? false;
	}
	
	/**
	 *
	 *  GetUserNickData 获取所有用户的昵称数据
	 *
	 */
	private function getUserNickData()
	{
		$data = @file_get_contents(ROOT . "/username.json");
		$json = json_decode($data, true);
		return $json ?? [];
	}
	
	/**
	 *
	 *  SetUserNickname 设置用户的昵称
	 *
	 */
	private function setUserNickname($ip, $name)
	{
		$data = $this->getUserNickData();
		$data[$ip] = $name;
		$this->setUserNickData($data);
	}
	
	/**
	 *
	 *  SetUserNickData 将昵称数据写入到硬盘
	 *
	 */
	private function setUserNickData($data)
	{
		@file_put_contents(ROOT . "/username.json", json_encode($data));
	}
	
	/**
	 *
	 *  SetBlackList 将黑名单数据写入到硬盘
	 *
	 */
	private function setBlackList($data)
	{
		$result = "";
		for($i = 0;$i < count($data);$i++) {
			$result .= $data[$i] . "\n";
		}
		@file_put_contents(ROOT . "/blacklist.txt", $result);
	}
	
	/**
	 *
	 *  SetLastChat 设置客户端的最后发言时间
	 *
	 */
	private function setLastChat($id, $time = 0)
	{
		$this->server->chats->set($id, ["last" => $time]);
	}
	
	/**
	 *
	 *  SetMusicList 设置等待播放的音乐列表
	 *
	 */
	private function setMusicList($data)
	{
		if(USE_REDIS) {
			$redis = new Redis();
			$redis->connect(REDIS_HOST, REDIS_PORT);
			if(!empty(REDIS_PASS)) {
				$redis->auth(REDIS_PASS);
			}
			$redis->set("syncmusic-list", json_encode($data));
		} else {
			$this->server->table->set(0, ["music_list" => json_encode($data)]);
		}
	}
	
	/**
	 *
	 *  SetMusicShow 设置用于网页显示的音乐列表
	 *
	 */
	private function setMusicShow($data)
	{
		if(USE_REDIS) {
			$redis = new Redis();
			$redis->connect(REDIS_HOST, REDIS_PORT);
			if(!empty(REDIS_PASS)) {
				$redis->auth(REDIS_PASS);
			}
			$redis->set("syncmusic-show", json_encode($data));
		} else {
			$this->server->table->set(0, ["music_show" => json_encode($data)]);
		}
	}
	
	/**
	 *
	 *  SetMusicTime 设置音乐播放的结束时间
	 *
	 */
	private function setMusicTime($data)
	{
		$this->server->table->set(0, ["music_time" => $data]);
	}
	
	/**
	 *
	 *  SetMusicLong 设置音乐播放的开始时间
	 *
	 */
	private function setMusicLong($data)
	{
		$this->server->table->set(0, ["music_long" => $data]);
	}
	
	/**
	 *
	 *  SetMusicPlay 设置音乐已经播放的时间
	 *
	 */
	private function setMusicPlay($data)
	{
		$this->server->table->set(0, ["music_play" => $data]);
	}
	
	/**
	 *
	 *  SetSavedMusicList 将等待播放的音乐列表储存到硬盘
	 *
	 */
	private function setSavedMusicList()
	{
		@file_put_contents(ROOT . "/musiclist.json", $this->server->table->get(0, "music_list"));
	}
	
	/**
	 *
	 *  SetSavedMusicShow 将用于显示在网页上的音乐列表储存到硬盘
	 *
	 */
	private function setSavedMusicShow($data)
	{
		@file_put_contents(ROOT . "/musicshow.json", $this->server->table->get(0, "music_show"));
	}
	
	/**
	 *
	 *  SetAdminIp 设置管理员的 IP 地址
	 *
	 */
	private function setAdminIp($ip)
	{
		@file_put_contents(ROOT . "/admin.ip", $ip);
	}
	
	/**
	 *
	 *  SetBannedIp 设置被封禁的 IP 列表
	 *
	 */
	private function setBannedIp($ip)
	{
		$this->server->table->set(0, ["banned_ips" => $ip]);
	}
	
	/**
	 *
	 *  SetNeedSwitch 设置需要投票切歌的用户列表
	 *
	 */
	private function setNeedSwitch($data)
	{
		$this->server->table->set(0, ["needswitch" => $data]);
	}
	
	/**
	 *
	 *  FetchMusicApi 搜索指定关键字的音乐
	 *
	 */
	private function fetchMusicApi($keyWord)
	{
		$keyWord = urlencode($keyWord);
		echo $this->debug ? $this->consoleLog("Http Request >> {$this->musicApi}/api.php?source=netease&types=search&name={$keyWord}&count=1&pages=1", 0) : "";
		$rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=search&name={$keyWord}&count=1&pages=1");
		echo $this->debug ? $this->consoleLog("Http Request << {$rawdata}", 0) : "";
		return json_decode($rawdata, true);
	}
	
	/**
	 *
	 *  FetchMusic 读取音乐文件内容
	 *
	 */
	private function fetchMusic($m, $download = '')
	{
		if(!file_exists(ROOT . "/tmp/{$m['id']}.mp3")) {
			$this->consoleLog("歌曲 {$m['name']} 不存在，下载中...", 1, true);
			$musicFile = @file_get_contents($download);
			$this->consoleLog("歌曲 {$m['name']} 下载完成。", 1, true);
			@file_put_contents(ROOT . "/tmp/{$m['id']}.mp3", $musicFile);
		} else {
			$musicFile = @file_get_contents(ROOT . "/tmp/{$m['id']}.mp3");
		}
		return $musicFile;
	}
	
	/**
	 *
	 *  ConsoleLog 控制台输出日志
	 *
	 */
	private function consoleLog($data, $level = 1, $directOutput = false)
	{
		$msgData = "[" . date("Y-m-d H:i:s") . " " . $this->getLoggerLevel($level) . "] {$data}\n";
		if($directOutput) {
			echo $msgData;
		} else {
			return $msgData;
		}
	}
}
