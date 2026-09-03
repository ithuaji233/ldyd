# 牢大运动插件

这套插件按当前源码整理后的交付目标就是：

`上传目录 -> 修改 config.php -> 访问入口`

前端静态资源、PHP 接口入口、同步脚本、本地 SQLite 都在插件目录内，不依赖额外前端源码目录，也没有 `config.local.php` 这一层配置文件。

## 部署方式

1. 把整个 `php-plugin` 目录上传到目标站点。
2. 将目录名改成实际插件目录名，例如 `laoda`。
3. 修改 `config.php`。
4. 确保插件目录和 `data/` 目录可写。
5. 浏览器访问：

```text
/插件目录/
```

或：

```text
/插件目录/index/plugin.php
```

## 目录说明

- `index.php`：根入口，实际会跳转到 `index/plugin.php`
- `index/plugin.php`：前端页面入口，读取 `public/app/.vite/manifest.json`
- `api.php`：插件统一接口入口
- `cron.php`：命令行同步入口，本质是调用 `api.php?action=cron_sync`
- `config.php`：当前唯一配置文件
- `lib/bootstrap.php`：宿主站接入、本地订单库、余额扣退、权限判断
- `lib/client.php`：请求插件网关并负责签名
- `public/app/`：前端静态资源
- `data/`：本地 SQLite 数据目录

## 配置项

源码里的 `config.php` 当前包含这些配置：

- `plugin_name`
- `gateway_base_url`
- `site_id`
- `app_key`
- `app_secret`
- `site_name`
- `db_path`
- `host_root`
- `host_common_path`
- `cron_token`

其中必填项：

- `gateway_base_url`
- `site_id`
- `app_key`
- `app_secret`

常用项：

- `plugin_name`：页面标题
- `site_name`：站点展示名；如果宿主站 `conf['sitename']` 存在，会优先使用宿主站名称
- `db_path`：本地 SQLite 路径
- `host_root`：宿主站根目录
- `host_common_path`：宿主站 `common.php` 真实路径
- `cron_token`：HTTP 触发同步时的访问令牌

补充说明：

- 插件当前没有 `config.local.php`
- 源码支持环境变量覆盖 `config.php`，例如 `PLUGIN_GATEWAY_BASE_URL`、`PLUGIN_SITE_ID`、`PLUGIN_APP_KEY`

## 宿主站要求

插件不是纯静态页面插件，当前源码要求宿主站至少满足：

- PHP 环境可用
- PHP 已启用 `curl`、`sqlite3`
- 宿主站可提供登录态变量 `$islogin` 与 `$userrow`
- 宿主站 `common.php` 可被插件加载
- 宿主站数据库对象 `$DB` 可用
- 宿主站存在用户余额表 `qingka_wangke_user`
- 宿主站支持 `wlog()` 时，插件会调用它记录扣款/退款日志

## 宿主站自动发现

插件会从插件目录开始向上最多搜索 5 层，按下面顺序查找宿主站入口：

1. `confing/common.php`
2. `common.php`
3. `db/common.php`

只有自动搜索不到时，才需要手动填写：

- `host_root`
- 或 `host_common_path`

## 同步方式

优先使用命令行：

```bash
php 插件目录/cron.php
```

如果只能用 URL：

```text
/插件目录/api.php?action=cron_sync&cron_token=你设置的token
```

说明：

- CLI 下执行 `cron.php` 不需要 `cron_token`
- HTTP 方式下，只有当 `config.php` 中设置了 `cron_token` 且请求携带正确 `cron_token` 时才允许执行

## 当前接口动作

`api.php` 当前主要动作包括：

- `bootstrap`
- `schools`
- `platforms`
- `prices`
- `host_balance`
- `host_profile`
- `order_query_link_current`
- `order_query_link_issue`
- `orders`
- `order_status`
- `create_order`
- `refund_order`
- `tickets`
- `ticket_detail`
- `create_ticket`
- `reply_ticket`
- `cron_sync`

## 当前行为

- 前端资源已内置，不需要单独部署 Vue 源码
- 下单前会先向网关实时询价
- 下单成功后会先扣宿主站用户余额，再把订单写入本地 SQLite
- 退款成功后会按网关返回结果回退宿主站用户余额
- 普通用户只能看自己的本地订单
- 管理员 `uid=1` 可以查看当前 `site_id` 下全部本地订单
- 订单列表会优先合并本地订单与远端快照
- 远端历史订单如果当时没有成功落入本地 SQLite，不会自动补出本地保存过的密码字段
