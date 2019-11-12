<?php
// 你的 API 服务器地址
define("API_URL", "https://cdn.zerodream.net/netease");
if(isset($_GET['s']) && !empty($_GET['s'])) {
	$keyWord = urlencode($_GET['s']);
	$rawdata = @file_get_contents(API_URL . "/api.php?source=netease&types=search&name={$keyWord}&count=10&pages=1");
	$data = json_decode($rawdata, true);
	if(!$data || empty($data)) {
		if(isset($_GET['debug'])) {
			exit($rawdata);
		}
		exit("<center><p>无搜索结果</p></center>");
	}
} else {
	exit("<center><p>未输入搜索内容</p></center>");
}
function getArtists($data) {
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
?>
<html>
	<head>
		<meta name="theme-color" content="#009688" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
		<meta http-equiv="X-UA-Compatible" content="IE=11">
		<title>SyncMusic - 在线点歌</title>
		<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
		<link rel="stylesheet" href="https://cdn.zerodream.net/css/materialize.min.css">
		<link rel="stylesheet" href="https://cdn.zerodream.net/font-awesome/css/font-awesome.min.css">
		<style>.table tr{font-size:14px;}.table .result:hover{cursor:pointer;color:#009688 !important;}.table tr th,.table tr td{white-space: nowrap;}</style>
	</head>
	<body style="display: none;">
		<table class="table" id="musicList">
			<tr>
				<th>歌名</th>
				<th>歌手</th>
				<th>专辑</th>
			</tr>
			<?php
			foreach($data as $music) {
				echo "<tr class='result' onclick='select({$music['id']})'>
				<td>{$music['name']}</td>
				<td>" . getArtists($music['artist']) . "</td>
				<td>{$music['album']}</td>
			</tr>";
			}
			?>
		</table>
	</body>
	<script type="text/javascript" src="https://cdn.zerodream.net/js/jquery.min.js"></script>
	<script type="text/javascript" src="https://cdn.zerodream.net/js/materialize.min.js"></script>
	<script type="text/javascript">
	function select(data) {
		try {
			window.parent.msginput.value = "点歌 " + data;
			window.parent.$(window.parent.search).fadeOut();
			window.parent.$(window.parent.msginput).focus();
		} catch(e) {
			// No
		}
	}
	window.onload = function() {
		$(document.body).fadeIn();
	}
	</script>
</html>
