<?php
// /admin/_back_btn.php
function admin_back_btn(string $href, string $label): void {
  $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
  $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  echo '<div class="backbar">
    <a class="back-btn" href="'.$href.'">
      <svg viewBox="0 0 20 20"><path d="M12.293 15.707a1 1 0 0 0 0-1.414L8.414 10l3.879-4.293a1 1 0 1 0-1.414-1.414l-4.586 5a1 1 0 0 0 0 1.414l4.586 5a1 1 0 0 0 1.414 0z"/></svg>
      '.$label.'
    </a>
  </div>';
}
