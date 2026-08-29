# 二九对接站插件

这套插件的交付方式已经按“杀手 / 闪电”那种模式整理成可直接部署的目录包。

## 部署方式

1. 把整个 `php-plugin` 目录上传到目标站点根目录。
2. 将目录名改成站长想使用的插件目录名，例如 `plugin-station`。
3. 复制 `config.local.example.php` 为 `config.local.php`。
4. 修改 `config.local.php` 里的网关地址、`site_id`、`app_key`、`app_secret`。
5. 如果插件目录不在站点根目录，再补 `host_root` 或 `host_common_path`。
6. 确保插件目录下 `data/` 可写。
7. 浏览器访问：

```text
/插件目录/
```

或：

```text
/插件目录/index/plugin.php
```

## 目录说明

- `index.php`：根入口，自动跳到单页。
- `index/plugin.php`：前端单页入口。
- `api.php`：插件统一接口入口。
- `cron.php`：定时同步入口。
- `config.php`：默认配置。
- `config.local.php`：站长自己改的本地配置文件。
- `lib/bootstrap.php`：宿主站登录态、订单本地库、权限判断。
- `lib/client.php`：请求对接站网关。
- `public/app/`：前端静态资源。

## 必改配置

- `gateway_base_url`：你的对接站网关地址。
- `site_id`：站点编号。
- `app_key`：插件调用 key。
- `app_secret`：插件调用 secret。

## 可选配置

- `plugin_name`：插件标题。
- `site_name`：当前站点展示名称。
- `db_path`：插件本地 SQLite 路径。
- `host_root`：宿主站根目录。
- `host_common_path`：宿主站 `confing/common.php` 完整路径。
- `cron_token`：HTTP 触发同步时的访问令牌。

## 宿主站要求

- 目标站必须存在 `confing/common.php`。
- 目标站登录后需要能在 `common.php` 里生成 `$islogin` 和 `$userrow`。
- PHP 需支持 `curl`、`sqlite3`。

## 定时同步

优先用命令行：

```bash
php 插件目录/cron.php
```

如果只能用 URL：

```text
/插件目录/api.php?action=cron_sync&cron_token=你设置的token
```

## 当前行为

- 已支持像杀手/闪电一样“上传目录 -> 改配置 -> 访问入口”。
- 已按宿主站登录用户隔离本地订单，普通用户只能看自己的单。
- 管理员 `uid=1` 可以查看全部本地订单。
- 退款和状态查询都带本地订单归属校验。
