<?php
require_once __DIR__ . '/gerar.php';

$path = __DIR__ . '/FOLHA AGOSTO 2026 (7).xlsx';
$funcs = extrairFuncionariosXlsx($path);

echo "Total lidos da planilha: " . count($funcs) . "\n\n";

// Simulando o que gerar.php faz no loop de geracao
echo "Simulando geracao para 'comprovante':\n";
$compartilhado = ['periodo' => '01/08/2026 a 31/08/2026', 'data' => '2026-08-31', 'cidade' => 'CG'];
$gerados = 0;
$pulados = 0;
$comValorZero = 0;
foreach ($funcs as $f) {
    $dados = montarDados(array_merge($compartilhado, $f, ['tipo' => 'comprovante']));
    if (($dados['valor_total'] ?? 0) <= 0) {
        $pulados++;
        if ($dados['valor_total'] == 0) $comValorZero++;
        continue;
    }
    $gerados++;
}
echo "  Gerariam DOCX: $gerados\n";
echo "  Pulados (total <= 0): $pulados\n";
echo "  Deles com total EXATO = 0: $comValorZero\n";

echo "\nSimulando geracao para 'comprovante_pagamento':\n";
$gerados = 0; $pulados = 0; $comValorZero = 0;
foreach ($funcs as $f) {
    $dados = montarDados(array_merge($compartilhado, $f, ['tipo' => 'comprovante_pagamento']));
    if (($dados['valor_total'] ?? 0) <= 0) {
        $pulados++;
        if ($dados['valor_total'] == 0) $comValorZero++;
        continue;
    }
    $gerados++;
}
echo "  Gerariam DOCX: $gerados\n";
echo "  Pulados (total <= 0): $pulados\n";
echo "  Deles com total EXATO = 0: $comValorZero\n";