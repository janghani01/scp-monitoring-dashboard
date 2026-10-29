#!/bin/bash

echo "{" > /opt/scp/os_info.json
FIRST=1

SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=2"

# servers.json에서 서버명과 IP를 같이 추출 (형식: 서버명|IP)
jq -r '.servers[] | "\(.name)|\(.addresses[0].ip_addresses[0].ip_address // "")"' /opt/scp/servers.json 2>/dev/null | while IFS='|' read -r SERVER_NAME IP; do
    if [ -z "$IP" ] || [ "$IP" == "null" ]; then continue; fi

    OS_NAME="UNREACHABLE"

    # 💡 1. 서버명에 'win'이 포함되어 있으면 즉시 Windows로 설정
    if echo "$SERVER_NAME" | grep -iq "win"; then
        OS_NAME="Windows"
    else
        # 2. 리눅스 서버 SSH 접속 시도
        RESULT=$(ssh $SSH_OPTS -i /opt/scp/works_mgmt.pem rocky@$IP "cat /etc/os-release" 2>/dev/null)

        if [ -z "$RESULT" ]; then
            RESULT=$(ssh $SSH_OPTS -i /opt/scp/rocky.pem rocky@$IP "cat /etc/os-release" 2>/dev/null)
        fi

        if [ -z "$RESULT" ]; then
            RESULT=$(ssh $SSH_OPTS -i /opt/scp/rocky.pem ubuntu@$IP "cat /etc/os-release" 2>/dev/null)
        fi

        # OS 결과 판별
        if echo "$RESULT" | grep -iq "Rocky"; then
            OS_NAME="Rocky Linux"
        elif echo "$RESULT" | grep -iq "Ubuntu"; then
            OS_NAME="Ubuntu"
        elif echo "$RESULT" | grep -iq "CentOS"; then
            OS_NAME="CentOS"
        fi
    fi

    if [ $FIRST -eq 1 ]; then
        FIRST=0
    else
        echo "," >> /opt/scp/os_info.json
    fi

    echo "  \"$IP\": {\"os\": \"$OS_NAME\"}" >> /opt/scp/os_info.json
done

echo "}" >> /opt/scp/os_info.json
chmod 666 /opt/scp/os_info.json
chown apache:apache /opt/scp/os_info.json
echo "OS 스캔 완료!"
