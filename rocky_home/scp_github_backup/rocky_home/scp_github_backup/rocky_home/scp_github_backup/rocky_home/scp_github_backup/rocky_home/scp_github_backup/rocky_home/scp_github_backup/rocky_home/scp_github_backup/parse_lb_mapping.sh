#!/bin/bash

OUTPUT_FILE="/opt/scp/scp_lb_member_mapping.csv"
TMP_OUTPUT="/opt/scp/scp_lb_member_mapping.tmp"

echo "LB_NAME,LB_TYPE,SG_NAME,MEMBER_NAME,MEMBER_IP,PORT" > $TMP_OUTPUT

echo "[1/3] Load Balancer 전체 목록 캐싱 중 (CPU 부하 최소화)..."
# 💡 [핵심 최적화] 개별 조회 대신 전체를 한 번만 조회해서 메모리에 들고 있습니다.
ALL_LBS=$(scp-cli loadbalancer loadbalancer list -f json < /dev/null 2>/dev/null)

echo "[2/3] 전체 서버 그룹 목록 조회 중..."
SG_LIST=$(scp-cli loadbalancer lb-server-group list -f json < /dev/null 2>/dev/null)

if [ -z "$SG_LIST" ] || [ "$SG_LIST" = "[]" ]; then
    echo "[오류] 서버 그룹 목록을 불러오지 못했습니다."
    exit 1
fi

TMP_SG=$(mktemp)
echo "$SG_LIST" | jq -c '.[]' > "$TMP_SG"
TOTAL_COUNT=$(wc -l < "$TMP_SG")
CURRENT=0

echo "[3/3] 매핑 데이터 추출 시작 (총 $TOTAL_COUNT 개)..."

while read -r sg; do
    CURRENT=$((CURRENT+1))
    SG_ID=$(echo "$sg" | jq -r '.id // empty')
    SG_NAME=$(echo "$sg" | jq -r '.name // .server_group_name // "-"')
    LB_ID=$(echo "$sg" | jq -r '.load_balancer_id // .loadbalancer_id // empty')

    [ -z "$SG_ID" ] && continue

    LB_NAME="UNKNOWN"
    LB_TYPE="-"
    if [ -n "$LB_ID" ] && [ "$LB_ID" != "null" ]; then
        # 💡 도커 컨테이너를 새로 띄우지 않고, 1번에서 미리 가져온 데이터에서 찾습니다.
        LB_INFO=$(echo "$ALL_LBS" | jq -r --arg id "$LB_ID" '.[]? | select(.id == $id)')
        if [ -n "$LB_INFO" ]; then
            LB_NAME=$(echo "$LB_INFO" | jq -r '.name // "UNKNOWN"')
            LB_TYPE=$(echo "$LB_INFO" | jq -r '.layer_type // "-"')
        fi
    fi

    echo " -> [$CURRENT/$TOTAL_COUNT] $SG_NAME (LB: $LB_NAME) 처리 중..."

    PORT="-"
    if [ -n "$LB_ID" ] && [ "$LB_ID" != "null" ]; then
        LS_INFO=$(scp-cli loadbalancer lb-listener list --loadbalancer_id "$LB_ID" -f json < /dev/null 2>/dev/null)
        if [ -n "$LS_INFO" ] && [ "$LS_INFO" != "[]" ]; then
            PORT=$(echo "$LS_INFO" | jq -r --arg sgid "$SG_ID" '.[]? | select(.lb_server_group_id == $sgid or .server_group_id == $sgid) | .service_port // .port' | head -n 1)
            [ -z "$PORT" ] || [ "$PORT" = "null" ] && PORT="-"
        fi
    fi

    MEMBERS=$(scp-cli loadbalancer lb-server-group-member list --lb_server_group_id "$SG_ID" -f json < /dev/null 2>/dev/null)

    if [ -n "$MEMBERS" ] && [ "$MEMBERS" != "[]" ]; then
        TMP_MEM=$(mktemp)
        echo "$MEMBERS" | jq -c '.[]' > "$TMP_MEM"

        while read -r m; do
            M_NAME=$(echo "$m" | jq -r '.name // .server_name // "-"')
            M_IP=$(echo "$m" | jq -r '.member_ip // .ip_address // "-"' )
            M_PORT=$(echo "$m" | jq -r '.member_port // empty')

            [ -n "$M_PORT" ] && [ "$M_PORT" != "null" ] && USE_PORT="$M_PORT" || USE_PORT="$PORT"

            echo "\"$LB_NAME\",\"$LB_TYPE\",\"$SG_NAME\",\"$M_NAME\",\"$M_IP\",\"$USE_PORT\"" >> $TMP_OUTPUT
        done < "$TMP_MEM"
        rm -f "$TMP_MEM"
    else
        echo "\"$LB_NAME\",\"$LB_TYPE\",\"$SG_NAME\",\"-\",\"-\",\"$PORT\"" >> $TMP_OUTPUT
    fi
    
    # 💡 [핵심 방어코드] 도커 데몬 기절 및 CPU 100% 마비를 막기 위한 0.5초 휴식
    sleep 0.5
done < "$TMP_SG"

rm -f "$TMP_SG"

mv $TMP_OUTPUT $OUTPUT_FILE
chmod 666 $OUTPUT_FILE

echo "--------------------------------------------------"
echo "✓ 추출 완료! ($TOTAL_COUNT 개 모두 처리됨)"
echo "--------------------------------------------------"
