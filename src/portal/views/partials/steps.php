<?php /** @var int $step current step (1 to 4) @var ?string $lastLabel label for step 4 */
$names = [1 => 'Choose', 2 => 'Your details', 3 => 'Pay', 4 => $lastLabel ?? 'Confirmed']; ?>
<nav aria-label="Booking steps" class="no-print mb-8">
  <ol class="steps flex-wrap">
    <?php foreach ($names as $n => $label) { ?>
      <li class="<?= $n < $step ? 'done' : '' ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
        <span class="step-dot" aria-hidden="true"><?= $n < $step ? icon('check', 'size-3.5') : $n ?></span>
        <span class="<?= $n === $step ? '' : 'sr-only sm:not-sr-only' ?>"><?= e($label) ?><?= $n < $step ? '<span class="sr-only"> (done)</span>' : '' ?></span>
      </li>
    <?php } ?>
  </ol>
</nav>
