<?php
require_once __DIR__ . '/gerar.php';

echo "=== TESTE 1: extrairFuncionariosXlsx na folha de Agosto ===\n";
$funcs = extrairFuncionariosXlsx(__DIR__ . '/FOLHA AGOSTO 2026 (7).xlsx');

$comValorTotalZero = [];
foreach ($funcs as $f) {
    // Simula o que cada tipo de doc produz
    $aux = (float)$f['combustivel'];
    $va  = (float)$f['alimentacao'];
    $aj  = (float)$f['aj_custo'];
    $bruto   = (float)($f['salario_bruto']   ?? 0);
    $liquido = (float)($f['salario_liquido'] ?? 0);
    $liqCalc = $liquido > 0 ? $liquido : ($bruto > 0 ? max(0, round($bruto - $aux - $va - $aj, 2)) : 0);

    // Comprovante de Vales
    $totalVales = round($aux + $va + $aj, 2);
    // Comprovante de Pagamento
    $totalPagto = $liqCalc;

    if ($totalVales <= 0 || $totalPagto <= 0) {
        $comValorTotalZero[] = [
            'nome' => $f['nome'],
            'emp'  => $f['empresa'],
            'totalVales' => $totalVales,
            'totalPagto' => $totalPagto,
        ];
    }
}

echo "Funcionários com pelo menos um tipo zerado:\n";
foreach ($comValorTotalZero as $x) {
    printf("  %-40s  emp=%-12s  Vales=%8.2f  Pagto=%8.2f\n",
        $x['nome'], $x['emp'], $x['totalVales'], $x['totalPagto']);
}
echo "\nTotal: " . count($comValorTotalZero) . "\n";

echo "\n=== TESTE 2: Simula o loop de geracao do LOTE para 'comprovante' ===\n";
$compartilhado = ['periodo' => '01/08/2026 a 31/08/2026', 'data' => '2026-08-31', 'cidade' => 'CG'];
$gerados = 0;
$pulados = 0;
$puladosDetalhes = [];
foreach ($funcs as $f) {
    $dados = montarDados(array_merge($compartilhado, $f, ['tipo' => 'comprovante']));
    if (($dados['valor_total'] ?? 0) <= 0) {
        $pulados++;
        if ($pulados <= 5) $puladosDetalhes[] = $f['nome'] . ' / total=' . ($dados['valor_total'] ?? 0);
    } else {
        $gerados++;
    }
}
echo "Gerados: $gerados | Pulados: $pulados\n";
echo "Primeiros pulados:\n";
foreach ($puladosDetalhes as $d) echo "  $d\n";

echo "\n=== TESTE 3: Simula o loop do LOTE para 'comprovante_pagamento' ===\n";
$gerados = 0; $pulados = 0;
foreach ($funcs as $f) {
    $dados = montarDados(array_merge($compartilhado, $f, ['tipo' => 'comprovante_pagamento']));
    if (($dados['valor_total'] ?? 0) <= 0) {
        $pulados++;
    } else {
        $gerados++;
    }
}
echo "Gerados: $gerados | Pulados: $pulados\n";

echo "\n=== TESTE 4: Simula RECIBO INDIVIDUAL com Luciano/Aux=VA=0 ===\n";
$entrada = [
    'empresa'      => 'Laboratório',
    'nome'         => 'Luciano Akira Kamada',
    'cpf'          => '',
    'combustivel'  => '0',
    'alimentacao'  => '0',
    'aj_custo'     => '0',
    'periodo'      => '01/08/2026 a 31/08/2026',
    'data'         => '2026-08-31',
    'cidade'       => 'CG',
];
$dados = montarDados($entrada);
echo "  valor_total=" . $dados['valor_total'] . "\n";
if ($dados['valor_total'] <= 0) {
    echo "  >>> BLOQUEADO (correto)\n";
} else {
    echo "  >>> PASSA - geraria DOCX zerado (BUG!)\n";
}