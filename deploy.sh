#!/bin/bash
# TP-HR Auto Deploy Script
# Pulls latest code from GitHub

REPO_DIR="/var/www/vhosts/tp-asset.com/hr.tp-asset.com"
LOG_FILE="$REPO_DIR/storage/logs/deploy.log"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

cd "$REPO_DIR" || exit 1

# Make repo temporarily public, pull, then private
# Using GitHub API with stored token
if [ -f "$REPO_DIR/.github_token" ]; then
    TOKEN=$(cat "$REPO_DIR/.github_token")
    
    # Pull using token
    git -c credential.helper="!f() { echo username=x-access-token; echo password=$TOKEN; }; f" pull origin main 2>&1 | while read line; do
        echo "[$TIMESTAMP] $line" >> "$LOG_FILE"
    done
else
    # Fallback: try regular pull (works if public)
    git pull origin main 2>&1 | while read line; do
        echo "[$TIMESTAMP] $line" >> "$LOG_FILE"
    done
fi

# Fix permissions
chown -R tpasset:psacln "$REPO_DIR" 2>/dev/null

echo "[$TIMESTAMP] Deploy completed" >> "$LOG_FILE"
