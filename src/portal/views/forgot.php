<?php /** @var Portal\Web\Ctx $c @var bool $sent @var ?string $error */ ?>
<div class="container-page grid gap-8 pb-4 pt-8 sm:pt-12 lg:min-h-[36rem] lg:grid-cols-2">
  <div class="mx-auto w-full max-w-md self-center">
    <h1 class="page-title"><?= $sent ? 'Check your email' : 'Reset your password' ?></h1>
    <?php if ($sent) { ?>
      <p class="alert alert-ok mt-8" role="status">If an account exists for that address, we sent a link that works for 1 hour. It can take a minute to arrive.</p>
      <p class="mt-6"><a class="btn btn-secondary" href="/login">Back to sign in</a></p>
    <?php } else { ?>
      <p class="mt-3 text-ink-600">Enter your email and we will send you a link to choose a new password.</p>
      <?= form_open($c, '/forgot-password', 'card card-pad mt-8 space-y-5') ?>
        <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
        <?= field('Email', 'email', 'email', '', 'required autocomplete="username" autofocus') ?>
        <button class="btn btn-primary btn-lg w-full">Send reset link</button>
        <p class="text-center text-sm"><a class="link" href="/login">Back to sign in</a></p>
      </form>
    <?php } ?>
  </div>
  <?php include __DIR__ . '/partials/auth_photo.php'; ?>
</div>
