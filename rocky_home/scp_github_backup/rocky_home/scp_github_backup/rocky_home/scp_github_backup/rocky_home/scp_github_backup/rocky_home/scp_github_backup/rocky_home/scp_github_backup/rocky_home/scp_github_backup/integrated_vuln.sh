#!/bin/bash

echo "==============================================="
echo " 통합 서버 취약점 조치 스크립트 (Central)"
echo "==============================================="
read -p "대상 서버 OS를 선택하세요 (1: Rocky, 2: Ubuntu): " os_num
read -p "대상 서버 IP를 입력하세요: " target_ip
read -p "PEM 키 경로를 입력하세요 (예: /path/to/key.pem): " pem_key

# OS에 따른 계정 분기
if [ "$os_num" -eq 1 ]; then
    target_user="rocky"
elif [ "$os_num" -eq 2 ]; then
    target_user="ubuntu"
else
    echo "잘못된 선택입니다. 종료합니다."
    exit 1
fi

while true; do
    echo ""
    echo "==============================================="
    echo " 조치할 항목을 선택하세요 ($target_user @ $target_ip)"
    echo "==============================================="
    echo "1. 로그인 경고 메시지 (Banner) 설정"
    echo "2. SSH Root 로그인 제한"
    echo "3. 패스워드 정책 (login.defs) 설정"
    echo "4. 패스워드 복잡도 (pwquality) 설정"
    echo "5. 계정 잠금 정책 (faillock/tally2) 설정"
    echo "6. Session Timeout (TMOUT) 및 UMASK 설정"
    echo "7. 주요 파일 권한 수정 및 SUID 제거 (hosts, shadow, cron 등)"
    echo "8. 전체 항목 일괄 조치"
    echo "0. 종료"
    echo "==============================================="
    read -p "번호 입력: " menu_choice

    if [ "$menu_choice" -eq 0 ]; then
        echo "스크립트를 종료합니다."
        break
    fi

    # SSH를 통해 원격 서버로 스크립트 내용을 전달하여 실행 (파라미터 전달)
    ssh -i "$pem_key" "${target_user}@${target_ip}" "sudo bash -s" "$os_num" "$menu_choice" << 'EOF'
    
    OS_TYPE=$1
    CHOICE=$2

    echo ""
    echo ">>> 원격 서버 조치 시작..."

    ############################################
    # 1. 로그인 경고 메시지
    ############################################
    if [ "$CHOICE" -eq 1 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[1] Login Banner Check"
        BANNER="#####################################################
###          WARNING: Authorized Users Only!      ###
###  All activities are monitored and recorded.   ###
###  Unauthorized access is strictly prohibited.  ###
#####################################################"
        if grep -q "Authorized Users Only" /etc/motd 2>/dev/null; then
            echo "Banner already configured"
        else
            echo "$BANNER" > /etc/motd
            echo "$BANNER" > /etc/issue
            echo "Banner configured"
        fi

        if grep -q "^Banner /etc/issue" /etc/ssh/sshd_config 2>/dev/null; then
            echo "SSH Banner already configured"
        else
            echo "Banner /etc/issue" >> /etc/ssh/sshd_config
            echo "SSH Banner configured"
        fi
    fi

    ############################################
    # 2. SSH Root Login 제한
    ############################################
    if [ "$CHOICE" -eq 2 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[2] SSH Root Login Check"
        if grep -q "^PermitRootLogin no" /etc/ssh/sshd_config; then
            echo "Root SSH already disabled"
        else
            sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
            grep -q "^PermitRootLogin" /etc/ssh/sshd_config || echo "PermitRootLogin no" >> /etc/ssh/sshd_config
            if [ "$OS_TYPE" -eq 1 ]; then
                systemctl restart sshd
            else
                systemctl restart ssh
            fi
            echo "Root SSH login disabled"
        fi
    fi

    ############################################
    # 3. Password 정책 (login.defs)
    ############################################
    if [ "$CHOICE" -eq 3 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[3] Password Policy Check (login.defs)"
        for item in "PASS_MAX_DAYS 90" "PASS_MIN_DAYS 1" "PASS_WARN_AGE 7"; do
            key=$(echo $item | awk '{print $1}')
            val=$(echo $item | awk '{print $2}')
            if grep -q "^${key}" /etc/login.defs; then
                sed -i "s/^${key}.*/${key}   ${val}/" /etc/login.defs
            elif grep -q "^#${key}" /etc/login.defs; then
                sed -i "s/^#${key}.*/${key}   ${val}/" /etc/login.defs
            else
                echo "${key}   ${val}" >> /etc/login.defs
            fi
        done
        echo "Password policy (login.defs) applied"
    fi

    ############################################
    # 4. Password 복잡도 정책 (pwquality)
    ############################################
    if [ "$CHOICE" -eq 4 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[4] Password Complexity Check (pwquality)"
        PWQUALITY_CONF="/etc/security/pwquality.conf"
        
        if [ -f "$PWQUALITY_CONF" ]; then
            sed -i 's/^#*\s*minlen.*/minlen = 8/' $PWQUALITY_CONF
            sed -i 's/^#*\s*dcredit.*/dcredit = -1/' $PWQUALITY_CONF
            sed -i 's/^#*\s*ucredit.*/ucredit = -1/' $PWQUALITY_CONF
            sed -i 's/^#*\s*ocredit.*/ocredit = -1/' $PWQUALITY_CONF
            sed -i 's/^#*\s*lcredit.*/lcredit = -1/' $PWQUALITY_CONF
            sed -i 's/^#*\s*retry.*/retry = 3/' $PWQUALITY_CONF
            echo "pwquality.conf updated"
        else
            cat <<EOL > $PWQUALITY_CONF
minlen = 8
dcredit = -1
ucredit = -1
ocredit = -1
lcredit = -1
retry = 3
EOL
            echo "pwquality.conf created"
        fi

        if [ "$OS_TYPE" -eq 1 ]; then
            # Rocky PAM 설정
            if grep -q "pam_pwquality.so" /etc/pam.d/system-auth; then
                echo "pam_pwquality already in system-auth"
            else
                sed -i '/^password.*pam_unix.so/i password    requisite     pam_pwquality.so try_first_pass local_users_only retry=3 authtok_type=' /etc/pam.d/system-auth
                echo "pam_pwquality added to system-auth"
            fi
        else
            # Ubuntu PAM 설정
            if grep -q "pam_pwquality.so" /etc/pam.d/common-password; then
                echo "Password policy already configured"
            else
                sed -i '/^password.*pam_unix.so/i password requisite pam_pwquality.so retry=3 minlen=8 lcredit=-1 ucredit=-1 dcredit=-1 ocredit=-1' /etc/pam.d/common-password
                echo "Password policy applied"
            fi
        fi
    fi

    ############################################
    # 5. 계정 잠금 정책
    ############################################
    if [ "$CHOICE" -eq 5 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[5] Account Lock Policy Check"
        if [ "$OS_TYPE" -eq 1 ]; then
            # Rocky 계정 잠금 (pam_faillock)
            if grep -q "pam_faillock.so" /etc/pam.d/system-auth; then
                echo "Account lock policy already configured"
            else
                sed -i '/^auth.*pam_unix.so/i auth        required      pam_faillock.so preauth silent deny=5 unlock_time=120' /etc/pam.d/system-auth
                sed -i '/^auth.*pam_unix.so/a auth        [default=die] pam_faillock.so authfail deny=5 unlock_time=120' /etc/pam.d/system-auth
                sed -i '/^account.*pam_unix.so/i account     required      pam_faillock.so' /etc/pam.d/system-auth
                echo "Account lock policy applied to system-auth"
            fi
            if grep -q "pam_faillock.so" /etc/pam.d/password-auth; then
                echo "Account lock policy already in password-auth"
            else
                sed -i '/^auth.*pam_unix.so/i auth        required      pam_faillock.so preauth silent deny=5 unlock_time=120' /etc/pam.d/password-auth
                sed -i '/^auth.*pam_unix.so/a auth        [default=die] pam_faillock.so authfail deny=5 unlock_time=120' /etc/pam.d/password-auth
                sed -i '/^account.*pam_unix.so/i account     required      pam_faillock.so' /etc/pam.d/password-auth
                echo "Account lock policy applied to password-auth"
            fi
        else
            # Ubuntu 계정 잠금 (버전별 분기)
            UBUNTU_VER=$(lsb_release -rs 2>/dev/null | cut -d. -f1)
            if [ "$UBUNTU_VER" -ge 22 ] 2>/dev/null; then
                if grep -q "pam_faillock.so" /etc/pam.d/common-auth; then
                    echo "Account lock policy (pam_faillock) already configured"
                else
                    sed -i '/^auth.*pam_unix.so/i auth required pam_faillock.so preauth silent deny=5 unlock_time=120' /etc/pam.d/common-auth
                    sed -i '/^auth.*pam_unix.so/a auth [default=die] pam_faillock.so authfail deny=5 unlock_time=120' /etc/pam.d/common-auth
                    sed -i '/^account.*pam_unix.so/i account required pam_faillock.so' /etc/pam.d/common-auth
                    echo "Account lock policy (pam_faillock) applied"
                fi
            else
                if grep -q "pam_tally2.so" /etc/pam.d/common-auth; then
                    echo "Account lock policy (pam_tally2) already configured"
                else
                    echo "auth required pam_tally2.so deny=5 unlock_time=120 no_magic_root" >> /etc/pam.d/common-auth
                    echo "Account lock policy (pam_tally2) applied"
                fi
            fi
        fi
    fi

    ############################################
    # 6. Session Timeout 및 UMASK
    ############################################
    if [ "$CHOICE" -eq 6 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[6] Session Timeout & Umask Check"
        if grep -q "^TMOUT=600" /etc/profile; then
            echo "TMOUT already configured"
        else
            echo "TMOUT=600" >> /etc/profile
            echo "export TMOUT" >> /etc/profile
            echo "TMOUT configured"
        fi
        export TMOUT=600

        if grep -q "^umask 022" /etc/profile; then
            echo "umask already configured in /etc/profile"
        else
            echo "umask 022" >> /etc/profile
            [ "$OS_TYPE" -eq 2 ] && echo "export umask" >> /etc/profile
            echo "umask configured in /etc/profile"
        fi

        if [ "$OS_TYPE" -eq 1 ]; then
            if grep -q "^umask 022" /etc/bashrc; then
                echo "umask already in bashrc"
            else
                echo "umask 022" >> /etc/bashrc
            fi
        fi
    fi

    ############################################
    # 7. 주요 권한 수정 및 SUID 제거
    ############################################
    if [ "$CHOICE" -eq 7 ] || [ "$CHOICE" -eq 8 ]; then
        echo "[7] Permissions and SUID Check"
        
        # Shadow
        perm=$(stat -c "%a" /etc/shadow)
        if [ "$perm" == "400" ]; then echo "Shadow permission already secure"; else chmod 400 /etc/shadow; echo "Shadow permission fixed"; fi
        
        # Hosts
        perm=$(stat -c "%a" /etc/hosts)
        if [ "$perm" == "600" ]; then echo "hosts permission already correct"; else chmod 600 /etc/hosts; echo "hosts permission fixed"; fi
        
        # su
        perm=$(stat -c "%a" /usr/bin/su)
        if [ "$perm" == "4750" ]; then echo "su permission already secure"; else chmod 4750 /usr/bin/su; echo "su permission fixed"; fi

        # rsyslog
        chmod 640 /etc/rsyslog.conf
        [ "$OS_TYPE" -eq 2 ] && chmod 640 /etc/rsyslog.d/50-default.conf 2>/dev/null
        if grep -q "^\*.alert" /etc/rsyslog.conf; then
            echo "Alert logging already configured"
        else
            echo "*.alert /dev/console" >> /etc/rsyslog.conf
            echo "Alert logging configured"
        fi
        systemctl restart rsyslog 2>/dev/null

        # cron
        chmod 750 /usr/bin/crontab
        chmod 640 /etc/crontab 2>/dev/null
        chmod 640 /etc/cron.deny 2>/dev/null
        echo "cron permissions applied"

        # SUID 제거
        chmod -s /sbin/unix_chkpwd 2>/dev/null
        chmod -s /usr/bin/newgrp 2>/dev/null
        echo "SUID removed"
    fi

    echo "<<< 조치 완료"
EOF
done
