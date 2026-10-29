#!/bin/bash

# 1. 엑셀 파일명 및 한글 깨짐 방지(BOM) 설정
OUTPUT_FILE="ntp_sync_report_$(date +%Y%m%d_%H%M).csv"
printf '\xEF\xBB\xBF' > "$OUTPUT_FILE"
echo '"서버명","IP","접속상태","NTP_설정여부","시간_동기화상태","현재_서버시간","오차(기준서버 대비)"' >> "$OUTPUT_FILE"

# 접속 환경 세팅
PEM_KEY="works_mgmt.pem" 
SSH_USER="rocky" 

echo "⏳ 1단계: /opt/scp/servers.json 에서 대상 서버 목록을 가져옵니다..."

# 파이썬 파싱 구문 (서버 목록 추출)
python3 -c '
import json
try:
    with open("/opt/scp/servers.json", "r") as f:
        for s in json.load(f).get("servers", []):
            try:
                ip = s.get("addresses")[0].get("ip_addresses")[0].get("ip_address", "")
            except:
                ip = ""
            if ip:
                name = s.get("name", "-")
                print(name + "," + ip)
except Exception as e:
    pass
' > /tmp/target_servers.txt

TOTAL=$(cat /tmp/target_servers.txt | wc -l)
if [ "$TOTAL" -eq 0 ]; then
    echo "❌ 대상 서버 목록을 불러오지 못했습니다. servers.json 파일 위치를 확인해주세요."
    exit 1
fi

echo "✅ 총 ${TOTAL}대의 서버를 점검합니다. (SSH 접속 시도 중...)"

# 기준이 될 현재 관리 서버의 시간 (Epoch 초 단위)
MGMT_TIME=$(date +%s)

# 2. 각 서버에 SSH로 접속하여 NTP 상태 조회
while IFS=',' read -r name ip; do
    if [ -z "$ip" ]; then continue; fi

    echo -n "  -> 점검 중: $name ($ip) ... "

    # 💡 [핵심 해결] ssh에 -n 옵션을 추가하여 반복문이 중간에 끊기지 않도록 수정!
    SSH_CMD="timedatectl show -p NTP -p NTPSynchronized 2>/dev/null; date +%s; date +'%Y-%m-%d %H:%M:%S'"
    RESULT=$(ssh -n -i "$PEM_KEY" -o ConnectTimeout=3 -o StrictHostKeyChecking=no "$SSH_USER@$ip" "$SSH_CMD" 2>/dev/null)

    if [ -z "$RESULT" ]; then
        echo "❌ 접속 실패 (키/네트워크 확인 필요)"
        echo "\"$name\",\"$ip\",\"접속실패\",\"-\",\"-\",\"-\",\"-\"" >> "$OUTPUT_FILE"
        continue
    fi

    # 데이터 파싱
    NTP_ON=$(echo "$RESULT" | grep "^NTP=" | cut -d= -f2)
    SYNCED=$(echo "$RESULT" | grep "^NTPSynchronized=" | cut -d= -f2)
    TARGET_EPOCH=$(echo "$RESULT" | sed -n '3p')
    TARGET_TIME=$(echo "$RESULT" | sed -n '4p')

    # 예외 처리: 데이터가 비어있을 경우
    [ -z "$NTP_ON" ] && NTP_ON="확인불가"
    [ -z "$SYNCED" ] && SYNCED="확인불가"

    # 오차 계산 (초 단위 절대값)
    if [[ "$TARGET_EPOCH" =~ ^[0-9]+$ ]]; then
        DIFF=$(( TARGET_EPOCH - MGMT_TIME ))
        [ $DIFF -lt 0 ] && DIFF=$(( -DIFF ))
        DIFF_STR="${DIFF}초"
    else
        DIFF_STR="계산불가"
    fi

    echo "✅ 완료 (오차: $DIFF_STR)"
    echo "\"$name\",\"$ip\",\"정상\",\"$NTP_ON\",\"$SYNCED\",\"$TARGET_TIME\",\"$DIFF_STR\"" >> "$OUTPUT_FILE"

done < /tmp/target_servers.txt
rm -f /tmp/target_servers.txt

echo "======================================================"
echo "✅ NTP 동기화 점검 완료! 엑셀 파일이 생성되었습니다."
echo "📂 파일명: $(pwd)/$OUTPUT_FILE"
echo "======================================================"
