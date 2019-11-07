# SyncMusic
🎵 PHP Swoole 开发的在线同步点歌台，支持自由点歌，切歌，调整排序，删除指定音乐以及基础权限分级

![img](https://i.loli.net/2019/11/07/LWSAIwPiYjnH7zT.png)

代码写了很详细的注释，非常适合新人学习 PHP WebSocket 应用程序开发。

## 功能特性
- 支持在线点歌
- 支持多人实时聊天
- 支持投票切掉当前音乐
- 管理员可切歌
- 管理员可删除指定音乐
- 管理员可将指定音乐提前播放
- 管理员可禁言指定用户
- 美观的界面 (Material Design)
- 无需登录，任何人都可以点歌
- 无需数据库，由 Swoole 内存表储存数据

有个地方就是获取音乐时间长度是用了 python，原本我是想直接用 PHP 来获取的，但是有点麻烦，还要导入一个单独的库，想了想还是用最简单的办法来解决，于是就用 python 整了个简单的脚本。

如果你有更好的读取音乐时间的实现方法，欢迎提 pr 或通过 issues 告诉我。

## 安装教程

请访问 Wiki 页面：[Installation](https://github.com/kasuganosoras/SyncMusic/wiki/Installation)

如果安装时遇到问题，可以通过 Issues 提问。

## 在线预览

ZeroDream：[Akkariin 点歌台](https://music.tql.ink/)

> 如果你想将你的点歌台列在这里，请开一个 Issues 并写上你的点歌台地址。

## 开源协议

本项目使用 GPL v3 协议开源
