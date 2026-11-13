<?php
session_start();

$db_file = '/opt/scp/dashboard_users.db';
$error = '';
$success = '';

// DB 테이블에 status 컬럼이 없으면 자동 추가
try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'APPROVED'");
} catch (Exception $e) {
    // 이미 컬럼이 존재하는 경우 무시
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $name = trim($_POST['name'] ?? '');

    if (!empty($username) && !empty($password) && !empty($name)) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->fetchColumn() > 0) {
                $error = '이미 존재하는 아이디입니다.';
            } else {
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);

                // 첫 계정이면 admin & APPROVED, 이후 계정은 user & PENDING (승인대기)
                $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $role = ($count == 0) ? 'admin' : 'user';
                $status = ($count == 0) ? 'APPROVED' : 'PENDING';

                $stmt = $db->prepare("INSERT INTO users (username, password, name, role, status, created_at) VALUES (:username, :password, :name, :role, :status, datetime('now', 'localtime'))");
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->bindValue(':password', $hashed_pw, PDO::PARAM_STR);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':role', $role, PDO::PARAM_STR);
                $stmt->bindValue(':status', $status, PDO::PARAM_STR);
                $stmt->execute();

                if ($status === 'APPROVED') {
                    $success = '최초 관리자 계정으로 가입이 완료되었습니다!';
                } else {
                    $success = '회원가입 신청이 완료되었습니다! ⏳ 관리자 승인 후 로그인이 가능합니다.';

                    // ==============================================================================
                    // 💡 [추가] 관리자 슬랙으로 신규 가입 대기 알림 쏘기 (일반 유저 가입 시에만 발송)
                    // ==============================================================================
                    $slack_webhook_url = "https://hooks.slack.com/services/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";
                    
                    $slack_message = "👤 *[대시보드 신규 가입 신청]* 👤\n새로운 사용자가 가입을 신청하여 *승인 대기 중*입니다.\n\n▪️ *신청 아이디:* {$username}\n▪️ *신청자 이름:* {$name}\n\n👉 *관리자님, 대시보드 [회원 관리] 탭에서 승인을 진행해 주세요!*";

                    $slack_data = json_encode(['text' => $slack_message]);

                    $ch = curl_init($slack_webhook_url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $slack_data);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3초 타임아웃 방어막
                    curl_exec($ch);
                    curl_close($ch);
                    // ==============================================================================
                }
            }
        } catch (Exception $e) {
            $error = 'DB 처리 오류: ' . $e->getMessage();
        }
    } else {
        $error = ' 모든 항목을 입력해 주세요.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>SCP 관제 플랫폼 - 회원가입</title>
<style>
body { font-family: 'Malgun Gothic', sans-serif; background: #0f172a; height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
.card { background: white; width: 380px; padding: 40px 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; }
.title { font-size: 22px; font-weight: bold; color: #0f172a; margin-bottom: 25px; }
.input-group { margin-bottom: 15px; text-align: left; }
.input-group label { display: block; font-size: 13px; font-weight: bold; color: #475569; margin-bottom: 5px; }
.input-group input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.btn-submit { width: 100%; padding: 12px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: bold; cursor: pointer; margin-top: 10px; }
.btn-submit:hover { background: #1d4ed8; }
.error-msg { background: #fef2f2; color: #ef4444; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px; }
.success-msg { background: #f0fdf4; color: #16a34a; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 15px; line-height: 1.4; font-weight: bold; }
.link { margin-top: 20px; font-size: 13px; color: #64748b; }
.link a { color: #2563eb; text-decoration: none; font-weight: bold; }
</style>
</head>
<body>
<div class="card">
    <div class="title">📝 회원가입 신청</div>
    <?php if ($error): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success-msg"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="input-group">
            <label>아이디</label>
            <input type="text" name="username" required placeholder="아이디 입력">
        </div>
        <div class="input-group">
            <label>비밀번호</label>
            <input type="password" name="password" required placeholder="비밀번호 입력">
        </div>
        <div class="input-group">
            <label>이름 / 담당자명</label>
            <input type="text" name="name" required placeholder="이름 입력">
        </div>
        <button type="submit" class="btn-submit">가입 신청하기</button>
    </form>
    <div class="link">
        이미 계정이 있으신가요? <a href="login.php">로그인</a>
    </div>
</div>
</body>
</html>
