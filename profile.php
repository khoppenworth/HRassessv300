<?php
require_once __DIR__.'/config.php';
auth_required();
refresh_current_user($pdo);
$t = load_lang($_SESSION['lang'] ?? 'en');
$user = current_user();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['date_of_birth'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $cadre = trim($_POST['cadre'] ?? '');
    $workFunction = $_POST['work_function'] ?? '';
    $language = $_POST['language'] ?? ($_SESSION['lang'] ?? 'en');
    $password = $_POST['password'] ?? '';

    if ($fullName === '' || $email === '' || $gender === '' || $dob === '' || $phone === '' || $department === '' || $cadre === '' || $workFunction === '') {
        $error = t($t,'profile_required','Please complete all required fields.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t($t,'invalid_email','Provide a valid email address.');
    } elseif (!in_array($gender, ['female','male','other','prefer_not_say'], true)) {
        $error = t($t,'invalid_gender','Select a valid gender option.');
    } elseif (!in_array($workFunction, WORK_FUNCTIONS, true)) {
        $error = t($t,'invalid_work_function','Select a valid work function.');
    } else {
        $fields = [
            'full_name' => $fullName,
            'email' => $email,
            'gender' => $gender,
            'date_of_birth' => $dob,
            'phone' => $phone,
            'department' => $department,
            'cadre' => $cadre,
            'work_function' => $workFunction,
            'language' => $language,
            'profile_completed' => 1,
        ];
        $params = array_values($fields);
        $set = implode(', ', array_map(static function ($key) { return "$key=?"; }, array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE users SET $set WHERE id=?");
        $params[] = $user['id'];
        $stmt->execute($params);
        if ($password !== '') {
            if (strlen($password) < 6) {
                $error = t($t,'password_too_short','Password must be at least 6 characters long.');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $user['id']]);
            }
        }
        if (!$error) {
            $_SESSION['lang'] = $language;
            refresh_current_user($pdo);
            $user = current_user();
            $message = t($t,'profile_updated','Profile updated successfully.');
        }
    }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><title><?=t($t,'profile','Profile')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/css/material.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'profile_information','Profile Information')?></h2>
    <?php if ($message): ?><div class="md-alert success"><?=$message?></div><?php endif; ?>
    <?php if ($error): ?><div class="md-alert error"><?=$error?></div><?php endif; ?>
    <form method="post" class="md-form-grid">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field">
        <span><?=t($t,'full_name','Full Name')?></span>
        <input name="full_name" value="<?=htmlspecialchars($user['full_name'] ?? '')?>" required>
      </label>
      <label class="md-field">
        <span><?=t($t,'email','Email')?></span>
        <input name="email" type="email" value="<?=htmlspecialchars($user['email'] ?? '')?>" required>
      </label>
      <label class="md-field">
        <span><?=t($t,'gender','Gender')?></span>
        <select name="gender" required>
          <?php $gval = $user['gender'] ?? ''; ?>
          <option value="" disabled <?= $gval ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <option value="female" <?=$gval==='female'?'selected':''?>><?=t($t,'female','Female')?></option>
          <option value="male" <?=$gval==='male'?'selected':''?>><?=t($t,'male','Male')?></option>
          <option value="other" <?=$gval==='other'?'selected':''?>><?=t($t,'other','Other')?></option>
          <option value="prefer_not_say" <?=$gval==='prefer_not_say'?'selected':''?>><?=t($t,'prefer_not_say','Prefer not to say')?></option>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t,'date_of_birth','Date of Birth')?></span>
        <input type="date" name="date_of_birth" value="<?=htmlspecialchars($user['date_of_birth'] ?? '')?>" required>
      </label>
      <label class="md-field">
        <span><?=t($t,'phone','Phone Number')?></span>
        <input name="phone" value="<?=htmlspecialchars($user['phone'] ?? '')?>" required>
      </label>
      <label class="md-field">
        <span><?=t($t,'department','Department')?></span>
        <input name="department" value="<?=htmlspecialchars($user['department'] ?? '')?>" required>
      </label>
      <label class="md-field">
        <span><?=t($t,'cadre','Cadre')?></span>
        <input name="cadre" value="<?=htmlspecialchars($user['cadre'] ?? '')?>" required>
      </label>
      <label class="md-field">
        <span><?=t($t,'work_function','Work Function / Cadre')?></span>
        <select name="work_function" required>
          <?php $wval = $user['work_function'] ?? ''; ?>
          <option value="" disabled <?= $wval ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach (WORK_FUNCTIONS as $function): ?>
            <option value="<?=$function?>" <?=$wval===$function?'selected':''?>><?=htmlspecialchars(WORK_FUNCTION_LABELS[$function] ?? $function)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t,'preferred_language','Preferred Language')?></span>
        <?php $lval = $_SESSION['lang'] ?? ($user['language'] ?? 'en'); ?>
        <select name="language">
          <option value="en" <?=$lval==='en'?'selected':''?>>English</option>
          <option value="am" <?=$lval==='am'?'selected':''?>>Amharic</option>
          <option value="fr" <?=$lval==='fr'?'selected':''?>>Français</option>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t,'new_password','New Password (optional)')?></span>
        <input type="password" name="password" minlength="6">
      </label>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>