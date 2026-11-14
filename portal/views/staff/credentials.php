<?php /** @var Portal\Web\Ctx $c @var array $made @var string $email @var string $intro */ ?>
<div class="container-page max-w-2xl py-8">
  <h1 class="text-3xl font-bold">Hand these over now</h1>
  <p class="alert alert-warn mt-4" role="alert"><?= e($intro) ?> This page shows the secrets <strong>once</strong>. They are not stored in readable form and cannot be shown again.</p>
  <dl class="card card-pad mt-6 space-y-4">
    <div><dt class="label">Sign-in email</dt><dd class="break-all font-mono"><?= e($email) ?></dd></div>
    <?php if (isset($made['tempPassword'])) { ?><div><dt class="label">One-time password</dt><dd class="font-mono text-lg" id="pw"><?= e($made['tempPassword']) ?></dd><dd class="mt-1"><button type="button" class="btn btn-secondary btn-sm" data-copy="#pw">Copy</button></dd><dd class="hint">The person should change it under Profile after the first sign-in.</dd></div><?php } ?>
    <div><dt class="label">Authenticator secret</dt><dd class="break-all font-mono text-lg" id="tp"><?= e($made['totpSecret']) ?></dd><dd class="mt-1"><button type="button" class="btn btn-secondary btn-sm" data-copy="#tp">Copy</button></dd><dd class="hint">Type this key into an authenticator app (6 digits, 30 seconds), or open the link below on the phone.</dd></div>
    <div><dt class="label">Setup link</dt><dd class="break-all font-mono text-xs"><?= e($made['otpauthUri']) ?></dd></div>
  </dl>
  <p class="mt-6"><a class="btn btn-primary" href="/staff/admin/users">Back to users</a></p>
</div>
