#!/usr/bin/env python3
import json, os, urllib.request, shutil

# ==========================================
# 🚨 캡처해주신 Webhook.site 주소를 적용했습니다!
WEBHOOK_URL = "https://hooks.slack.com/services/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"
# ==========================================

STATE_FILE = '/opt/scp/alert_state.json'
OS_INFO_FILE = '/opt/scp/os_info.json'

def get_system_metrics():
    # CPU 부하량 (1분 평균)
    cpu_load = os.getloadavg()[0]
    
    # 디스크 사용량 (루트 경로)
    total, used, free = shutil.disk_usage("/")
    disk_percent = (used / total) * 100
    
    return cpu_load, disk_percent

def get_unreachable_vms():
    unreachable_ips = []
    if os.path.exists(OS_INFO_FILE):
        try:
            with open(OS_INFO_FILE, 'r', encoding='utf-8') as f:
                os_info = json.load(f)
            for ip, info in os_info.items():
                if 'UNREACHABLE' in str(info.get('os', '')).upper() or '접속불가' in str(info.get('os', '')):
                    unreachable_ips.append(ip)
        except Exception as e:
            print(f"OS info 읽기 오류: {e}")
    return unreachable_ips

def send_webhook(message):
    payload = {"text": message}
    data = json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(WEBHOOK_URL, data=data, headers={'Content-Type': 'application/json'})
    try:
        urllib.request.urlopen(req, timeout=5)
        print("✅ 알림 전송 성공!")
    except Exception as e:
        print(f"❌ 웹훅 전송 실패: {e}")

def main():
    cpu_load, disk_percent = get_system_metrics()
    unreachable_vms = get_unreachable_vms()
    
    alerts = []
    
    # 1. 중앙 서버 리소스 임계치 체크
    if cpu_load > 80.0:
        alerts.append(f"🔥 [위험] 중앙 관리서버 CPU 부하율 높음 ({cpu_load:.1f}%)")
    if disk_percent > 90.0:
        alerts.append(f"💾 [위험] 중앙 관리서버 디스크 용량 부족 ({disk_percent:.1f}% 사용중)")
        
    # 2. VM 상태 체크 (접속 불가)
    if unreachable_vms:
        alerts.append(f"🚫 [장애] VM 접속 불가 탐지 (총 {len(unreachable_vms)}대)")
        # 5대까지만 IP를 나열하고 나머지는 생략
        display_ips = unreachable_vms[:5]
        alerts.append(f"   👉 대상 IP: {', '.join(display_ips)}" + (" 등..." if len(unreachable_vms) > 5 else ""))

    # 3. 알림 스팸 방지 로직 (이전 상태와 비교)
    try:
        with open(STATE_FILE, 'r') as f:
            state = json.load(f)
    except:
        state = {'last_alert': ''}

    current_alert_hash = str(alerts)
    
    # 새로운 장애가 생겼을 때만 알림 발송
    if alerts and state.get('last_alert') != current_alert_hash:
        message = "🚨 *[SCP 관제 긴급 알림]* 🚨\n\n" + "\n".join(alerts)
        send_webhook(message)
        
        with open(STATE_FILE, 'w') as f:
            json.dump({'last_alert': current_alert_hash}, f)
            
    # 장애가 모두 해결되었을 때 복구 알림 발송
    elif not alerts and state.get('last_alert') != '':
        message = "✅ *[SCP 관제 복구 알림]* ✅\n\n모든 서버 및 리소스가 정상 상태로 복구되었습니다."
        send_webhook(message)
        
        with open(STATE_FILE, 'w') as f:
            json.dump({'last_alert': ''}, f)

if __name__ == '__main__':
    main()
