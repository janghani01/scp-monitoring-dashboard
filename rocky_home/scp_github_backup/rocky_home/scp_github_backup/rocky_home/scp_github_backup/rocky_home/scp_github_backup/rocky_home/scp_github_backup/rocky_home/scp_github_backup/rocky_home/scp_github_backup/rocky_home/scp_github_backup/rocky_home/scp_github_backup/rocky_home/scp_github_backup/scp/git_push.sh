#!/bin/bash
BACKUP_DIR="/home/rocky/scp_github_backup"

# ① 세 곳의 폴더에서 최신 파일들을 백업 폴더로 복사 (rsync 동기화)
# html 폴더 복사
rsync -a --exclude="*.swp" /var/www/html/ $BACKUP_DIR/html/
# scp 폴더 복사 (db, json, sh 등)
rsync -a /opt/scp/ $BACKUP_DIR/scp/
# rocky 홈 폴더 복사 (용량이 클 수 있으니 숨김파일 제외)
rsync -a --exclude=".*" /home/rocky/ $BACKUP_DIR/rocky_home/

cd $BACKUP_DIR

# ② 깃허브에서 직접 수정한 내역(README 등)이 있으면 꼬이지 않게 먼저 가져오기
git pull origin main --rebase

# ③ 변경된 모든 파일 깃허브로 깔끔하게 업로드
git add .
git commit -m "🚀 Auto Backup: $(date +'%Y-%m-%d %H:%M:%S')"
git push origin main
