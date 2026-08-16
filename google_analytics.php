<?php
/**
 * google-analytics.php
 * ---------------------------------------------------------------------
 * Snippet do Google Analytics (GA4), centralizado em um único arquivo.
 *
 * COMO USAR:
 * Inclua esta linha dentro da tag <head> de CADA página do site
 * (logo depois do <meta charset="UTF-8">, por exemplo):
 *
 *     <?php include 'google-analytics.php'; ?>
 *
 * Vantagem de centralizar assim: se um dia precisar trocar o ID de
 * medição, ou adicionar/remover algo do rastreamento, você mexe em
 * um único arquivo em vez de editar todas as páginas uma por uma.
 * ---------------------------------------------------------------------
 */

// Troque pelo seu Measurement ID assim que criar a propriedade no GA4
// (formato: G-XXXXXXXXXX)
$GA_MEASUREMENT_ID = 'G-FT1YL095P9';
?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($GA_MEASUREMENT_ID) ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?= htmlspecialchars($GA_MEASUREMENT_ID) ?>');
</script>