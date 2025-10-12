<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$flash = $_SESSION['admin_role_flash'] ?? null;
if ($flash) {
    unset($_SESSION['admin_role_flash']);
}
$msg = $flash['message'] ?? '';
$msgType = $flash['type'] ?? 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $message = '';
    $type = 'success';
    try {
        if ($action === 'create') {
            $roleKey = strtolower(trim((string)($_POST['role_key'] ?? '')));
            $label = trim((string)($_POST['label'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $sortOrderInput = trim((string)($_POST['sort_order'] ?? ''));
            if ($roleKey === '' || !preg_match('/^[a-z0-9_]+$/', $roleKey)) {
                throw new RuntimeException(t($t, 'invalid_role_key', 'Role key must contain only lowercase letters, numbers, or underscores.'));
            }
            if ($label === '') {
                throw new RuntimeException(t($t, 'role_label_required', 'Role label is required.'));
            }
            $roleMap = get_user_role_map($pdo);
            if (isset($roleMap[$roleKey])) {
                throw new RuntimeException(t($t, 'role_key_exists', 'That role key is already defined.'));
            }
            if ($sortOrderInput !== '' && filter_var($sortOrderInput, FILTER_VALIDATE_INT) !== false) {
                $sortOrder = (int)$sortOrderInput;
            } else {
                $sortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM user_role')->fetchColumn();
            }
            $stmt = $pdo->prepare('INSERT INTO user_role (role_key, label, description, sort_order, is_protected) VALUES (?,?,?,?,0)');
            $stmt->execute([$roleKey, $label, $description !== '' ? $description : null, $sortOrder]);
            $message = t($t, 'role_created', 'Role added successfully.');
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException(t($t, 'invalid_role', 'Unable to locate the requested role.'));
            }
            $currentStmt = $pdo->prepare('SELECT * FROM user_role WHERE id = ?');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                throw new RuntimeException(t($t, 'invalid_role', 'Unable to locate the requested role.'));
            }
            $label = trim((string)($_POST['label'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $sortOrderInput = trim((string)($_POST['sort_order'] ?? ''));
            if ($label === '') {
                throw new RuntimeException(t($t, 'role_label_required', 'Role label is required.'));
            }
            $sortOrder = $current['sort_order'];
            if ($sortOrderInput !== '' && filter_var($sortOrderInput, FILTER_VALIDATE_INT) !== false) {
                $sortOrder = (int)$sortOrderInput;
            }
            $stmt = $pdo->prepare('UPDATE user_role SET label=?, description=?, sort_order=? WHERE id=?');
            $stmt->execute([
                $label,
                $description !== '' ? $description : null,
                $sortOrder,
                $id,
            ]);
            $message = t($t, 'role_updated', 'Role updated successfully.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException(t($t, 'invalid_role', 'Unable to locate the requested role.'));
            }
            $currentStmt = $pdo->prepare('SELECT * FROM user_role WHERE id = ?');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                throw new RuntimeException(t($t, 'invalid_role', 'Unable to locate the requested role.'));
            }
            if ((int)$current['is_protected'] === 1) {
                throw new RuntimeException(t($t, 'role_delete_protected', 'Protected roles cannot be deleted.'));
            }
            $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
            $usageStmt->execute([(string)$current['role_key']]);
            $usage = (int)$usageStmt->fetchColumn();
            if ($usage > 0) {
                throw new RuntimeException(t($t, 'role_delete_in_use', 'This role is assigned to existing users and cannot be deleted.'));
            }
            $del = $pdo->prepare('DELETE FROM user_role WHERE id = ?');
            $del->execute([$id]);
            $message = t($t, 'role_deleted', 'Role deleted successfully.');
        } else {
            throw new RuntimeException(t($t, 'invalid_action', 'Unsupported action.'));
        }
        refresh_user_role_cache($pdo);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $type = 'error';
    }

    $_SESSION['admin_role_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
    header('Location: ' . url_for('admin/metadata_roles.php'));
    exit;
}

$roles = get_user_roles($pdo);
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'role_metadata','Role Metadata'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style>
    .md-inline-form {
      display: block;
    }
    .md-role-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }
    .md-role-actions {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-top: 0.75rem;
    }
    .md-alert--error {
      background: rgba(220, 53, 69, 0.12);
      color: #7f1d1d;
      border-left: 4px solid #b91c1c;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'add_role','Add Role')?></h2>
    <?php if ($msg): ?>
      <div class="md-alert <?=$msgType==='error'?'md-alert--error':''?>"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div>
    <?php endif; ?>
    <form method="post" class="md-form-grid" action="<?=htmlspecialchars(url_for('admin/metadata_roles.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="create">
      <label class="md-field"><span><?=t($t,'role_key','Role Key')?></span><input name="role_key" pattern="[a-z0-9_]+" required placeholder="<?=htmlspecialchars(t($t,'role_key_placeholder','e.g. regional_manager'), ENT_QUOTES, 'UTF-8')?>"></label>
      <label class="md-field"><span><?=t($t,'role_label','Role Label')?></span><input name="label" required></label>
      <label class="md-field"><span><?=t($t,'role_description','Description (optional)')?></span><textarea name="description" rows="2"></textarea></label>
      <label class="md-field"><span><?=t($t,'sort_order','Sort Order')?></span><input type="number" name="sort_order" placeholder="<?=htmlspecialchars(t($t,'sort_order_hint','Leave blank to append to the end'), ENT_QUOTES, 'UTF-8')?>"></label>
      <button class="md-button md-primary md-elev-2"><?=t($t,'add','Add')?></button>
    </form>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'manage_roles','Manage Roles')?></h2>
    <?php if (!$roles): ?>
      <p class="md-empty-state"><?=t($t,'no_roles_defined','No roles have been defined yet.')?></p>
    <?php else: ?>
      <div class="md-table-scroll">
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t,'role_key','Role Key')?></th>
              <th><?=t($t,'role_label','Role Label')?></th>
              <th><?=t($t,'role_description','Description')?></th>
              <th><?=t($t,'sort_order','Sort Order')?></th>
              <th><?=t($t,'actions','Actions')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $role): ?>
              <tr>
                <td>
                  <strong><?=htmlspecialchars($role['role_key'], ENT_QUOTES, 'UTF-8')?></strong><br>
                  <?php if ((int)$role['is_protected'] === 1): ?>
                    <span class="md-chip md-chip--small"><?=t($t,'protected_role','Protected')?></span>
                  <?php endif; ?>
                </td>
                <td colspan="4">
                  <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/metadata_roles.php'), ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?=$role['id']?>">
                    <div class="md-role-grid">
                      <label class="md-field md-field--compact">
                        <span><?=t($t,'role_label','Role Label')?></span>
                        <input name="label" value="<?=htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8')?>" required>
                      </label>
                      <label class="md-field md-field--compact">
                        <span><?=t($t,'role_description','Description (optional)')?></span>
                        <textarea name="description" rows="2" placeholder="<?=htmlspecialchars(t($t,'role_description_hint','Optional description for admins'), ENT_QUOTES, 'UTF-8')?>"><?=
htmlspecialchars((string)($role['description'] ?? ''), ENT_QUOTES, 'UTF-8')?></textarea>
                      </label>
                      <label class="md-field md-field--compact">
                        <span><?=t($t,'sort_order','Sort Order')?></span>
                        <input type="number" name="sort_order" value="<?=htmlspecialchars((string)($role['sort_order'] ?? 0), ENT_QUOTES, 'UTF-8')?>">
                      </label>
                    </div>
                    <div class="md-role-actions">
                      <button class="md-button md-elev-1" type="submit"><?=t($t,'save','Save')?></button>
                      <?php if ((int)$role['is_protected'] === 0): ?>
                        <button class="md-button md-danger md-elev-1" type="submit" data-confirm="<?=htmlspecialchars(t($t,'confirm_delete_role','Delete this role? This action cannot be undone.'), ENT_QUOTES, 'UTF-8')?>" data-role-id="<?=$role['id']?>" data-role-label="<?=htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8')?>"><?=t($t,'delete','Delete')?></button>
                      <?php endif; ?>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function() {
  const deleteButtons = document.querySelectorAll('button[data-confirm][data-role-id]');
  deleteButtons.forEach((btn) => {
    btn.addEventListener('click', (event) => {
      const message = btn.dataset.confirm || 'Delete this role?';
      if (!window.confirm(message)) {
        event.preventDefault();
        event.stopPropagation();
      } else {
        const form = btn.closest('form');
        if (form) {
          form.elements.action.value = 'delete';
        }
      }
    });
  });
})();
</script>
</body>
</html>
