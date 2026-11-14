<?php /** @var Portal\Web\Ctx $c @var string $token @var bool $valid @var ?string $error @var bool $done */ ?>
<div class="container-page max-w-md py-10">
  <h1 class="text-3xl font-bold"><?= $done ? 'Password changed' : 'Choose a new password' ?></h1>
  <?php if ($done) { ?>
    <p class="alert alert-ok mt-6" role="status">Your password was changed and every device was signed out.</p>
    <p class="mt-4"><a class="btn btn-primary" href="/login">Sign in</a></p>
  <?php } elseif (!$valid) { ?>
    <p class="alert alert-error mt-6" role="alert">This link has expired or was already used.</p>
    <p class="mt-4"><a class="btn btn-primary" href="/forgot-password">Ask for a new link</a></p>
  <?php } else { ?>
    <?= form_open($c, '/reset-password', 'card card-pad mt-6 space-y-4') ?>
      <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?= field('New password', 'password', 'password', '', 'required minlength="10" autocomplete="new-password" autofocus', 'At least 10 characters.') ?>
      <?= field('Repeat new password', 'password2', 'password', '', 'required minlength="10" autocomplete="new-password"') ?>
      <button class="btn btn-primary w-full">Save new password</button>
    </form>
  <?php } ?>
</div>
