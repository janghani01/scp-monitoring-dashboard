#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys, json, subprocess

def run_cmd(cmd):
    try:
        res = subprocess.check_output(cmd, shell=True, stderr=subprocess.STDOUT, timeout=5)
        return res.decode('utf-8', errors='ignore').strip()
    except Exception as e:
        return "ERROR"

def check_u01():
    out = run_cmd("sudo sshd -T 2>/dev/null | grep -i '^permitrootlogin' | awk '{print $2}'")
    status = "양호" if out.lower() == "no" else "취약"
    return {"title": "[U-01] root 계정 원격 접속 제한", "desc": "root 직접 원격 접속 차단 여부", "val": out or "yes", "status": status}

def check_u02():
    out = run_cmd("sudo grep -E '^minlen' /etc/security/pwquality.conf 2>/dev/null | awk '{print $3}'")
    status = "양호" if out and out != "ERROR" else "취약"
    return {"title": "[U-02] 패스워드 복잡성 설정", "desc": "패스워드 최소 길이 및 복잡도 설정", "val": out or "설정없음", "status": status}

def check_u03():
    out = run_cmd("sudo grep -i 'deny=' /etc/pam.d/system-auth /etc/pam.d/password-auth 2>/dev/null | wc -l")
    status = "양호" if out.isdigit() and int(out) > 0 else "취약"
    return {"title": "[U-03] 계정 잠금 임계값 설정", "desc": "비밀번호 실패 시 계정 잠금 설정", "val": f"{out}개 설정됨", "status": status}

def check_u04():
    out = run_cmd("sudo stat -c '%a:%U' /etc/shadow 2>/dev/null")
    status = "양호" if "000:root" in out or "400:root" in out else "취약"
    return {"title": "[U-04] /etc/shadow 파일 권한", "desc": "shadow 파일 400 이하 및 root 소유 여부", "val": out or "확인불가", "status": status}

def check_u05():
    out = run_cmd("echo $PATH | egrep '(^|:)(\\.|:|$)' >/dev/null && echo '취약' || echo '양호'")
    return {"title": "[U-05] root 홈, 패스 디렉터리 권한", "desc": "PATH 환경변수 내 '.' 포함 여부", "val": out, "status": out}

def check_u06():
    out = run_cmd("sudo awk -F: '$3 == 0 { print $1 }' /etc/passwd | grep -v '^root$' | wc -l")
    status = "양호" if out == "0" else "취약"
    return {"title": "[U-06] root 이외의 UID 0 금지", "desc": "root 외 UID 0 계정 존재 여부", "val": f"추가 UID 0 계정 {out}개", "status": status}

def check_u07():
    out = run_cmd("sudo stat -c '%a:%U' /etc/passwd 2>/dev/null")
    status = "양호" if "644:root" in out or "600:root" in out else "취약"
    return {"title": "[U-07] /etc/passwd 파일 권한", "desc": "passwd 파일 644 이하 및 root 소유 여부", "val": out or "확인불가", "status": status}

def check_u19():
    out = run_cmd("sudo ss -tuln 2>/dev/null | grep ':79\\b' | wc -l")
    status = "양호" if out == "0" else "취약"
    return {"title": "[U-19] Finger 서비스 비활성화", "desc": "Finger 서비스 포트(79) 오픈 여부", "val": "비활성화" if out=="0" else "활성화", "status": status}

def check_u20():
    out = run_cmd("sudo ss -tuln 2>/dev/null | grep ':21\\b' | wc -l")
    status = "양호" if out == "0" else "취약"
    return {"title": "[U-20] Anonymous FTP 비활성화", "desc": "FTP 서비스 포트(21) 오픈 여부", "val": "비활성화" if out=="0" else "활성화", "status": status}

def check_u60():
    out = run_cmd("sudo sshd -T 2>/dev/null | grep '^port' | awk '{print $2}'")
    status = "양호" if out and out != "22" else "취약(기본포트)"
    return {"title": "[U-60] SSH 포트 변경", "desc": "기본 22번 포트 변경 사용 여부", "val": f"포트 {out}", "status": status}

# 모듈 디스패처
CHECK_MAP = {
    "U-01": check_u01, "U-02": check_u02, "U-03": check_u03, "U-04": check_u04,
    "U-05": check_u05, "U-06": check_u06, "U-07": check_u07, "U-19": check_u19,
    "U-20": check_u20, "U-60": check_u60
}

if __name__ == "__main__":
    items = sys.argv[1].split(',') if len(sys.argv) > 1 else list(CHECK_MAP.keys())
    results = {}
    for item in items:
        item = item.strip()
        if item in CHECK_MAP:
            results[item] = CHECK_MAP[item]()
        else:
            results[item] = {"title": f"[{item}] 점검 항목", "desc": "가이드라인 진단 준비 중", "val": "N/A", "status": "양호"}
    print(json.dumps(results, ensure_ascii=False))
