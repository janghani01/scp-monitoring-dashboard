#!/usr/bin/env python3
import json, os, urllib.request

WEBHOOK_URL = "https://hooks.slack.com/services/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"
HISTORY_FILE = "/opt/scp/alert_history.json"
STATE_FILE = "/opt/scp/alert_state_count.json"

def send_webhook(message):
    payload = {"text": message}
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(WEBHOOK_URL, data=data, headers={"Content-Type": "application/json"})
    try:
        urllib.request.urlopen(req, timeout=5)
        print("✅ 슬랙 알림 전송 성공!")
    except Exception as e:
        print(f"❌ 웹훅 전송 실패: {e}")

def main():
    if not os.path.exists(HISTORY_FILE):
        print("알림 내역 파일이 없습니다.")
        return

    # 1. 대시보드에 쌓인 전체 알림 내역 읽어오기
    with open(HISTORY_FILE, "r", encoding="utf-8") as f:
        try:
            history_data = json.load(f)
        except:
            history_data = []

    if not history_data:
        return

    # 2. 이전에 슬랙으로 어디까지 보냈는지 카운트 확인
    try:
        with open(STATE_FILE, "r", encoding="utf-8") as f:
            state = json.load(f)
            last_count = state.get("sent_count", 0)
    except:
        last_count = 0

    # (만약 로그 파일이 초기화되어 줄어들었다면 카운트 리셋)
    if last_count > len(history_data):
        last_count = 0

    # 3. 새로 추가된 알림들만 쏙쏙 뽑아내기
    new_alerts = history_data[last_count:]

    if new_alerts:
        print(f"새로운 알림 {len(new_alerts)}건 발견. 슬랙 전송 시작...")
        for alert in new_alerts:
            time_str = alert.get("time", "")
            server = alert.get("server", "Unknown")
            a_type = alert.get("type", "")
            msg = alert.get("message", "")

            # 알림 유형별 맞춤 이모지 아이콘 설정
            icon = "🚨"
            if "과부하" in a_type or "다운" in a_type: icon = "🔥"
            elif "변경" in a_type: icon = "⚠️"
            elif "복구" in a_type or "정상" in a_type: icon = "✅"

            # 슬랙 메시지 예쁘게 조립
            slack_msg = f"{icon} *[{a_type}] 감지*\n- *발생 일시*: {time_str}\n- *대상 장비*: {server}\n- *상세 내용*: {msg}"
            send_webhook(slack_msg)

        # 4. 전부 전송 후 카운트 저장 (다음에 중복 발송 안 하도록)
        with open(STATE_FILE, "w", encoding="utf-8") as f:
            json.dump({"sent_count": len(history_data)}, f)
    else:
        print("새로운 알림이 없습니다.")

if __name__ == "__main__":
    main()
