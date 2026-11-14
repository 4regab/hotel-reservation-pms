<?php /** @var Portal\Web\Ctx $c @var array $accounts @var string $role @var string $q @var string $me */
$roles = ['reception' => 'Reception', 'housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'cashier' => 'Cashier / finance', 'manager' => 'Manager', 'it' => 'IT / privacy lead', 'steward' => 'Data steward']; ?>
<div class="container-page max-w-6xl py-8">
  <h1 class="text-3xl font-bold">Users and roles</h1>
  <p class="mt-1 text-slate-600">Every change here is written to the audit log. Role changes and disabling sign the person out everywhere.</p>

  <details class="card card-pad mt-6" <?= $accounts ? '' : 'open' ?>>
    <summary class="cursor-pointer text-lg font-semibold">Create a staff account</summary>
    <?= form_open($c, '/staff/admin/users', 'mt-4 grid gap-4 sm:grid-cols-2') ?>
      <?= field('Full name', 'fullName', 'text', '', 'required maxlength="120"') ?>
      <?= field('Work email', 'email', 'email', '', 'required maxlength="160"') ?>
      <?= field('Phone (optional)', 'phone', 'tel', '', 'maxlength="40"') ?>
      <?= select_field('Role', 'role', $roles, 'reception') ?>
      <p class="hint sm:col-span-2">The next page shows a one-time password and the second-factor secret. Hand them over in person and never by email.</p>
      <div class="sm:col-span-2"><button class="btn btn-primary">Create account</button></div>
    </form>
  </details>

  <form method="get" action="/staff/admin/users" class="mt-6 flex flex-wrap items-end gap-3" role="search">
    <div><label class="label" for="f-role">Role</label><select class="input" id="f-role" name="role"><option value="">All roles</option><option value="guest"<?= $role === 'guest' ? ' selected' : '' ?>>Guests</option><?php foreach ($roles as $k => $l) { ?><option value="<?= e($k) ?>"<?= $role === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php } ?></select></div>
    <div class="min-w-0 flex-1 sm:max-w-xs"><label class="label" for="f-q">Name or email</label><input class="input" id="f-q" name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-secondary">Filter</button>
  </form>

  <div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Accounts</caption>
    <thead><tr><th scope="col">Person</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($accounts as $a) { $self = $a['account_id'] === $me; $staff = $a['role'] !== 'guest'; ?>
        <tr>
          <td><p class="font-medium"><?= e($a['full_name']) ?><?= $self ? ' (you)' : '' ?></p><p class="break-all text-xs text-slate-600"><?= e($a['email']) ?></p></td>
          <td><?= e(ucfirst($a['role'])) ?></td>
          <td><?= (int) $a['active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Disabled</span>' ?><?php if ($a['locked_until'] && strtotime($a['locked_until'] . ' UTC') > time()) { ?> <span class="badge badge-amber">Locked</span><?php } ?></td>
          <td class="whitespace-nowrap"><?= e($a['last_login_at'] ? dt($a['last_login_at']) : 'never') ?></td>
          <td>
            <div class="flex flex-wrap items-end gap-2">
              <?php if ($staff && !$self) { ?>
                <?= form_open($c, '/staff/admin/users/' . $a['account_id'] . '/role', 'flex items-end gap-1') ?><label class="sr-only" for="r-<?= e($a['account_id']) ?>">Role for <?= e($a['full_name']) ?></label><select class="input min-h-9 py-1 text-sm" id="r-<?= e($a['account_id']) ?>" name="role"><?php foreach ($roles as $k => $l) { ?><option value="<?= e($k) ?>"<?= $a['role'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php } ?></select><button class="btn btn-secondary btn-sm">Change</button></form>
              <?php } ?>
              <?php if (!$self) { echo form_open($c, '/staff/admin/users/' . $a['account_id'] . '/active', 'inline', (int) $a['active'] ? 'data-confirm="Disable ' . e($a['full_name']) . '? They are signed out at once."' : '') . '<input type="hidden" name="active" value="' . ((int) $a['active'] ? '0' : '1') . '"><button class="btn btn-sm ' . ((int) $a['active'] ? 'btn-ghost' : 'btn-secondary') . '">' . ((int) $a['active'] ? 'Disable' : 'Enable') . '<span class="sr-only"> ' . e($a['full_name']) . '</span></button></form>'; } ?>
              <?php if ($staff) { echo form_open($c, '/staff/admin/users/' . $a['account_id'] . '/totp', 'inline', 'data-confirm="Make a new second-factor secret? The old one stops working."') . '<input type="hidden" name="email" value="' . e($a['email']) . '"><button class="btn btn-ghost btn-sm">Reset 2FA<span class="sr-only"> for ' . e($a['full_name']) . '</span></button></form>'; } ?>
              <?= form_open($c, '/staff/admin/users/' . $a['account_id'] . '/reset-link', 'inline') ?><button class="btn btn-ghost btn-sm">Send reset link<span class="sr-only"> to <?= e($a['full_name']) ?></span></button></form>
            </div>
          </td>
        </tr>
      <?php } ?>
    </tbody>
  </table></div>
  <?php if (!$accounts) { ?><p class="card card-pad mt-4 text-center">No accounts match.</p><?php } ?>
</div>
