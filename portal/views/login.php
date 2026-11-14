<?php /** @var Portal\Web\Ctx $c @var string $next @var string $email @var ?string $error @var bool $otpNeeded */ ?>
<div class="container-page grid gap-8 pb-4 pt-8 sm:pt-12 lg:min-h-[40rem] lg:grid-cols-2">
  <div class="mx-auto w-full max-w-md self-center">
    <h1 class="page-title">Sign in</h1>
    <p class="mt-3 text-ink-600">New here? <a class="link" href="/register<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Create an account</a>.</p>
    <?= form_open($c, '/login', 'card card-pad mt-8 space-y-5') ?>
      <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <?= field('Email', 'email', 'email', $email, 'required autocomplete="username" autofocus') ?>
      <?= field('Password', 'password', 'password', '', 'required autocomplete="current-password"') ?>
      <?= field('6-digit code (staff only)', 'otp', 'text', '', 'inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"' . ($otpNeeded ? ' required' : ''), 'Staff accounts also need the code from their authenticator app. Guests can leave this empty.') ?>
      <button class="btn btn-primary btn-lg w-full">Sign in</button>
      <p class="text-center text-sm"><a class="link" href="/forgot-password">Forgot your password?</a></p>
    </form>
  </div>
  <?php include __DIR__ . '/partials/auth_photo.php'; ?>
</div>
