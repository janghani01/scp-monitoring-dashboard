#!/usr/bin/env python3
import json, os, subprocess, urllib.request
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

WEBHOOK_URL = "https://hooks.slack.com/services/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"
OS_INFO_FILE = '/opt/scp/os_info.json'
SERVERS_FILE = '/opt/scp/servers.json'  # 💡 서버 이름이 담긴 파일 추가
HISTORY_FILE = '/opt/scp/alert_history.json'
STATE_FILE = '/opt/scp/vm_state.json'
SSH_USER = "rocky"
SSH_KEYS = ["/home/rocky/rocky.pem", "/home/rocky/works_mgmt.pem"]

def check_vm(ip):
    remote_cmd = "up=$(cut -d' ' -f1 /proc/uptime); cpu=$(vmstat 1 2 | tail -1 | awk '{print 100-$15}'); mem=$(free | awk '/Mem/ {print $3/$2*100}'); echo \"$up|$cpu|$mem\""
    cmd = ["ssh", "-o", "StrictHostKeyChecking=no", "-o", "PasswordAuthentication=no", "-o", "ConnectTimeout=7"]
    for key in SSH_KEYS: cmd.extend(["-i", key])
    cmd.extend([f"{SSH_USER}@{ip}", remote_cmd])
    
    try:
        res = subprocess.check_output(cmd, stderr=subprocess.DEVNULL, timeout=20).decode('utf-8').strip()
        parts = res.split('|')
        if len(parts) == 3: return {'ip': ip, 'status': 'up', 'uptime': float(parts[0]), 'cpu': float(parts[1]), 'mem': float(parts[2]), 'error': None}
    except: pass
    return {'ip': ip, 'status': 'down', 'error': 'Failed'}

def send_webhook(message):
    try: urllib.request.urlopen(urllib.request.Request(WEBHOOK_URL, data=json.dumps({"text": message}).encode('utf-8'), headers={'Content-Type': 'application/json'}), timeout=5)
    except: pass

def main():
    if not os.path.exists(OS_INFO_FILE): return
    with open(OS_INFO_FILE, 'r', encoding='utf-8') as f: os_info = json.load(f)
    target_ips = [ip for ip, info in os_info.items() if 'UNREACHABLE' not in str(info.get('os','')).upper() and 'WINDOWS' not in str(info.get('os','')).upper()]
    
    # 💡 [핵심] IP로 서버명을 찾을 수 있도록 딕셔너리 생성
    ip_to_name = {}
    try:
        if os.path.exists(SERVERS_FILE):
            with open(SERVERS_FILE, 'r', encoding='utf-8') as f:
                srv_data = json.load(f)
                for s in srv_data.get('servers', []):
                    try:
                        name = s.get('name', 'Unknown')
                        s_ip = s['addresses'][0]['ip_addresses'][0]['ip_address']
                        ip_to_name[s_ip] = name
                    except: pass
    except: pass

    try:
        with open(STATE_FILE, 'r') as f: state = json.load(f)
    except: state = {}

    alerts_down, alerts_recover, alerts_reboot, alerts_cpu, alerts_mem = [], [], [], [], []
    history_records = []
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    new_state = {}

    with ThreadPoolExecutor(max_workers=15) as executor:
        futures = {executor.submit(check_vm, ip): ip for ip in target_ips}
        for future in as_completed(futures):
            data = future.result()
            ip = data['ip']
            
            # 💡 슬랙과 대시보드에 뿌려질 예쁜 서버명 포맷 만들기 (예: "btci02-mail (172.16.99.73)")
            disp_name = f"{ip_to_name.get(ip, 'Unknown')} ({ip})"
            
            prev_info = state.get(ip, {})
            prev_status = prev_info.get('status', 'unknown')
            prev_uptime = prev_info.get('uptime', 0.0)

            is_recently_booted = False

            if data['error']:
                new_state[ip] = {'status': 'down', 'uptime': prev_uptime}
                if prev_status == 'up':
                    msg = "서버 접속 실패 (전원 꺼짐 또는 네트워크 단절)"
                    alerts_down.append(f"- {disp_name} : {msg}")
                    history_records.append({"time": now_str, "server": disp_name, "type": "서버 다운", "message": msg})
                continue

            current_uptime = data['uptime']
            new_state[ip] = {'status': 'up', 'uptime': current_uptime}

            if prev_status == 'up' and prev_uptime > 0 and current_uptime < prev_uptime:
                msg = f"업타임 초기화 감지 (이전 {int(prev_uptime)}초 -> 현재 {int(current_uptime)}초)"
                alerts_reboot.append(f"- {disp_name} : {msg}")
                history_records.append({"time": now_str, "server": disp_name, "type": "재부팅", "message": msg})
                is_recently_booted = True
                
            elif prev_status == 'down':
                if current_uptime < prev_uptime or current_uptime < 300:
                    msg = f"다운 후 시스템 재부팅 복구 (현재 업타임 {int(current_uptime)}초)"
                    alerts_reboot.append(f"- {disp_name} : {msg}")
                    history_records.append({"time": now_str, "server": disp_name, "type": "재부팅", "message": msg})
                    is_recently_booted = True
                else:
                    msg = "서버 접속 복구 완료 (재부팅 없이 네트워크만 복구됨)"
                    alerts_recover.append(f"- {disp_name} : {msg}")
                    history_records.append({"time": now_str, "server": disp_name, "type": "접속 복구", "message": msg})

            if not is_recently_booted and current_uptime >= 300:
                if data['cpu'] >= 80.0:
                    msg = f"현재 CPU {data['cpu']:.1f}%"
                    alerts_cpu.append(f"- {disp_name} : {msg}")
                    history_records.append({"time": now_str, "server": disp_name, "type": "CPU 과부하", "message": msg})
                if data['mem'] >= 80.0:
                    msg = f"현재 MEM {data['mem']:.1f}%"
                    alerts_mem.append(f"- {disp_name} : {msg}")
                    history_records.append({"time": now_str, "server": disp_name, "type": "MEM 과부하", "message": msg})

    with open(STATE_FILE, 'w') as f: json.dump(new_state, f)

    final_message = ""
    if alerts_down: final_message += "❌ *[서버 다운/응답 없음 감지]*\n" + "\n".join(alerts_down) + "\n\n"
    if alerts_recover: final_message += "✅ *[서버 접속 복구]*\n" + "\n".join(alerts_recover) + "\n\n"
    if alerts_reboot: final_message += "🔄 *[VM 서버 재부팅 감지]*\n" + "\n".join(alerts_reboot) + "\n\n"
    if alerts_cpu: final_message += "🔥 *[VM CPU 80% 이상 과부하]*\n" + "\n".join(alerts_cpu) + "\n\n"
    if alerts_mem: final_message += "💾 *[VM MEM 80% 이상 과부하]*\n" + "\n".join(alerts_mem) + "\n\n"

    up_count = sum(1 for v in new_state.values() if v.get('status') == 'up')

    if final_message:
        send_webhook("🚨 *[SCP 전체 VM 인프라 상태 변경]* 🚨\n\n" + final_message.strip())
        try:
            with open(HISTORY_FILE, 'r', encoding='utf-8') as f: history = json.load(f)
        except Exception: history = []
        history = history_records + history
        with open(HISTORY_FILE, 'w', encoding='utf-8') as f: json.dump(history[:500], f, ensure_ascii=False, indent=2)
        print(f"[{now_str}] 🚨 상태 변경 감지! (슬랙 발송 완료) - 정상 응답: {up_count}대")
    else:
        print(f"[{now_str}] 전체 서버 기록 및 비교 완료. (정상 응답: {up_count}대) 이상 무.")

if __name__ == '__main__':
    main()
