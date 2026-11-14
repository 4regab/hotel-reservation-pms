<?php /** @var Portal\Web\Ctx $c @var array $v @var ?string $error @var string $next */ ?>
<div class="container-page max-w-md py-10">
  <h1 class="text-3xl font-bold">Create your account</h1>
  <p class="mt-1 text-slate-600">Already registered? <a class="link" href="/login<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Sign in</a>.</p>
  <?= form_open($c, '/register', 'card card-pad mt-6 space-y-4') ?>
    <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?= field('Full name', 'fullName', 'text', $v['fullName'], 'required maxlength="120" autocomplete="name"') ?>
    <?= field('Email', 'email', 'email', $v['email'], 'required maxlength="160" autocomplete="email"') ?>
    <?= field('Mobile number', 'phone', 'tel', $v['phone'], 'required maxlength="40" autocomplete="tel"') ?>
    <?= field('Password', 'password', 'password', '', 'required minlength="10" autocomplete="new-password"', 'At least 10 characters.') ?>
    <?= field('Repeat password', 'password2', 'password', '', 'required minlength="10" autocomplete="new-password"') ?>
    <?= checkbox_field('I have read how HarborLight uses my data and agree to it for handling my bookings.', 'terms', false, 'required') ?>
    <?= checkbox_field('Send me occasional offers (optional, separate from booking emails).', 'marketing') ?>
    <button class="btn btn-primary w-full">Create account</button>
  </form>
</div>
