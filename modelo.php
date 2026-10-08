<?php
/**
 * modelo.php
 * Layout fixo do recibo (espelha o "DOCX modelo.docx"). Funções que montam
 * o conteúdo a partir de um array $dados já calculado.
 *
 * $dados = [
 *   'empresa'     => string,
 *   'valor_total' => float,
 *   'extenso'     => string,
 *   'periodo'     => string,    // "dd/mm/aaaa a dd/mm/aaaa" ou "Maio/2026"
 *   'data'        => string,    // dd/mm/aaaa ou aaaa-mm-dd
 *   'combustivel' => float,
 *   'alimentacao' => float,
 *   'nome'        => string,
 *   'cpf'         => string,
 *   'cidade'      => string,
 * ];
 */

require_once __DIR__ . '/extenso.php';

/**
 * Textos configuráveis do recibo. Cada entrada pode ser sobrescrita pelo
 * usuário em /configuracoes.php. Aqui ficam os defaults; o
 * _config_recibo.json (se existir) tem prioridade.
 *
 * As chaves são lidas por textoRecibo() e usadas em reciboHTML() e
 * corpoDoRecibo().
 */
function textosPadraoRecibo(): array
{
    return [
        'titulo_comprovante_vales'      => 'COMPROVANTE DE VALES',
        'titulo_comprovante_pagamento'  => 'COMPROVANTE DE PAGAMENTO',
        'titulo_comprovante_recibos'    => 'RECIBOS',
        'rotulo_auxilio'                => 'Auxílio combustível',
        'rotulo_vale'                   => 'Vale alimentação',
        'rotulo_aj'                     => 'AJ. CUSTO',
        'rotulo_comissao'               => 'Comissão',
        'rotulo_vale_gas'               => 'Vale Gás',
        'rotulo_prestacao_servicos'     => 'Prestação de Serviços',
        'rotulo_premiacao'              => 'Premiação',
        'frase_recebi'                  => 'Recebi da',
        'frase_referente_aux_va'        => 'referente a Auxílio Combustível e Vale Alimentação',
        'frase_referente_aux_va_aj'     => 'referente a Auxílio Combustível, Vale Alimentação e AJ. CUSTO',
        'frase_referente_recibos'       => 'referente a recibos conforme descrito abaixo.',
        'frase_pagamento'               => 'a título de pagamento de salário líquido',
        'fechamento'                    => 'E por ser verdade assino o presente recibo.',
        'conforme_descrito'             => 'conforme descrito abaixo.',
    ];
}

/**
 * Lê os textos configurados pelo usuário (em _config_recibo.json) e
 * devolve o valor de uma chave. Se o JSON não existe ou a chave sumiu,
 * retorna o default.
 */
function textoRecibo(string $chave): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = textosPadraoRecibo();
        $path = __DIR__ . '/_config_recibo.json';
        if (is_readable($path)) {
            $raw = @file_get_contents($path);
            $data = json_decode((string) $raw, true);
            if (is_array($data)) {
                $cache = array_merge($cache, $data);
            }
        }
    }
    return (string) ($cache[$chave] ?? '');
}

/** Formata um número como moeda BR: R$ 1.234,56 */
function formatarMoeda($valor): string
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

/** Formata CPF mantendo máscara. */
function formatarCpf(string $cpf): string
{
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) === 11) {
        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' .
               substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }
    return $cpf;
}

/** Nomes dos meses em pt-BR. */
function nomeMes(int $m): string
{
    $nomes = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];
    return $nomes[$m] ?? '';
}

/** Decompõe "dd/mm/aaaa" ou "aaaa-mm-dd" em [dia, mes, ano]. */
function decomporDataLocal(?string $data): array
{
    $data = trim((string) $data);
    if ($data === '') {
        return [null, null, null];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m)) {
        return [(int) $m[3], (int) $m[2], (int) $m[1]];
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m)) {
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
    return [null, null, null];
}

/** Converte o período em duas datas "00/00/0000". */
function periodoParaDatas(string $periodo): array
{
    $periodo = trim($periodo);
    if ($periodo === '') {
        return ['', ''];
    }
    $partes = preg_split('/\s*(?:a|até|ate|-)\s*/i', $periodo);
    $fmt = function ($p) {
        $p = trim($p);
        [$d, $m, $a] = decomporDataLocal($p);
        if ($d === null) {
            return $p;
        }
        return sprintf('%02d/%02d/%04d', $d, $m, $a);
    };
    $ini = $fmt($partes[0] ?? '');
    $fim = $fmt($partes[1] ?? ($partes[0] ?? ''));
    return [$ini, $fim];
}

/** Corpo corrido do recibo (igual ao do modelo DOCX). */
function corpoDoRecibo(array $d): string
{
    $empresa     = htmlspecialchars($d['empresa'] ?? '');
    $extenso     = htmlspecialchars(rtrim($d['extenso'] ?? '', '.'));
    $valorNum    = htmlspecialchars(formatarMoeda($d['valor_total'] ?? 0));

    [$perIni, $perFim] = periodoParaDatas($d['periodo'] ?? '');
    $perIni = htmlspecialchars($perIni);
    $perFim = htmlspecialchars($perFim);

    // Se o período veio vazio, omite o trecho "proveniente dos dias X a Y"
    // para não sobrar "proveniente dos dias a".
    $partePeriodo = '';
    if ($perIni !== '' || $perFim !== '') {
        $partePeriodo = "proveniente dos dias {$perIni} a {$perFim} ";
    }

    $tipo = (string) ($d['tipo'] ?? 'recibo');
    $temAj = ((float) ($d['aj_custo'] ?? 0)) > 0;
    $temCom = ((float) ($d['comissao'] ?? 0)) > 0;
    if ($tipo === 'comprovante_pagamento') {
        $fraseItens = textoRecibo('frase_pagamento') . ' ';
    } elseif ($tipo === 'comprovante_recibos') {
        $fraseItens = textoRecibo('frase_referente_recibos') . ' ';
    } elseif ($tipo === 'comprovante') {
        // Se tem AJ. CUSTO, cita os três; senão só Aux + VA.
        $fraseItens = $temAj
            ? textoRecibo('frase_referente_aux_va_aj') . ' '
            : textoRecibo('frase_referente_aux_va') . ' ';
    } else {
        $fraseItens = '';
    }
    // Quando há comissão, acrescenta "e Comissão" à relação de itens.
    if ($temCom && $tipo !== 'comprovante_recibos') {
        $fraseItens = rtrim($fraseItens) . ' e Comissão ';
    }

    // Frase com o número em destaque + o extenso entre parênteses.
    // Ex: "a importância de R$ 207,90 (Duzentos e sete reais e noventa centavos)".
    $recebi    = textoRecibo('frase_recebi');
    $conforme  = textoRecibo('conforme_descrito');
    if ($tipo === 'comprovante_recibos') {
        // Para recibos: frase sem "conforme descrito abaixo" extra (já incluído em frase_referente_recibos)
        return "{$recebi} {$empresa}, a importância de {$valorNum} ({$extenso}) "
             . "{$fraseItens}{$partePeriodo}";
    }
    return "{$recebi} {$empresa}, a importância de {$valorNum} ({$extenso}) "
         . "{$fraseItens}{$partePeriodo}{$conforme}";
}

/** Data do recibo por extenso: "00 de mês de 0000". */
function dataPorExtenso(?string $data): string
{
    [$d, $m, $a] = decomporDataLocal($data);
    if ($d === null) {
        return '';
    }
    return sprintf('%02d de %s de %04d', $d, nomeMes($m), $a);
}

/**
 * Renderiza o recibo em HTML (espelha o layout do DOCX modelo).
 *
 * Quando $autoPrint = true, injeta um script que abre a janela de
 * impressão do navegador assim que a página carrega e fecha a aba ao
 * terminar (modo "mandar para impressão").
 */
function reciboHTML(array $d, bool $autoPrint = false): string
{
    $empresa     = htmlspecialchars($d['empresa'] ?? '');
    $valorTotal  = formatarMoeda($d['valor_total'] ?? 0);
    $extenso     = htmlspecialchars($d['extenso'] ?? '');
    $combustivel = formatarMoeda($d['combustivel'] ?? 0);
    $alimentacao = formatarMoeda($d['alimentacao'] ?? 0);
    $ajCusto     = formatarMoeda($d['aj_custo'] ?? 0);
    $comissao    = formatarMoeda($d['comissao'] ?? 0);
    $valeGas          = formatarMoeda($d['vale_gas'] ?? 0);
    $prestacaoServicos = formatarMoeda($d['prestacao_servicos'] ?? 0);
    $premiacao        = formatarMoeda($d['premiacao'] ?? 0);
    $tipo        = (string) ($d['tipo'] ?? 'comprovante');
    if ($tipo === 'comprovante_pagamento') {
        $tituloDoc = textoRecibo('titulo_comprovante_pagamento') ?: 'COMPROVANTE DE PAGAMENTO';
    } elseif ($tipo === 'comprovante_recibos') {
        $tituloDoc = textoRecibo('titulo_comprovante_recibos') ?: 'RECIBOS';
    } else {
        $tituloDoc = textoRecibo('titulo_comprovante_vales') ?: 'COMPROVANTE DE VALES';
    }
    $nome        = htmlspecialchars($d['nome'] ?? '');
    $cpf         = htmlspecialchars(formatarCpf($d['cpf'] ?? ''));
    $cidade      = htmlspecialchars($d['cidade'] ?? '');
    $corpo       = corpoDoRecibo($d);
    $dataExt     = htmlspecialchars(dataPorExtenso($d['data'] ?? ''));

    // Rótulos configuráveis pelo usuário em /configuracoes.php
    $rotAuxilio  = htmlspecialchars(textoRecibo('rotulo_auxilio'));
    $rotVale     = htmlspecialchars(textoRecibo('rotulo_vale'));
    $rotAj       = htmlspecialchars(textoRecibo('rotulo_aj'));
    $rotCom      = htmlspecialchars(textoRecibo('rotulo_comissao'));
    $rotValeGas  = htmlspecialchars(textoRecibo('rotulo_vale_gas'));
    $rotPrestacao = htmlspecialchars(textoRecibo('rotulo_prestacao_servicos'));
    $rotPremiacao = htmlspecialchars(textoRecibo('rotulo_premiacao'));
    $fechamento  = htmlspecialchars(textoRecibo('fechamento'));

    // Linha extra de "AJ. CUSTO" só aparece quando tem valor. Se o AJ
    // está zerado, não mostra linha nem "e AJ. CUSTO" no corpo — fica
    // só Auxílio Combustível + Vale Alimentação.
    $mostrarAj = ((float) ($d['aj_custo'] ?? 0)) > 0;
    $linhaAj = '';
    if ($tipo === 'comprovante' && $mostrarAj) {
        $linhaAj = '<div class="linha">'
                 . '<span class="rot">' . $rotAj . '</span>'
                 . '<span class="vlr">' . $ajCusto . '</span>'
                 . '</div>';
    }
    // Linha extra de "Comissão" só aparece quando tem valor (presente
    // para alguns funcionários, ex.: aba FS da folha de pagamento).
    $mostrarCom = ((float) ($d['comissao'] ?? 0)) > 0;
    $linhaCom = '';
    if ($mostrarCom && $tipo !== 'comprovante_recibos') {
        $linhaCom = '<div class="linha">'
                 . '<span class="rot">' . $rotCom . '</span>'
                 . '<span class="vlr">' . $comissao . '</span>'
                 . '</div>';
    }

    // Monta o bloco de linhas da descrição
    if ($tipo === 'comprovante_recibos') {
        // Para recibos: apenas os 5 campos com valor > 0
        $linhasDescricao = '';
        $camposRecibos = [
            $rotAuxilio   => (float)($d['combustivel'] ?? 0),
            $rotVale      => (float)($d['alimentacao'] ?? 0),
            $rotValeGas   => (float)($d['vale_gas'] ?? 0),
            $rotPrestacao => (float)($d['prestacao_servicos'] ?? 0),
            $rotPremiacao => (float)($d['premiacao'] ?? 0),
        ];
        foreach ($camposRecibos as $rot => $val) {
            if ($val > 0) {
                $linhasDescricao .= '<div class="linha">'
                    . '<span class="rot">' . $rot . '</span>'
                    . '<span class="vlr">' . formatarMoeda($val) . '</span>'
                    . '</div>';
            }
        }
    } else {
        $linhasDescricao = '<div class="linha">'
            . '<span class="rot">' . $rotAuxilio . '</span>'
            . '<span class="vlr">' . $combustivel . '</span>'
            . '</div>'
            . '<div class="linha">'
            . '<span class="rot">' . $rotVale . '</span>'
            . '<span class="vlr">' . $alimentacao . '</span>'
            . '</div>'
            . $linhaAj
            . $linhaCom;
    }

    // Logo: usa a imagem da empresa (igual ao DOCX) embutida em base64.
    // Quando não há logo_path (FS), usa a imagem extraída do próprio
    // modelo DOCX (word/media/image1.jpeg) para fidelidade total.
    $logoHtml = '';
    $logoCandidatos = array_filter([
        $d['logo_path'] ?? null,
        __DIR__ . '/_logo_fs.jpeg',
        __DIR__ . '/WhatsApp Image 2026-08-06 at 8.52.03 AM.jpeg',
        __DIR__ . '/WhatsApp Image 2026-08-06 at 8.51.33 AM.jpeg',
    ]);
    foreach ($logoCandidatos as $candidato) {
        if ($candidato && is_readable($candidato)) {
            $mime = (strtolower(pathinfo($candidato, PATHINFO_EXTENSION)) === 'png') ? 'image/png' : 'image/jpeg';
            $b64  = base64_encode((string) file_get_contents($candidato));
            $logoHtml = '<img src="data:' . $mime . ';base64,' . $b64 . '" alt="' . $empresa . '">';
            break;
        }
    }
    if ($logoHtml === '') {
        // Fallback: texto da empresa (comportamento antigo)
        $logoHtml = '<strong style="font-family:Verdana;font-size:14pt;">' . $empresa . '</strong>';
    }

    // Script de impressão automática (modo "Imprimir"). Dispara o dialog
    // de impressão ao carregar e fecha a aba quando termina.
    $scriptPrint = $autoPrint
        ? "\n<script>window.addEventListener('load',function(){"
          . "  setTimeout(function(){window.focus();window.print();},200);"
          . "});"
          . "window.onafterprint=function(){window.close();};</script>"
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{$tituloDoc} - {$nome}</title>
<style>
  @page { size: A4; margin: 1.2cm 2.6cm 0.5cm 2.3cm; }
  * { box-sizing: border-box; }
  body {
    font-family: "Times New Roman", Georgia, serif;
    color: #000;
    margin: 0;
    padding: 24px 48px;
  }
  .papel { max-width: 760px; margin: 0 auto; padding: 8px 0 0; }
  .logo { text-align: left; margin: 0 0 18px; }
  .logo img { max-width: 240px; height: auto; }
  h1 {
    text-align: center;
    font-family: Verdana, sans-serif;
    font-weight: bold;
    font-size: 22pt;
    letter-spacing: 3px;
    margin: 0 0 6px;
  }
  .corpo {
    font-size: 14pt;
    line-height: 1.7;
    text-align: justify;
    margin: 18px 0 16px;
  }
  .descricao {
    font-size: 12pt;
    line-height: 1.8;
    text-align: justify;
    margin: 0 0 18px;
  }
  .descricao .linha {
    display: flex; justify-content: space-between; gap: 24px;
  }
  .descricao .rot { white-space: nowrap; }
  .descricao .vlr { font-family: inherit; }
  .fechamento {
    font-size: 12pt;
    line-height: 1.6;
    text-align: justify;
    margin-top: 10px;
  }
  .linha-ass-preta {
    height: 1px;
    background: #000;
    margin: 60px auto 0;
    width: 70%;
  }
  .linha-ass-preta.curta {
    height: 1px;
    background: #000;
    margin: 12px auto 6px;
    width: 28%;
  }
  .nome-ass {
    text-align: center;
    font-family: Calibri, Arial, sans-serif;
    font-size: 14pt;
    margin-top: 6px;
  }
  .cpf-ass {
    text-align: center;
    font-size: 12pt;
    margin-top: 14px;
    border-bottom: 1px solid #000;
    padding-bottom: 2px;
    width: 60%;
    margin-left: auto;
    margin-right: auto;
  }
  @media print {
    body { padding: 0; }
    .papel { border: none; padding: 0; }
  }
</style>
</head>
<body>
  <div class="papel">

    <div class="logo">
      {$logoHtml}
    </div>

    <h1>{$tituloDoc}</h1>

    <p class="corpo">{$corpo}</p>

    <div class="descricao">
      {$linhasDescricao}
    </div>

    <p class="fechamento">
      {$fechamento} {$cidade}, {$dataExt}.
    </p>

    <div class="linha-ass-preta"></div>
    <div class="nome-ass">{$nome}</div>
    <div class="cpf-ass">CPF: {$cpf}</div>

  </div>
{$scriptPrint}
</body>
</html>
HTML;
}
