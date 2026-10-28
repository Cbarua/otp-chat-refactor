#!/usr/bin/env bash
# ==============================================================================
# Multi-Instance Deployment Script for Amazon Linux 2023 / Apache
# Updates all app instances located two levels deep in /var/www/html/*/*/
# ==============================================================================

set -e

ROOT_DIR="/var/www/html"

echo "======================================================================"
echo " Starting Multi-Instance Deployment: $(date)"
echo " Base Directory: ${ROOT_DIR}"
echo "======================================================================"

updated_count=0

for dir in "${ROOT_DIR}"/*/*; do
    # Ensure it is a valid directory containing a git repository
    if [ -d "$dir" ] && [ -d "$dir/.git" ]; then
        rel_path="${dir#"${ROOT_DIR}/"}"

        echo ""
        echo "----------------------------------------------------------------------"
        echo " [UPDATING] ${rel_path}"
        echo "----------------------------------------------------------------------"

        cd "$dir"

        # 1. Fetch latest changes from remote
        echo " -> Fetching latest code from git..."
        TARGET_BRANCH="${TARGET_BRANCH:-main}"
        git fetch origin "$TARGET_BRANCH" --prune
        git reset --hard "origin/$TARGET_BRANCH"

        # 2. Run production composer install
        echo " -> Installing production dependencies..."
        composer install --no-dev --optimize-autoloader --no-interaction

        # 3. Ensure proper directory & log permissions (SGID ec2-user:apache)
        echo " -> Enforcing permissions on logs/..."
        mkdir -p logs/app logs/capi
        chmod -R 2775 logs 2>/dev/null || true
        chown -R ec2-user:apache logs 2>/dev/null || true


        if [ -f logs/userlog.sqlite ]; then
            chmod 664 logs/userlog.sqlite 2>/dev/null || true
            chown ec2-user:apache logs/userlog.sqlite 2>/dev/null || true
        fi

        ((updated_count++)) || true
    fi
done

echo ""
echo "======================================================================"
echo " Reloading Apache Web Server..."
echo "======================================================================"
if command -v systemctl >/dev/null 2>&1; then
    sudo systemctl reload httpd || sudo systemctl restart httpd
fi

echo ""
echo "======================================================================"
echo " Deployment Summary"
echo "  - Total Instances Updated: ${updated_count}"
echo "  - Timestamp: $(date)"
echo "======================================================================"
