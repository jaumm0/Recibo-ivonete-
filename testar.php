<?php
/**
 * testar.php
 * Página de diagnóstico: valida a conversão de valores por extenso.
 * Acesse pelo navegador para conferir vários casos de uma vez.
 */
require_once __DIR__ . '/extenso.php';

$casos = [
    [257.40 + 207.90, 'Exemplo do projeto (combustível + alimentação)'],
    [465.30,          '465,30'],
    [1,               '1 real'],
    [0.30,            '30 centavos'],
    [2,               '2 reais'],
    [100,             'cem'],
    [101,             'cento e um'],
    [1100,            'mil e cem'],
    [1234,            'mil duzentos e trinta e quatro'],
    [1234.56,         '1.234,56'],
    [1200000,         'um milhão e duzentos mil'],
    [1234000,         'um milhão duzentos e trinta e quatro mil'],
    [1000000,         'um milhão'],
    [100100,          'cem mil e cem'],
    [1000001,         'um milhão e um'],
    [200100,          'duzentos mil e cem'],
    [0,               'zero'],
    ['1.234,56',      'string BR "1.234,56"'],
    [99.99,           '99,99'],
    [1500.90,         '1.500,90'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Teste do valor por extenso</title>
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background:#f4f5f7; padding:32px; color:#1f2937; }
  .wrap { max-width:760px; margin:0 auto; }
  h1 { font-size:20px; }
  table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden;
          box-shadow:0 1px 2px rgba(0,0,0,.05); }
  th, td { padding:10px 14px; border-bottom:1px solid #e5e7eb; text-align:left; font-size:14px; }
  th { background:#f2f2f2; }
  td.num { font-variant-numeric: tabular-nums; white-space:nowrap; color:#6b7280; }
  td.ext { font-family: Georgia, serif; }
  .ok { color:#047857; font-weight:600; }
  a { color:#1d4ed8; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Teste do valor por extenso</h1>
  <p>Verifique se os resultados abaixo estão corretos.</p>
  <table>
    <tr><th>Valor</th><th>Extenso gerado</th><th>Observação</th></tr>
    <?php foreach ($casos as [$val, $obs]): ?>
      <tr>
        <td class="num"><?= htmlspecialchars(is_numeric($val) ? number_format((float)$val, 2, ',', '.') : $val) ?></td>
        <td class="ext ok"><?= htmlspecialchars(valorPorExtenso($val)) ?></td>
        <td><?= htmlspecialchars($obs) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:16px;"><a href="index.php">← Voltar ao gerador</a></p>
</div>
</body>
</html>