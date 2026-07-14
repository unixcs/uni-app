# Tencent SSH 密钥人工轮换

适用主机：腾讯云 YoShop B 环境 `124.222.144.181`。

本指南只描述人工步骤，不执行换钥。目标是把目前可能共用的访问材料拆成两把独立
ED25519 密钥：

- **ops-admin key**：紧急和系统管理；默认用于现有 `root` 管理路径。
- **deployer key**：仅供 `deployer` 发布账号使用。

两把私钥不得复用、互拷或提交到 Git。轮换必须遵循“先加后删”，并在整个窗口保留一条
使用旧密钥建立的管理员会话。

## 1. 前置条件与停止条件

开始前确认：

- 腾讯云控制台登录和主机救援/VNC 路径可用。
- 已获当次 SSH 授权变更批准，且有人负责回退。
- 本地在可信工作站操作，磁盘加密可用，`~/.ssh` 权限正确。
- 知道当前旧公钥的 SHA-256 指纹；不要用注释或文件名代替指纹。
- 当前 `deployer` 的 sudo 仍只允许 `/usr/local/sbin/yoshop-release`。
- 业务发布、SSH 轮换和“禁止 root 登录”是三个独立变更；本次只做已批准项。

遇到以下任一情况立即停止，不删除旧钥：

- 无法保持旧管理员会话在线；
- 新 ops-admin 登录失败；
- 新 deployer 登录失败或 sudo 边界与预期不符；
- `sshd -t` 失败；
- 文件 owner/mode 不符合本文检查；
- 控制台救援路径不可用。

## 2. 在可信工作站生成两把新钥

以下命令由用户在维护窗口手工运行；将日期替换为实际轮换日。为两把私钥设置不同的强
passphrase，不要把 passphrase 写进 shell、文档或密码管理器备注之外的明文位置。

```bash
umask 077
install -d -m 0700 "$HOME/.ssh"

ADMIN_KEY="$HOME/.ssh/yoshop_ops_admin_YYYYMMDD"
DEPLOY_KEY="$HOME/.ssh/yoshop_deployer_YYYYMMDD"

ssh-keygen -t ed25519 -a 100 -f "$ADMIN_KEY" \
  -C 'yoshop-ops-admin-YYYYMMDD'
ssh-keygen -t ed25519 -a 100 -f "$DEPLOY_KEY" \
  -C 'yoshop-deployer-YYYYMMDD'

chmod 0600 "$ADMIN_KEY" "$DEPLOY_KEY"
chmod 0644 "${ADMIN_KEY}.pub" "${DEPLOY_KEY}.pub"
ssh-keygen -lf "${ADMIN_KEY}.pub" -E sha256
ssh-keygen -lf "${DEPLOY_KEY}.pub" -E sha256
```

检查两条指纹不同，并在受控变更记录中只保存用途、日期和指纹。不得记录私钥内容。

## 3. 为每个身份固定 SSH 选择

在本地 `~/.ssh/config` 新增独立别名。`IdentitiesOnly yes` 是强制项，避免 ssh-agent
尝试错误密钥或把管理员密钥用于 deployer。

```sshconfig
Host yoshop-ops-admin-new
    HostName 124.222.144.181
    User root
    IdentityFile ~/.ssh/yoshop_ops_admin_YYYYMMDD
    IdentitiesOnly yes
    PreferredAuthentications publickey

Host yoshop-deployer-new
    HostName 124.222.144.181
    User deployer
    IdentityFile ~/.ssh/yoshop_deployer_YYYYMMDD
    IdentitiesOnly yes
    PreferredAuthentications publickey
```

验证本地配置展开结果：

```bash
chmod 0600 "$HOME/.ssh/config"
ssh -G yoshop-ops-admin-new | \
  grep -Ei '^(hostname|user|identityfile|identitiesonly) '
ssh -G yoshop-deployer-new | \
  grep -Ei '^(hostname|user|identityfile|identitiesonly) '
```

预期两个 alias 使用不同 `IdentityFile`，且 `identitiesonly yes`。

## 4. 建立回退会话并备份授权文件

用**旧密钥**打开 Session A；在整个轮换窗口保持它在线。不要在 Session A 中运行
`exit`，也不要重启主机。

```bash
OLD_KEY="$HOME/.ssh/current_tencent_key"  # 人工改成当前旧私钥路径
ssh -o IdentitiesOnly=yes -i "$OLD_KEY" root@124.222.144.181
```

在 Session A 中确认身份并创建只读回退副本：

```bash
set -eu
id
stamp=$(date -u +%Y%m%dT%H%M%SZ)

install -d -o root -g root -m 0700 /root/.ssh
touch /root/.ssh/authorized_keys
chown root:root /root/.ssh/authorized_keys
chmod 0600 /root/.ssh/authorized_keys
cp -a /root/.ssh/authorized_keys \
  "/root/.ssh/authorized_keys.pre-rotation.${stamp}"

install -d -o deployer -g deployer -m 0700 /home/deployer/.ssh
touch /home/deployer/.ssh/authorized_keys
chown deployer:deployer /home/deployer/.ssh/authorized_keys
chmod 0600 /home/deployer/.ssh/authorized_keys
cp -a /home/deployer/.ssh/authorized_keys \
  "/home/deployer/.ssh/authorized_keys.pre-rotation.${stamp}"

sshd -t
```

记住输出的 `stamp`，回退时必须使用本轮副本。

## 5. 先添加两把新公钥

回到本地另一个终端。`ssh-copy-id` 只发送 `.pub` 文件；确认参数没有指向私钥。

```bash
ADMIN_KEY="$HOME/.ssh/yoshop_ops_admin_YYYYMMDD"
DEPLOY_KEY="$HOME/.ssh/yoshop_deployer_YYYYMMDD"
OLD_KEY="$HOME/.ssh/current_tencent_key"

ssh-copy-id -i "${ADMIN_KEY}.pub" \
  -o IdentitiesOnly=yes -o IdentityFile="$OLD_KEY" \
  root@124.222.144.181

ssh-copy-id -i "${DEPLOY_KEY}.pub" \
  -o IdentitiesOnly=yes -o IdentityFile="$OLD_KEY" \
  deployer@124.222.144.181
```

不要删除旧公钥。先在 Session A 中执行精确权限检查：

```bash
chown root:root /root/.ssh /root/.ssh/authorized_keys
chmod 0700 /root/.ssh
chmod 0600 /root/.ssh/authorized_keys

chown deployer:deployer \
  /home/deployer/.ssh /home/deployer/.ssh/authorized_keys
chmod 0700 /home/deployer/.ssh
chmod 0600 /home/deployer/.ssh/authorized_keys

stat -c '%U:%G %a %n' \
  /root /root/.ssh /root/.ssh/authorized_keys \
  /home/deployer /home/deployer/.ssh \
  /home/deployer/.ssh/authorized_keys
namei -l /root/.ssh/authorized_keys
namei -l /home/deployer/.ssh/authorized_keys
ssh-keygen -lf /root/.ssh/authorized_keys -E sha256
ssh-keygen -lf /home/deployer/.ssh/authorized_keys -E sha256
sshd -t
```

必须看到：

- `/root/.ssh` 为 `root:root 700`，其 `authorized_keys` 为 `root:root 600`；
- `/home/deployer/.ssh` 为 `deployer:deployer 700`，其 `authorized_keys` 为
  `deployer:deployer 600`；
- 两个新指纹分别只出现在对应账号文件中；
- `sshd -t` 无输出且退出码为 0。

## 6. 用新钥打开独立会话

保持 Session A 在线。打开 Session B 验证新管理员钥：

```bash
ssh -o IdentitiesOnly=yes yoshop-ops-admin-new
id
```

再打开 Session C 验证新 deployer 钥和受限 sudo：

```bash
ssh -o IdentitiesOnly=yes yoshop-deployer-new
id
sudo -n -l
sudo -n /usr/local/sbin/yoshop-release status

if sudo -n /usr/bin/id; then
  printf '%s\n' 'ERROR: deployer sudo is broader than expected' >&2
  exit 1
else
  printf '%s\n' 'expected: arbitrary sudo command denied'
fi
```

通过条件：

- Session B 是 `root`，断开后可再次用新管理员钥建立全新会话；
- Session C 是 `deployer`；release `status` 可执行；
- `/usr/bin/id` 经 sudo 明确失败，不能因 NOPASSWD 获得任意 root 命令；
- 两个 alias 都使用 `IdentitiesOnly yes`，没有回退到旧钥或 agent 中的其他钥。

如需诊断，可在客户端临时加 `-v`，并在 Session A 中查看近期 SSH 日志。不要把包含来源 IP
或账户细节的完整日志粘贴到公开渠道。

## 7. 删除旧公钥

只有第 6 节全部通过后才可继续。按账号分别删除，两个账号之间再做一次新登录验证。

先在本地从旧公钥取得 key blob 和指纹：

```bash
OLD_KEY="$HOME/.ssh/current_tencent_key"
old_blob=$(awk 'NF >= 2 {print $2; exit}' "${OLD_KEY}.pub")
test -n "$old_blob"
ssh-keygen -lf "${OLD_KEY}.pub" -E sha256
printf '%s\n' "$old_blob"
```

`old_blob` 是公钥字段，不是私钥。把它通过受控终端带到 Session A，并先从 root
`authorized_keys` 删除。下面的 `PASTE_OLD_PUBLIC_KEY_BLOB` 必须替换为上一步的完整值：

```bash
old_blob='PASTE_OLD_PUBLIC_KEY_BLOB'
test -n "$old_blob"

awk -v blob="$old_blob" '
  {
    found = 0
    for (i = 1; i <= NF; i++) if ($i == blob) found = 1
    if (!found) print
  }
' /root/.ssh/authorized_keys > /root/.ssh/authorized_keys.new

! grep -Fq "$old_blob" /root/.ssh/authorized_keys.new
install -o root -g root -m 0600 \
  /root/.ssh/authorized_keys.new /root/.ssh/authorized_keys
rm -f /root/.ssh/authorized_keys.new
ssh-keygen -lf /root/.ssh/authorized_keys -E sha256
sshd -t
```

保持 Session A 和 B 在线，另开一个全新的管理员会话。确认成功后，再在 Session A 中对
`deployer` 文件执行同样的按 blob 删除：

```bash
old_blob='PASTE_OLD_PUBLIC_KEY_BLOB'
test -n "$old_blob"

awk -v blob="$old_blob" '
  {
    found = 0
    for (i = 1; i <= NF; i++) if ($i == blob) found = 1
    if (!found) print
  }
' /home/deployer/.ssh/authorized_keys \
  > /home/deployer/.ssh/authorized_keys.new

! grep -Fq "$old_blob" /home/deployer/.ssh/authorized_keys.new
install -o deployer -g deployer -m 0600 \
  /home/deployer/.ssh/authorized_keys.new \
  /home/deployer/.ssh/authorized_keys
rm -f /home/deployer/.ssh/authorized_keys.new
ssh-keygen -lf /home/deployer/.ssh/authorized_keys -E sha256
sshd -t
```

最后重新建立全新的 Session B 和 Session C，并重做受限 sudo 测试。只有两者都通过，才可
关闭旧 Session A。

## 8. 可选：禁止 root SSH 登录

这不是换钥的默认步骤。当前 ops-admin alias 使用 `root`；在存在并验证独立的非 root
管理员路径之前，**不得**设置 `PermitRootLogin no`。

只有满足以下条件才可单独安排：

1. 已按组织策略配置命名管理员账号，例如 `opsadmin`，且使用独立管理员公钥；
2. 已从全新会话登录该账号，并实际通过 `sudo -v` 和 `sudo id -u` 验证管理能力；
3. `sudo id -u` 输出 `0`，退出重连后仍可 sudo；
4. 腾讯云控制台救援路径已现场验证；
5. 一条现有 root Session 仍保持在线，且已获“禁止 root 登录”的独立授权。

满足后才在 root Session 中创建 drop-in：

```bash
cat > /etc/ssh/sshd_config.d/90-disable-root-login.conf <<'SSHD'
PermitRootLogin no
SSHD
chmod 0644 /etc/ssh/sshd_config.d/90-disable-root-login.conf
sshd -t
systemctl reload ssh
```

不要重启 ssh 服务。reload 后，先确认新的 root 登录被拒绝，再从全新 `opsadmin` 会话登录
并完成 sudo 测试；最后才关闭保留的 root Session。若没有命名管理员路径，跳过本节并保留
`PermitRootLogin prohibit-password` 的现有密钥管理方式。

## 9. 回退

### 仍有 Session A 或 Session B

使用第 4 节本轮 `stamp` 的副本恢复。不要用其他日期的文件：

```bash
cp -a "/root/.ssh/authorized_keys.pre-rotation.${stamp}" \
  /root/.ssh/authorized_keys
cp -a "/home/deployer/.ssh/authorized_keys.pre-rotation.${stamp}" \
  /home/deployer/.ssh/authorized_keys

chown root:root /root/.ssh/authorized_keys
chmod 0600 /root/.ssh/authorized_keys
chown deployer:deployer /home/deployer/.ssh/authorized_keys
chmod 0600 /home/deployer/.ssh/authorized_keys
sshd -t
```

恢复后用旧钥建立一个**全新**管理员会话，再调查新钥失败原因。只恢复
`authorized_keys` 不需要 reload sshd。

若失败发生在可选 root-login 禁用步骤：

```bash
rm -f /etc/ssh/sshd_config.d/90-disable-root-login.conf
sshd -t
systemctl reload ssh
```

### 所有 SSH 会话均丢失

停止远程尝试，使用腾讯云控制台救援/VNC。先恢复 root 登录策略和本轮授权文件副本，运行
`sshd -t` 后 reload ssh，再从可信工作站测试。不得通过开启口令登录或临时放宽防火墙来
“快速修复”。

## 10. 收尾

- 再次记录 root、deployer 当前公钥 SHA-256 指纹和轮换时间，不记录私钥。
- 确认旧指纹已从两个 `authorized_keys` 消失，新指纹没有串用。
- 确认发布配置使用 deployer alias/独立 deployer 私钥，不使用管理员私钥。
- 从 ssh-agent 移除旧私钥：`ssh-add -d "$OLD_KEY"`。
- 在维护窗口观察期结束并确认无需回退后，删除服务器上的
  `authorized_keys.pre-rotation.<stamp>` 副本。
- 按组织密钥销毁策略删除或离线封存旧私钥；私钥疑似泄露时必须销毁，不能只改文件名。
- 更新受控资产记录和下一次轮换日期。不要提交/推送密钥、指纹清单或主机访问日志。
