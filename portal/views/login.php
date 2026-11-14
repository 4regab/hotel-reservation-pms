<?php /** @var Portal\Web\Ctx $c @var string $next @var string $email @var ?string $error @var bool $otpNeeded */ ?>
<div class="container-page max-w-md py-10">
  <h1 class="text-3xl font-bold">Sign in</h1>
  <p class="mt-1 text-slate-600">New here? <a class="link" href="/register<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Create an account</a>.</p>
  <?= form_open($c, '/login', 'card card-pad mt-6 space-y-4') ?>
    <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?= field('Email', 'email', 'email', $email, 'required autocomplete="username" autofocus') ?>
    <?= field('Password', 'password', 'password', '', 'required autocomplete="current-password"') ?>
    <div<?= $otpNeeded ? '' : '' ?>>
      <?= field('6-digit code (staff only)', 'otp', 'text', '', 'inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"' . ($otpNeeded ? ' required' : ''), 'Staff accounts also need the code from their authenticator app. Guests can leave this empty.') ?>
    </div>
    <button class="btn btn-primary w-full">Sign in</button>
    <p class="text-center text-sm"><a class="link" href="/forgot-password">Forgot your password?</a></p>
  </form>
</div>
