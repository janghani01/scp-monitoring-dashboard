<?php
session_start();

$db_file = '/opt/scp/dashboard_users.db';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $db = new PDO("sqlite:" . $db_file);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // 💡 승인 상태 체크 (status가 PENDING이면 로그인 거부)
                $status = $user['status'] ?? 'APPROVED';
                if ($status === 'PENDING') {
                    $error = '⏳ 아직 관리자 승인이 완료되지 않았습니다. 승인 후 이용해 주세요.';
                } else {
                    $_SESSION['user_id'] = $user['username'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'] ?? 'user';
                    $_SESSION['last_activity'] = time();

                    header("Location: index.php");
                    exit;
                }
            } else {
                $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
            }
        } catch (Exception $e) {
            $error = 'DB 연결 오류: ' . $e->getMessage();
        }
    } else {
        $error = '아이디와 비밀번호를 입력해 주세요.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>SCP 관제 플랫폼 - 로그인</title>
<style>
body { font-family: 'Malgun Gothic', sans-serif; background: #0f172a; height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
.login-card { background: white; width: 380px; padding: 40px 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; }
.login-title { font-size: 22px; font-weight: bold; color: #0f172a; margin-bottom: 25px; }
.input-group { margin-bottom: 15px; text-align: left; }
.input-group label { display: block; font-size: 13px; font-weight: bold; color: #475569; margin-bottom: 5px; }
.input-group input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.btn-submit { width: 100%; padding: 12px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: bold; cursor: pointer; margin-top: 10px; }
.btn-submit:hover { background: #1d4ed8; }
.error-msg { background: #fef2f2; color: #ef4444; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px; line-height: 1.4; font-weight: bold; }
.register-link { margin-top: 20px; font-size: 13px; color: #64748b; }
.register-link a { color: #2563eb; text-decoration: none; font-weight: bold; }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-title">☁️ SCP 관제 플랫폼</div>
    <?php if ($error): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="input-group">
            <label>아이디</label>
            <input type="text" name="username" required autofocus placeholder="아이디 입력">
        </div>
        <div class="input-group">
            <label>비밀번호</label>
            <input type="password" name="password" required placeholder="비밀번호 입력">
        </div>
        <button type="submit" class="btn-submit">로그인</button>
    </form>
    <div class="register-link">
        계정이 없으신가요? <a href="register.php">회원가입 신청</a>
    </div>
</div>
</body>
</html>
