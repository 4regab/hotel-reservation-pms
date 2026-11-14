<?php /** @var Portal\Web\Ctx $c @var string $token @var bool $valid @var ?string $error @var bool $done */ ?>
<div class="container-page grid gap-8 pb-4 pt-8 sm:pt-12 lg:min-h-[36rem] lg:grid-cols-2">
  <div class="mx-auto w-full max-w-md self-center">
    <h1 class="page-title"><?= $done ? 'Password changed' : 'Choose a new password' ?></h1>
    <?php if ($done) { ?>
      <p class="alert alert-ok mt-8" role="status">Your password was changed and every device was signed out.</p>
      <p class="mt-6"><a class="btn btn-primary" href="/login">Sign in</a></p>
    <?php } elseif (!$valid) { ?>
      <p class="alert alert-error mt-8" role="alert">This link has expired or was already used.</p>
      <p class="mt-6"><a class="btn btn-primary" href="/forgot-password">Ask for a new link</a></p>
    <?php } else { ?>
      <?= form_open($c, '/reset-password', 'card card-pad mt-8 space-y-5') ?>
        <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?= field('New password', 'password', 'password', '', 'required minlength="10" autocomplete="new-password" autofocus', 'At least 10 characters.') ?>
        <?= field('Repeat new password', 'password2', 'password', '', 'required minlength="10" autocomplete="new-password"') ?>
        <button class="btn btn-primary btn-lg w-full">Save new password</button>
      </form>
    <?php } ?>
  </div>
  <?php include __DIR__ . '/partials/auth_photo.php'; ?>
</div>
