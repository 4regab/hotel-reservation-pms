<?php /** @var Portal\Web\Ctx $c @var array $a */ ?>
<div class="container-page max-w-2xl py-8">
  <h1 class="text-3xl font-bold">My profile</h1>
  <?= form_open($c, '/account/profile', 'card card-pad mt-6 space-y-4') ?>
    <h2 class="text-lg font-semibold">Contact details</h2>
    <?= field('Full name', 'fullName', 'text', $a['full_name'], 'required maxlength="120" autocomplete="name"') ?>
    <?= field('Mobile number', 'phone', 'tel', $a['phone'], 'required maxlength="40" autocomplete="tel"') ?>
    <div><p class="label">Email</p><p class="text-slate-800"><?= e($a['email']) ?></p><p class="hint">To change your email, send a correction request from the <a class="link" href="/account/privacy">privacy page</a>.</p></div>
    <?php if ($c->role() === 'guest') { echo checkbox_field('Send me occasional offers (optional).', 'marketing', (bool) $a['marketing_consent']); } ?>
    <button class="btn btn-primary">Save profile</button>
  </form>
  <?= form_open($c, '/account/password', 'card card-pad mt-6 space-y-4') ?>
    <h2 class="text-lg font-semibold">Change password</h2>
    <?= field('Current password', 'current', 'password', '', 'required autocomplete="current-password"') ?>
    <?= field('New password', 'new', 'password', '', 'required minlength="10" autocomplete="new-password"', 'At least 10 characters.') ?>
    <?= field('Repeat new password', 'new2', 'password', '', 'required minlength="10" autocomplete="new-password"') ?>
    <button class="btn btn-secondary">Change password</button>
  </form>
</div>
