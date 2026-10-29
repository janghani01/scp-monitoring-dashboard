#!/usr/bin/env python3
import json, urllib.request, time
from datetime import datetime

# 부팅 직후 네트워크가 연결될 시간을 벌기 위해 30초 대기
time.sleep(30) 

WEBHOOK_URL = "https://hooks.slack.com/services/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"
HISTORY_FILE = '/opt/scp/alert_history.json'
now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

# 화면에 보이던 중앙서버 IP
server_ip = "172.16.118.63" 

msg = "🚨 *[중앙 관제서버 재부팅 감지]* 🚨\n\n중앙 관리서버(test-mgmt-01)가 방금 재부팅되어 관제 시스템이 다시 시작되었습니다!"

# 1. 슬랙 발송
try: 
    urllib.request.urlopen(urllib.request.Request(WEBHOOK_URL, data=json.dumps({"text": msg}).encode("utf-8"), headers={"Content-Type": "application/json"}))
except: 
    pass

# 2. 대시보드 알림 현황에 기록
try:
    with open(HISTORY_FILE, "r") as f: history = json.load(f)
except: 
    history = []
    
history.insert(0, {"time": now_str, "server": server_ip, "type": "재부팅", "message": "중앙 관리서버(test-mgmt-01) 시스템 재부팅 완료"})

with open(HISTORY_FILE, "w") as f: 
    json.dump(history[:500], f, ensure_ascii=False, indent=2)
