<?php
session_start();

$timeout_duration = 600;
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();
        session_destroy();
        echo "<script>alert('⏰ 10분 동안 활동이 없어 자동 로그아웃되었습니다.'); location.href = 'login.php';</script>";
        exit;
    }
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    echo "<script>alert('관리자 권한이 필요합니다.'); location.href='index.php';</script>";
    exit;
}

$db_file = '/opt/scp/dashboard_users.db';
$msg = '';

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 액션 처리 (승인, 거절/삭제, 권한 변경)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $user_id = $_POST['username'] ?? '';
        
        if ($_POST['action'] === 'approve') {
            $stmt = $db->prepare("UPDATE users SET status = 'APPROVED' WHERE username = :username");
            $stmt->bindValue(':username', $user_id, PDO::PARAM_STR);
            $stmt->execute();
            $msg = "✅ 사용자($user_id) 가입이 승인되었습니다!";
        } elseif ($_POST['action'] === 'delete') {
            if ($user_id !== $_SESSION['user_id']) {
                $stmt = $db->prepare("DELETE FROM users WHERE username = :username");
                $stmt->bindValue(':username', $user_id, PDO::PARAM_STR);
                $stmt->execute();
                $msg = "🗑️ 사용자($user_id) 계정이 삭제되었습니다.";
            } else {
                $msg = "⚠️ 자기 자신은 삭제할 수 없습니다.";
            }
        } elseif ($_POST['action'] === 'toggle_role') {
            $new_role = $_POST['current_role'] === 'admin' ? 'user' : 'admin';
            $stmt = $db->prepare("UPDATE users SET role = :role WHERE username = :username");
            $stmt->bindValue(':role', $new_role, PDO::PARAM_STR);
            $stmt->bindValue(':username', $user_id, PDO::PARAM_STR);
            $stmt->execute();
            $msg = "🔄 사용자($user_id)의 권한이 $new_role 로 변경되었습니다.";
        }
    }

    // 승인 대기 회원 목록
    $pending_users = $db->query("SELECT username, name, role, created_at FROM users WHERE status = 'PENDING' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // 승인 완료 회원 목록
    $approved_users = $db->query("SELECT username, name, role, created_at FROM users WHERE status = 'APPROVED' OR status IS NULL ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("DB 오류: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>회원 관리 - SCP 관제 플랫폼</title>
<style>
body { font-family: 'Malgun Gothic', sans-serif; background: #f1f5f9; margin: 0; padding: 30px; color: #1e293b; }
.container { max-width: 950px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
h1 { margin: 0; font-size: 22px; }
.btn-back { background: #64748b; color: white; text-decoration: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; font-size: 14px; }
.section-title { font-size: 16px; font-weight: bold; margin-top: 30px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; color: #0f172a; }
.badge-count { background: #ef4444; color: white; border-radius: 12px; padding: 2px 8px; font-size: 12px; }

table { width: 100%; border-collapse: collapse; margin-top: 5px; margin-bottom: 25px; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
th { background: #1e293b; color: white; font-size: 14px; }
.badge-admin { background: #0284c7; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
.badge-user { background: #64748b; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
.btn-action { border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; }
.btn-approve { background: #16a34a; color: white; }
.btn-approve:hover { background: #15803d; }
.btn-role { background: #f59e0b; color: white; }
.btn-del { background: #ef4444; color: white; }
.msg { background: #dcfce7; color: #15803d; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold; }
.empty-box { background: #f8fafc; text-align: center; color: #94a3b8; padding: 20px; border-radius: 8px; border: 1px dashed #cbd5e1; margin-bottom: 25px; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚙️ 회원 관리 (관리자)</h1>
        <a href="index.php" class="btn-back">⬅️ 대시보드로 돌아가기</a>
    </div>

    <?php if ($msg): ?>
        <div class="msg"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- 1. 승인 대기 목록 -->
    <div class="section-title">
        ⏳ 가입 승인 대기 목록
        <?php if (count($pending_users) > 0): ?>
            <span class="badge-count"><?php echo count($pending_users); ?>대기 중</span>
        <?php endif; ?>
    </div>

    <?php if (count($pending_users) === 0): ?>
        <div class="empty-box">현재 가입 승인 대기 중인 회원이 없습니다.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>아이디</th>
                    <th>이름</th>
                    <th>신청일시</th>
                    <th>승인 관리</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_users as $u): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['name']); ?></td>
                        <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="username" value="<?php echo $u['username']; ?>">
                                <button type="submit" class="btn-action btn-approve">✅ 가입 승인</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('가입 신청을 거절하고 삭제하시겠습니까?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="username" value="<?php echo $u['username']; ?>">
                                <button type="submit" class="btn-action btn-del">❌ 거절/삭제</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 2. 승인 완료 회원 목록 -->
    <div class="section-title">👥 등록된 회원 목록</div>
    <table>
        <thead>
            <tr>
                <th>아이디</th>
                <th>이름</th>
                <th>권한</th>
                <th>가입일시</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($approved_users as $u): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td>
                        <span class="<?php echo $u['role'] === 'admin' ? 'badge-admin' : 'badge-user'; ?>">
                            <?php echo strtoupper($u['role']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                    <td>
                        <?php if ($u['username'] !== $_SESSION['user_id']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_role">
                                <input type="hidden" name="username" value="<?php echo $u['username']; ?>">
                                <input type="hidden" name="current_role" value="<?php echo $u['role']; ?>">
                                <button type="submit" class="btn-action btn-role">권한 변경</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('정말 삭제하시겠습니까?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="username" value="<?php echo $u['username']; ?>">
                                <button type="submit" class="btn-action btn-del">삭제</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:12px;">본인</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
