<?php /** @var Portal\Web\Ctx $c @var array $v @var ?string $error @var string $next */ ?>
<div class="container-page grid gap-8 pb-4 pt-8 sm:pt-12 lg:grid-cols-2">
  <div class="mx-auto w-full max-w-md self-center">
    <h1 class="page-title">Create your account</h1>
    <p class="mt-3 text-ink-600">Already registered? <a class="link" href="/login<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Sign in</a>.</p>
    <?= form_open($c, '/register', 'card card-pad mt-8 space-y-5') ?>
      <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <?= field('Full name', 'fullName', 'text', $v['fullName'], 'required maxlength="120" autocomplete="name"') ?>
      <?= field('Email', 'email', 'email', $v['email'], 'required maxlength="160" autocomplete="email"') ?>
      <?= field('Mobile number', 'phone', 'tel', $v['phone'], 'required maxlength="40" autocomplete="tel"') ?>
      <div class="grid gap-5 sm:grid-cols-2">
        <?= field('Password', 'password', 'password', '', 'required minlength="10" autocomplete="new-password"', 'At least 10 characters.') ?>
        <?= field('Repeat password', 'password2', 'password', '', 'required minlength="10" autocomplete="new-password"') ?>
      </div>
      <?= checkbox_field('I have read how MA Hotel uses my data and agree to it for handling my bookings.', 'terms', false, 'required') ?>
      <?= checkbox_field('Send me occasional offers (optional, separate from booking emails).', 'marketing') ?>
      <button class="btn btn-primary btn-lg w-full">Create account</button>
    </form>
  </div>
  <?php include __DIR__ . '/partials/auth_photo.php'; ?>
</div>
