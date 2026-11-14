<?php /** @var string $heading @var string $message @var int $status */ ?>
<div class="container-page max-w-xl py-16 text-center">
  <p class="text-sm font-semibold text-brand-800"><?= e($status) ?></p>
  <h1 class="mt-2 text-3xl font-bold"><?= e($heading) ?></h1>
  <p class="mt-3 text-slate-600"><?= e($message) ?></p>
  <p class="mt-6 flex flex-wrap justify-center gap-3"><a class="btn btn-primary" href="/">Back to home</a><a class="btn btn-secondary" href="/search">Find a room</a></p>
</div>
