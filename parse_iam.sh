#!/bin/bash

OUTPUT_FILE="/opt/scp/iam_current.json"
ACCOUNT_ID="9385572a5f534b8590f6c9c0c9eb408a"

echo "🔑 IAM 사용자 목록 조회 중 (Account ID: $ACCOUNT_ID) ..."

# 💡 관리자님이 찾아주신 정확한 명령어와 파라미터 적용 (JSON 포맷으로 1000개까지 넉넉히 조회)
IAM_DATA=$(sudo /usr/local/bin/scp-cli iam iam user list --account_id "$ACCOUNT_ID" --size 1000 -f json 2>&1)

# 추출한 데이터가 대괄호 [ 나 중괄호 { 로 시작하는 정상적인 JSON인지 검사
if [[ "$IAM_DATA" != [* && "$IAM_DATA" != {* ]]; then
    echo "❌ [오류] 올바른 데이터를 가져오지 못했습니다. CLI 응답 원본:"
    echo "$IAM_DATA"
    echo "[]" > $OUTPUT_FILE
    chmod 666 $OUTPUT_FILE
    exit 1
fi

# 정상적인 JSON 데이터일 경우 파일 덮어쓰기
echo "$IAM_DATA" > $OUTPUT_FILE
chmod 666 $OUTPUT_FILE

echo "--------------------------------------------------"
echo "✓ IAM 데이터 추출 완료 및 저장됨: $OUTPUT_FILE"
echo "--------------------------------------------------"
