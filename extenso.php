<?php
/**
 * extenso.php
 * Conversão de valores numéricos para texto em português do Brasil.
 * Retorna o valor por extenso no formato "reais e centavos".
 */

/**
 * Converte um número (valor monetário) para extenso em reais.
 *
 * @param float|int|string $valor Ex.: 465.30, "465,30", 465
 * @return string Ex.: "Quatrocentos e sessenta e cinco reais e trinta centavos."
 */
function valorPorExtenso($valor): string
{
    // Aceita tanto "465.30" quanto "465,30"
    if (is_string($valor)) {
        $valor = str_replace(['.', ' '], '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    $valor = (float) $valor;
    // Arredonda para 2 casas para evitar flutuação de ponto flutuante
    $valor = round($valor, 2);

    $negativo = $valor < 0;
    $valor = abs($valor);

    $reais    = (int) floor($valor);
    $centavos = (int) round(($valor - $reais) * 100);

    // Ajuste de carry: 0.999 -> 1 real
    if ($centavos >= 100) {
        $reais += (int) floor($centavos / 100);
        $centavos = $centavos % 100;
    }

    $parte = '';

    if ($reais > 0) {
        $parte .= numeroPorExtenso($reais);
        $parte .= ($reais === 1) ? ' real' : ' reais';
    }

    if ($centavos > 0) {
        if ($reais > 0) {
            $parte .= ' e ';
        }
        $parte .= numeroPorExtenso($centavos);
        $parte .= ($centavos === 1) ? ' centavo' : ' centavos';
    }

    if ($reais === 0 && $centavos === 0) {
        $parte = 'zero reais';
    }

    if ($negativo) {
        $parte = 'menos ' . $parte;
    }

    // Primeira letra maiúscula e ponto final
    $parte = ucfirst(trim($parte));
    if (substr($parte, -1) !== '.') {
        $parte .= '.';
    }

    return $parte;
}

/**
 * Escreve um grupo de 3 dígitos (0..999) por extenso.
 * Observação: 100 isolado vira "cem"; 101 vira "cento e um".
 */
function cardinalTres(int $g): string
{
    if ($g === 0) {
        return '';
    }
    if ($g === 100) {
        return 'cem';
    }

    $unidades = [
        '', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete',
        'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'quatorze',
        'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove',
    ];
    $dezenas = [
        '', '', 'vinte', 'trinta', 'quarenta', 'cinquenta',
        'sessenta', 'setenta', 'oitenta', 'noventa',
    ];
    $centenas = [
        '', 'cento', 'duzentos', 'trezentos', 'quatrocentos',
        'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos',
    ];

    $c  = (int) floor($g / 100);
    $du = $g % 100;          // 0..99
    $d  = (int) floor($du / 10);
    $u  = $du % 10;

    $s = '';
    if ($c > 0) {
        $s = $centenas[$c];
    }

    if ($du > 0 && $du < 20) {
        $s .= ($s !== '' ? ' e ' : '') . $unidades[$du];
    } else {
        if ($d > 0) {
            $s .= ($s !== '' ? ' e ' : '') . $dezenas[$d];
        }
        if ($u > 0) {
            $s .= ($s !== '' ? ' e ' : '') . $unidades[$u];
        }
    }
    return $s;
}

/**
 * Converte um número inteiro (até a casa dos quatrilhões) para extenso,
 * aplicando as regras de conjunção "e"/espaço entre grupos.
 *
 * @param int $n
 * @return string
 */
function numeroPorExtenso(int $n): string
{
    if ($n === 0) {
        return 'zero';
    }

    $grupoNomes = [
        '', 'mil', 'milhão', 'bilhão', 'trilhão', 'quatrilhão',
    ];
    $grupoNomesPlural = [
        '', 'mil', 'milhões', 'bilhões', 'trilhões', 'quatrilhões',
    ];

    // Divide o número em grupos de 3 dígitos, da direita para a esquerda
    $grupos = [];
    $resto = $n;
    do {
        $grupos[] = $resto % 1000;
        $resto = (int) floor($resto / 1000);
    } while ($resto > 0);

    // Constrói cada parte (valor + texto), do grupo maior para o menor
    $partes = [];
    for ($i = count($grupos) - 1; $i >= 0; $i--) {
        $g = $grupos[$i];
        if ($g === 0) {
            continue;
        }

        // Caso especial: "mil" em vez de "um mil"
        if ($i === 1 && $g === 1) {
            $partes[] = ['v' => $g, 't' => 'mil'];
            continue;
        }

        $texto = cardinalTres($g);
        if ($i > 0) {
            $nome = ($g === 1) ? $grupoNomes[$i] : $grupoNomesPlural[$i];
            $texto .= ' ' . $nome;
        }
        $partes[] = ['v' => $g, 't' => $texto];
    }

    // Junta as partes aplicando a regra da conjunção "e":
    //   usa "e" entre grupos quando o grupo da direita (menor) for < 100
    //   (sem centenas) ou for centena exata (% 100 == 0); caso contrário,
    //   usa espaço. Ex.: "mil e cem", "mil duzentos e trinta e quatro".
    $resultado = '';
    foreach ($partes as $k => $p) {
        if ($k === 0) {
            $resultado = $p['t'];
            continue;
        }
        $lower = $p['v'];
        $useE  = ($lower < 100) || ($lower % 100 === 0);
        $resultado .= ($useE ? ' e ' : ' ') . $p['t'];
    }

    return $resultado;
}