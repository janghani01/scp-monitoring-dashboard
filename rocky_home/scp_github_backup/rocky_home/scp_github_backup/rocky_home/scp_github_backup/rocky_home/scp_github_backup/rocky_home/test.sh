#!/bin/bash

# =================================================================
# SCP v2 Security Group & Rules 추출 스크립트 (CLI Ver.)
# =================================================================

SCP_CLI="/usr/local/bin/scp-cli"
OUTPUT_DIR="$HOME/scp_output"

mkdir -p "$OUTPUT_DIR"

SG_LIST_FILE="${OUTPUT_DIR}/security_groups.json"
SG_RULES_FILE="${OUTPUT_DIR}/security_group_rules.json"
SG_RULES_CSV="${OUTPUT_DIR}/security_group_rules.csv"

echo "[+] SCP CLI를 이용하여 Security Group 목록을 조회합니다..."

# 1. Security Group 목록 수집 (JSON 포맷)
SG_RAW=$(sudo $SCP_CLI security-group security-group list -f json 2>/dev/null)

if [ -z "$SG_RAW" ] || ! echo "$SG_RAW" | jq -e . >/dev/null 2>&1; then
    echo "[-] Security Group 목록 조회에 실패했거나 올바른 JSON 응답이 아닙니다."
    echo "[-] CLI 응답 내용:"
    echo "$SG_RAW"
    exit 1
fi

echo "$SG_RAW" > "$SG_LIST_FILE"
echo "[+] Security Group 목록 저장 완료: $SG_LIST_FILE"

# 2. Security Group ID 목록 추출
SG_IDS=$(echo "$SG_RAW" | jq -r '.[]? .securityGroupId // .[]? .id // .contents[]? .securityGroupId // empty')

if [ -z "$SG_IDS" ]; then
    echo "[!] 조회된 Security Group이 없습니다."
    exit 0
fi

# 3. CSV 및 JSON 결과 구조 초기화
echo "SecurityGroupId,SecurityGroupName,RuleId,Direction,Protocol,Port,RemoteIp,Description" > "$SG_RULES_CSV"
echo "[]" > "$SG_RULES_FILE"

echo "[+] 각 Security Group별 규칙(Rules) 수집을 시작합니다..."

for sg_id in $SG_IDS; do
    # SG 이름 추출
    sg_name=$(echo "$SG_RAW" | jq -r --arg id "$sg_id" '(.[]? // .contents[]?) | select((.securityGroupId==$id) or (.id==$id)) | .securityGroupName // .name')
    
    # 해당 SG의 Rule 목록 조회 (--security-group-id 파라미터 적용)
    RULE_RAW=$(sudo $SCP_CLI security-group security-group-rule list --security-group-id "$sg_id" -f json 2>/dev/null)
    
    if [ -n "$RULE_RAW" ] && echo "$RULE_RAW" | jq -e . >/dev/null 2>&1; then
        # JSON 통합 저장
        jq -s '.[0] + (.[1] | map(. + {securityGroupId: "'"$sg_id"'", securityGroupName: "'"$sg_name"'}"))' \
            "$SG_RULES_FILE" <(echo "$RULE_RAW") > "${OUTPUT_DIR}/temp_rules.json" 2>/dev/null
        
        if [ -s "${OUTPUT_DIR}/temp_rules.json" ]; then
            mv "${OUTPUT_DIR}/temp_rules.json" "$SG_RULES_FILE"
        fi

        # CSV 파싱 후 저장
        echo "$RULE_RAW" | jq -r --arg sg_id "$sg_id" --arg sg_name "$sg_name" \
            '(.[]? // .contents[]?) | [$sg_id, $sg_name, .ruleId, .direction, .serviceType, .serviceValue, .remoteIpPrefix, .description] | @csv' \
            >> "$SG_RULES_CSV" 2>/dev/null
    fi
done

echo ""
echo "[+] Security Group & Rules 추출 완료!"
echo "  - Security Group 목록: $SG_LIST_FILE"
echo "  - 규칙 목록 (JSON):   $SG_RULES_FILE"
echo "  - 규칙 목록 (CSV):    $SG_RULES_CSV"
