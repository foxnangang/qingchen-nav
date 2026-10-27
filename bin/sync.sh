#!/usr/bin/env bash
# ============================================================================
#  卿辰导航 · 一键同步到 GitHub（私有仓库）
#
#  用法：
#    ./bin/sync.sh                 自动生成提交信息（取当前时间 + 改动文件概览）
#    ./bin/sync.sh "fix: xxx"      指定提交信息
#
#  做的事：
#    1. 检查是否在 git 仓库里、有没有未提交改动
#    2. git add -A + commit
#    3. push，失败自动重试 3 次（本机到 GitHub 偶发 SSL EOF）
#    4. 仍然失败时打印手动兜底方案（Git Data API），不静默失败
#
#  ⚠️ 约定：改完代码就跑一次，别攒着 —— 攒多了冲突难解，也容易忘。
# ============================================================================
set -uo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR" || { echo "❌ 进不去仓库目录"; exit 1; }

BRANCH="${QCNav_BRANCH:-main}"
MSG="${1:-}"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "❌ 这里不是 git 仓库：$REPO_DIR"
  exit 1
fi

# ---- 1. 有无改动 ----
if [ -z "$(git status --porcelain)" ]; then
  # 工作区干净，但可能有未推送的提交
  AHEAD="$(git rev-list --count "origin/$BRANCH..HEAD" 2>/dev/null || echo 0)"
  if [ "$AHEAD" = "0" ]; then
    echo "✅ 工作区干净，也没有待推送的提交，无需同步"
    exit 0
  fi
  echo "ℹ️  工作区干净，但有 $AHEAD 个提交没推，直接推"
else
  # ---- 2. 生成提交信息 ----
  if [ -z "$MSG" ]; then
    FILES="$(git status --porcelain | awk '{print $2}' | head -6 | tr '\n' ' ')"
    MSG="chore: 同步 $(date '+%Y-%m-%d %H:%M') 的改动（$FILES）"
  fi
  git add -A || { echo "❌ git add 失败"; exit 1; }
  git -c user.name="foxnangang" \
      -c user.email="foxnangang@users.noreply.github.com" \
      commit -q -m "$MSG" || { echo "❌ git commit 失败"; exit 1; }
  echo "✅ 已提交：$MSG"
fi

# ---- 3. 推送（最多 3 次）----
for i in 1 2 3; do
  echo "→ 推送第 $i 次…"
  if timeout 90 git push origin "$BRANCH" 2>&1 | tail -3; then
    if git rev-list --count "origin/$BRANCH..HEAD" 2>/dev/null | grep -qx 0; then
      echo "✅ 已同步到 GitHub（$BRANCH）"
      exit 0
    fi
  fi
  sleep 3
done

# ---- 4. 兜底提示 ----
cat <<EOF
❌ 推送三次都失败。本机到 GitHub 偶发 SSL EOF，可以：

  1) 重跑一次：./bin/sync.sh
  2) 看网络：curl -sI https://github.com | head -1
  3) 走 Git Data API（blobs→trees→commits→PATCH refs）：
     gh api repos/foxnangang/qingchen-nav/git/refs/heads/$BRANCH

未推送的提交仍在本地，别慌，网络恢复后重跑即可。
EOF
exit 1
