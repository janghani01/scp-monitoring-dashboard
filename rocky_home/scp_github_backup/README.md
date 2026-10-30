# ☁️ SCP 통합 관제 대시보드 (SCP Monitoring Dashboard)

## 1. 프로젝트 개요 및 구축 목적
본 프로젝트는 파편화된 클라우드 인프라(VM, DBaaS, Storage) 자원과 시스템 보안/권한(KISA 진단, SG, IAM) 상태를 단일 뷰(Single-Pane-of-Glass)에서 관리하기 위해 기획된 통합 관제 웹 플랫폼입니다. 
기존 클라우드 콘솔이나 CLI를 통해 개별적으로 접근해야 했던 모니터링 작업과 수동 보안 점검을 자동화하고, Slack 웹훅 연동 및 백그라운드 데이터 동기화를 통해 인프라 운영의 가시성과 업무 효율성을 극대화하는 것을 목적으로 개발되었습니다.

## 2. 사용 기술 스택 (Tech Stack)
- **Frontend**: HTML5, CSS3, Vanilla JavaScript (Chart.js, FullCalendar 연동)
- **Backend**: PHP, SQLite (`dashboard_users.db`)
- **Automation & Scripting**: Python 3.6, Bash Shell Script
- **Infrastructure & OS**: Linux (Rocky Linux), Crontab, rsync, SSH/Ansible Remote Execution
- **VCS (형상관리)**: Git, GitHub (토큰 기반 무인 자동화 백업)

## 3. 핵심 기능 및 참고 파일 (Key Features & Reference Files)
각 기능이 구현된 주요 파일과 동작 방식입니다. 코드 리뷰 시 아래 매핑된 파일들을 중점적으로 확인하시면 됩니다.

### 🖥️ 1. 메인 대시보드 및 VM 서버 통합 관리
전체 인프라 자원의 라이프사이클(생성/삭제)을 추적하고 실시간 상태를 모니터링합니다. 
- **기능**: OS 및 서버 유형별 차트 시각화, 자원(CPU/RAM/Disk) 실시간 상태 조회, 다중 서버 원격 쉘 명령어 실행 및 파일 일괄 배포. 서버별 매핑된 방화벽(Security Group) 정보 실시간 파싱.
- **참고 파일**:
  - `index.php` : 대시보드 UI 및 프론트엔드/백엔드 코어 라우팅 로직 (차트 렌더링, 동적 서버 테이블 구성)
  - `get_server_metrics.php` : 선택한 VM의 실시간 자원 상태를 백그라운드에서 긁어오는 API
  - `run_ansible.php` / `upload_ansible.php` : 원격 서버 제어 및 파일 배포 백엔드 프로세스
  - `servers.json` / `os_info.json` : 스케줄러가 수집한 인프라 스펙 및 OS 정보

### 🗄️ 2. DBaaS (MySQL) 자동 동기화 모니터링
PaaS 형태의 데이터베이스 클러스터 상태를 추적합니다.
- **기능**: 클러스터 상태(Running), 할당 스펙, 엔드포인트(VIP 및 Port), Active/Standby 노드 현황 실시간 동기화.
- **참고 파일**: 
  - `sync_mysql_info.py` : 클라우드 API를 호출하여 MySQL 토폴로지 정보를 추출하고 병합하는 Python 파싱 스크립트 (Python 3.6 호환 패치 적용)
  - `mysql_dashboard_data.json` : 파이썬 스크립트가 갱신하는 결과 데이터 세트
  - `index.php` (DBaaS 관리 탭) : JSON을 비동기로 읽어와 렌더링하는 UI 로직

### 🛡️ 3. 주요정보통신기반시설(KISA) 취약점 진단 및 조치 (CSPM)
보안 가이드라인 준수 여부를 자동 스캔하고 원격으로 조치합니다.
- **기능**: U-01 ~ U-60 항목에 대한 대상 서버 원격 자동 스캔, 취약 항목 도출 시 자동 쉘 스크립트 주입을 통한 원격 조치(Auto-Remediation).
- **참고 파일**:
  - `index.php` (KISA 취약점 진단 탭) : 진단 항목 체크박스 선택 및 결과 출력 로직, 각 U-코드별 판별 기준(정규식 및 텍스트 매칭) 로직
  - `run_ansible.php` : 대상 서버에 진단 및 조치 명령어(`sed`, `chmod`, `systemctl` 등)를 실행 후 결과 반환

### 🐳 4. Docker 컨테이너 관제 및 네트워크 통신 확인
- **기능**: 서버 내 구동 중인 Docker 컨테이너 상태(Running/Exited) 조회 및 라이프사이클 제어(Start/Stop). 출발지-도착지 간 TCP/UDP 통신 테스트(Port Open 및 라우팅 점검).
- **참고 파일**:
  - `index.php` (Docker 관리 / 통신 확인 탭)
  - `run_ansible.php` : 백엔드에서 `docker ps`, `curl telnet`, `nc` 명령어를 실행하고 터미널 아웃풋 반환

### 🔑 5. IAM 권한 및 Security Group / Load Balancer 시각화
- **기능**: 클라우드 계정 사용자 정책 및 Access Key 발급 현황 추적, 방화벽(SG) 룰셋 인바운드/아웃바운드 필터링 및 시각화, 로드밸런서(LB) 리스너 및 타겟 매핑 현황 제공.
- **참고 파일**:
  - `index.php` (IAM / SG / LB 탭) : CSV 및 JSON 백그라운드 데이터를 파싱하여 동적 피벗 테이블 렌더링
  - `iam_current.json`, `scp_lb_member_mapping.csv` : 스케줄러를 통해 추출된 원본 데이터

### 📅 6. 운영 자동화: 일정 캘린더 및 Slack 웹훅 연동
- **기능**: 매일 맞춤형 알림(Routine) 설정 및 해결 이력 DB화, Jira 티켓 번호 연동, 서버 다운/과부하 감지 시 Slack 채널 자동 메시지 발송.
- **참고 파일**:
  - `dashboard_users.db` (SQLite) : 루틴 알림 이력, Jira 연동 매핑, 메모장 데이터가 영구 저장되는 DB
  - `alert_history.json` : 장애 감지 데몬이 기록하는 로그 파일

## 🔄 시스템 백엔드 파이프라인 (Crontab Automation)
웹 페이지 로딩 속도를 최적화하고 클라우드 API Rate Limit(호출 제한) 부하를 방지하기 위해 2-Track 무인 스케줄링 파이프라인을 운영합니다.

1. **리소스 스크래핑 파이프라인 (`*/10 * * * *`)**
   - 10분 주기로 `sync_mysql_info.py` 및 쉘 스크립트들이 클라우드 인프라 자원을 스캔하여 로컬 JSON 캐시를 갱신.
2. **GitHub 형상관리 및 영구 백업 파이프라인 (`0 0 * * *`)**
   - 매일 자정 `git_push.sh` 스크립트 실행. 
   - 서버 내 분산된 3개 경로(`/var/www/html/`, `/opt/scp/`, `/home/rocky/`)를 `rsync` 알고리즘으로 단일 통합 폴더로 동기화.
   - `.gitignore`를 통해 에디터 임시 파일(`.swp`, `.sw*` 등)을 필터링하고, `git pull --rebase` 명령어로 원격 저장소 충돌을 방지한 후 GitHub에 무인 Push 백업 처리.
