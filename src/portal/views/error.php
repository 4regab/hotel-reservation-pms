<?php /** @var string $heading @var string $message @var int $status */ ?>
<div class="container-page max-w-xl pb-4 pt-16 text-center sm:pt-24">
  <p class="font-display text-7xl font-medium tracking-tight text-clay-700 sm:text-8xl"><?= e($status) ?></p>
  <h1 class="page-title mt-4"><?= e($heading) ?></h1>
  <p class="mt-4 text-ink-600"><?= e($message) ?></p>
  <p class="mt-8 flex flex-wrap justify-center gap-3"><a class="btn btn-primary" href="/">Back to home</a><a class="btn btn-secondary" href="/search">Find a room</a></p>
</div>
