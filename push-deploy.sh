#!/bin/bash
# Push and Deploy script for tp-hr
# Usage: ./push-deploy.sh "commit message"

cd "$(dirname "$0")"

MESSAGE="${1:-Update}"

echo "📦 Committing changes..."
git add -A
git commit -m "$MESSAGE"

echo "⬆️  Pushing to GitHub..."
git push

echo "🚀 Deploying to server..."
SERVER="root@crm.tp-asset.com"
REMOTE_DIR="/var/www/vhosts/tp-asset.com/hr.tp-asset.com"
REPO="sakonwanth/tp-hr"

# Make repo temporarily public
gh repo edit $REPO --visibility public --accept-visibility-change-consequences 2>/dev/null

# Deploy to server
ssh $SERVER "cd $REMOTE_DIR && curl -sL https://github.com/$REPO/archive/refs/heads/main.tar.gz | tar -xzf - --strip-components=1 && chown -R tpasset:psacln . && echo '[$(date)] Deployed: $MESSAGE' >> storage/logs/deploy.log"

# Make repo private again  
gh repo edit $REPO --visibility private --accept-visibility-change-consequences 2>/dev/null

echo "✅ Done! Changes are live at https://hr.tp-asset.com"
