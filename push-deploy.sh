#!/bin/bash
# TP-HR Deploy Script
# Usage: ./push-deploy.sh ["commit message"]
# - With message: git commit + push + deploy
# - Without message: deploy only (no git)

set -e
cd "$(dirname "$0")"

SERVER="root@crm.tp-asset.com"
REMOTE_DIR="/var/www/vhosts/tp-asset.com/hr.tp-asset.com"
MESSAGE="$1"

# ── Build Tailwind CSS ──
echo "🎨 Building Tailwind CSS..."
npx tailwindcss -i assets/css/input.css -o assets/css/app.css --minify 2>&1 | tail -1

# ── Git (optional) ──
if [ -n "$MESSAGE" ]; then
    echo "📦 Committing: $MESSAGE"
    git add -A
    git commit -m "$MESSAGE" || echo "  (nothing to commit)"
    echo "⬆️  Pushing to GitHub..."
    git push
fi

# ── Deploy via rsync ──
echo "🚀 Deploying to $SERVER..."
rsync -avz --delete \
    --exclude='.env' \
    --exclude='.git/' \
    --exclude='uploads' \
    --exclude='storage/uploads/' \
    --exclude='storage/documents/' \
    --exclude='storage/temp/' \
    --exclude='storage/logs/' \
    --exclude='logs/' \
    --exclude='_work/' \
    --exclude='.DS_Store' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='tailwind.config.js' \
    --exclude='assets/css/input.css' \
    ./ "$SERVER:$REMOTE_DIR/"

# ── Fix permissions ──
ssh "$SERVER" "chown -R tpasset:psacln $REMOTE_DIR && echo '[$(date)] Deployed: ${MESSAGE:-manual}' >> $REMOTE_DIR/storage/logs/deploy.log"

echo "✅ Done! https://hr.tp-asset.com"
