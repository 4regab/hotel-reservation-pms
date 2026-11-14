<?php /** @var Portal\Web\Ctx $c @var bool $sent @var ?string $error */ ?>
<div class="container-page max-w-md py-10">
  <h1 class="text-3xl font-bold"><?= $sent ? 'Check your email' : 'Reset your password' ?></h1>
  <?php if ($sent) { ?>
    <p class="alert alert-ok mt-6" role="status">If an account exists for that address, we sent a link that works for 1 hour. It can take a minute to arrive.</p>
    <p class="mt-4"><a class="link" href="/login">Back to sign in</a></p>
  <?php } else { ?>
    <p class="mt-1 text-slate-600">Enter your email and we will send you a link to choose a new password.</p>
    <?= form_open($c, '/forgot-password', 'card card-pad mt-6 space-y-4') ?>
      <?php if ($error) { ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php } ?>
      <?= field('Email', 'email', 'email', '', 'required autocomplete="username" autofocus') ?>
      <button class="btn btn-primary w-full">Send reset link</button>
    </form>
  <?php } ?>
</div>
