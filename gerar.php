<?php
/**
 * gerar.php
 * Recebe os dados do formulário (ou um CSV em lote), calcula o valor total,
 * gera o valor por extenso e produz o recibo em HTML (tela/impressão) ou
 * DOCX (download). O DOCX é gerado a partir do modelo "DOCX modelo.docx"
 * preservando 100% do layout (imagem do cabeçalho, fontes, linhas de
 * assinatura, espaçamentos). Os placeholders do modelo são sobrescritos
 * pelos dados do recibo. No modo CSV, gera um .zip com um DOCX por
 * funcionário.
 */

require_once __DIR__ . '/extenso.php';
require_once __DIR__ . '/modelo.php';

/* ---------------------------------------------------------------------------
 * Helpers de cálculo
 * ------------------------------------------------------------------------- */

/** Converte "1.234,56" ou "1234.56" para float. */
function parseValor($v): float
{
    if (is_numeric($v)) {
        return (float) $v;
    }
    $v = (string) $v;
    $v = trim($v);
    $v = preg_replace('/[^\d,.\-]/', '', $v);
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (strpos($v, ',') !== false) {
        $v = str_replace(',', '.', $v);
    }
    return (float) $v;
}

/**
 * Versão "folha de pagamento" do parseValor. Se o valor veio como string
 * não-numérica ("cartão", "Dispensada 30/07", "x", "S/ Aux."), devolve 0
 * em vez de tentar extrair dígitos. Evita o bug em que "Dispensada 30/07"
 * virava 3007 por causa do "30/07" que sobrou.
 */
function parseValorFolha($v): float
{
    if ($v === null || $v === '') return 0.0;
    if (is_numeric($v)) return (float) $v;
    $s = trim((string) $v);
    // Texto que claramente NÃO é monetário
    if (preg_match('/[a-záéíóúçãâêôà]/i', $s)) {
        return 0.0;
    }
    return parseValor($s);
}

/** Calcula e devolve o conjunto de dados pronto para o modelo. */
function montarDados(array $entrada): array
{
    // Valida e resolve a chave da empresa. Aceita tanto a chave (recomendado,
    // ex.: 'fs') quanto um nome livre (legado / pré-multi-empresa).
    $chaveOuNome = trim((string) ($entrada['empresa'] ?? ''));
    $empresaChave = null;
    $logoPath = null;
    if ($chaveOuNome !== '' && array_key_exists($chaveOuNome, EMPRESAS)) {
        $empresaChave = $chaveOuNome;
        $empresaNome = EMPRESAS[$chaveOuNome]['nome'];
        $logoPath = EMPRESAS[$chaveOuNome]['logo'];
    } elseif ($chaveOuNome !== '') {
        // Compatibilidade: aceita texto livre como nome (ex.: testes).
        $empresaNome = $chaveOuNome;
    } else {
        $empresaNome = '';
    }

    $combustivel = parseValor($entrada['combustivel'] ?? 0);
    $alimentacao = parseValor($entrada['alimentacao'] ?? 0);
    $ajCusto     = parseValor($entrada['aj_custo'] ?? 0);
    $comissao       = parseValor($entrada['comissao'] ?? 0);
    $salarioLiquido = parseValor($entrada['salario_liquido'] ?? 0);
    $salarioBruto   = parseValor($entrada['salario_bruto'] ?? 0);

    $tipoBruto = (string) ($entrada['tipo'] ?? 'comprovante');
    if ($tipoBruto === 'comprovante_pagamento') {
        $tipo = 'comprovante_pagamento';
    } else {
        $tipo = 'comprovante';
    }

    // Total exibido no corpo.
    //   - 'comprovante'           = Aux + VA + AJ. CUSTO (se houver)
    //   - 'comprovante_pagamento' = Salário já com os descontos dos vales
    //                                (salario_liquido da planilha, se
    //                                 preenchido; senão calcula como
    //                                 salario_bruto - combustivel -
    //                                 alimentacao - aj_custo)
    //   - qualquer outro valor    = tratado como 'comprovante' (padrão)
    if ($tipo === 'comprovante_pagamento') {
        if ($salarioLiquido > 0) {
            // Planilha já trouxe o líquido calculado (planilha antiga,
            // ou futuras folhas com essa coluna). Usa o que veio.
            $total = $salarioLiquido;
        } elseif ($salarioBruto > 0) {
            // Folha de Agosto (sem coluna Salário Líquido): calcula o
            // líquido = Bruto - Aux - VA - AJ.CUSTO. Limita em zero
            // caso os vales excedam o salário.
            $descontos = $combustivel + $alimentacao + $ajCusto;
            $total = max(0, round($salarioBruto - $descontos, 2));
        } else {
            // Sem nenhum dado de salário — não há como emitir.
            $total = 0;
        }
        // Comissão é somada ao total também no Comprovante de Pagamento
        // (exibida como linha e acrescentada ao valor pago).
        $total = round($total + $comissao, 2);
    } else {
        $total = round($combustivel + $alimentacao + $ajCusto + $comissao, 2);
    }

    return [
        'empresa'         => $empresaNome,
        'empresa_chave'   => $empresaChave,
        'logo_path'       => $logoPath,
        'combustivel'     => $combustivel,
        'alimentacao'     => $alimentacao,
        'aj_custo'        => $ajCusto,
        'comissao'        => $comissao,
        'salario_liquido' => $salarioLiquido,
        'salario_bruto'   => $salarioBruto,
        'tipo'            => $tipo,
        'valor_total'     => $total,
        'extenso'         => valorPorExtenso($total),
        'periodo'        => trim($entrada['periodo'] ?? ''),
        'data'           => trim($entrada['data'] ?? ''),
        'nome'           => trim($entrada['nome'] ?? ''),
        'cpf'            => trim($entrada['cpf'] ?? ''),
        'cidade'         => trim($entrada['cidade'] ?? ''),
    ];
}

/* ---------------------------------------------------------------------------
 * Geração de DOCX a partir do modelo (preserva layout/fonts/imagem/linhas)
 * ------------------------------------------------------------------------- */

const MODELO_DOCX    = __DIR__ . '/DOCX modelo.docx';
const MODELO_DOCX_FS = __DIR__ . '/_modelo_recibo.bin';

/**
 * Empresas suportadas. Cada entrada mapeia a chave (vinda do formulário)
 * para um array com:
 *   - nome:  rótulo que aparece no corpo do recibo e substitui "FS
 *            Administradora de Cartões" no XML do modelo.
 *   - logo:  caminho (absoluto ou relativo à pasta do projeto) do arquivo
 *            de imagem que substituirá word/media/image1.jpeg no DOCX.
 *            Use null para manter a logo original do modelo.
 *
 * Para acrescentar uma empresa nova: copie o JPEG na pasta e adicione a
 * entrada abaixo. A logo é embutida no DOCX sem alterar as relações
 * (o nome interno "image1.jpeg" é preservado e o rId4 não muda).
 */
const EMPRESAS = [
    'fs'          => [
        'nome' => 'FS Administradora de Cartões',
        'logo' => null,
    ],
    'clinica'     => [
        'nome' => 'Clínica Saúde',
        'logo' => __DIR__ . '/WhatsApp Image 2026-08-06 at 8.52.03 AM.jpeg',
    ],
    'laboratorio' => [
        'nome' => 'Laboratório',
        'logo' => __DIR__ . '/WhatsApp Image 2026-08-06 at 8.51.33 AM.jpeg',
    ],
];

/** Altura da faixa de logo no DOCX, em EMU. 873918 EMU = ~0,91 cm. */
const LOGO_CY_EMU    = 873918;
/** Largura máxima da logo, em EMU. 5760000 EMU = ~16 cm (cabe na página A4). */
const LOGO_CX_MAX_EMU = 5760000;

/**
 * Devolve o nome da empresa a partir da chave recebida, ou null se for inválida.
 */
function resolverEmpresa(?string $chave): ?string
{
    if ($chave === null) {
        return null;
    }
    return EMPRESAS[$chave]['nome'] ?? null;
}

/**
 * Devolve o caminho de um arquivo temporário com a cópia binária do modelo.
 * Evita múltiplas leituras em modo lote.
 */
function carregarModeloBinario(): string
{
    if (!is_readable(MODELO_DOCX)) {
        throw new RuntimeException('Arquivo "DOCX modelo.docx" não encontrado na pasta do sistema.');
    }
    if (!is_readable(MODELO_DOCX_FS)) {
        copy(MODELO_DOCX, MODELO_DOCX_FS);
    }
    return MODELO_DOCX_FS;
}

/** Escapa texto para uso seguro dentro do XML do DOCX. */
function xml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Ajusta o tamanho do <w:drawing> no XML para preservar a proporção da logo
 * da empresa, respeitando a altura fixa da faixa (LOGO_CY_EMU) e o limite
 * de largura (LOGO_CX_MAX_EMU).
 *
 * Estratégia:
 *   - Lê as dimensões (em pixels) do arquivo de imagem.
 *   - Calcula cx = cy * (largura_px / altura_px).
 *   - Se cx passar do limite, escalamos pela largura máxima e recalculamos
 *     cy para manter a proporção (a faixa fica um pouco mais alta).
 *
 * Para a FS (logo nula) não mexe em nada, preservando o desenho original.
 */
function ajustarTamanhoLogo(string $xml, ?string $logoPath): string
{
    if ($logoPath === null) {
        return $xml;
    }
    if (!is_readable($logoPath)) {
        error_log('ajustarTamanhoLogo: logo não encontrada em ' . $logoPath);
        return $xml;
    }

    [$w, $h] = jpegDimensions($logoPath);
    if ($w <= 0 || $h <= 0) {
        return $xml;
    }

    $cx = (int) round(LOGO_CY_EMU * ($w / $h));
    $cy = LOGO_CY_EMU;
    if ($cx > LOGO_CX_MAX_EMU) {
        $cx = LOGO_CX_MAX_EMU;
        $cy = (int) round(LOGO_CX_MAX_EMU * ($h / $w));
    }

    // Substitui o primeiro <wp:extent cx="X" cy="Y"/> pelo novo tamanho.
    // O modelo tem EXATAMENTE uma ocorrência em <wp:inline>.
    $padrao = '#<wp:extent cx="\d+" cy="\d+"/>#';
    $novo   = '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>';
    $nova   = preg_replace($padrao, $novo, $xml, 1);
    if ($nova === null) {
        return $xml;
    }

    // O <pic:spPr><a:ext cx="..." cy="..."/> duplica o tamanho do desenho
    // (especificações OOXML exigem que ambos os tamanhos batam). Trocamos
    // também essa ocorrência.
    $padraoSp = '#<a:ext cx="\d+" cy="\d+"/>#';
    $nova     = preg_replace($padraoSp, '<a:ext cx="' . $cx . '" cy="' . $cy . '"/>', $nova, 1);

    return $nova ?? $xml;
}

/** Devolve [largura_px, altura_px] de um arquivo JPEG, ou [0, 0] se falhar. */
function jpegDimensions(string $path): array
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 4) {
        return [0, 0];
    }
    $i = 2;
    $len = strlen($data);
    while ($i < $len) {
        // Procura próximo marker 0xFF xx
        while ($i < $len && ord($data[$i]) !== 0xFF) {
            $i++;
        }
        if ($i + 1 >= $len) {
            return [0, 0];
        }
        $marker = ord($data[$i + 1]);
        // Marcadores SOF (Start Of Frame) carregam largura/altura
        if (($marker >= 0xC0 && $marker <= 0xC3)
         || ($marker >= 0xC5 && $marker <= 0xC7)
         || ($marker >= 0xC9 && $marker <= 0xCB)
         || ($marker >= 0xCD && $marker <= 0xCF)) {
            $h = unpack('n', substr($data, $i + 5, 2))[1];
            $w = unpack('n', substr($data, $i + 7, 2))[1];
            return [$w, $h];
        }
        if ($i + 3 >= $len) {
            return [0, 0];
        }
        $segLen = unpack('n', substr($data, $i + 2, 2))[1];
        $i += 2 + $segLen;
    }
    return [0, 0];
}

/** Lê o conteúdo de um arquivo dentro de um ZIP usando ZipArchive. */
function lerEntradaZip(string $zipPath, string $entry): string
{
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        throw new RuntimeException('Não foi possível abrir o arquivo: ' . $zipPath);
    }
    $conteudo = $za->getFromName($entry);
    $za->close();
    if ($conteudo === false) {
        throw new RuntimeException('Entrada "' . $entry . '" não encontrada no zip.');
    }
    return $conteudo;
}

/**
 * Divide "dd/mm/aaaa" em [dia, mes, ano] numéricos. Aceita "aaaa-mm-dd" também.
 */
function decomporData(?string $data): array
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

/** Converte o período "dd/mm/aaaa" → "00/00/0000" textual para o modelo. */
function periodoParaPlaceholders(string $periodo): array
{
    // Aceita "dd/mm/aaaa a dd/mm/aaaa", "dd/mm/aaaa - dd/mm/aaaa",
    // "dd/mm/aaaa" ou "Maio/2026".
    $periodo = trim($periodo);
    if ($periodo === '') {
        return ['ini' => '', 'fim' => ''];
    }
    $partes = preg_split('/\s*(?:a|até|ate|-)\s*/i', $periodo);
    $fmt = function ($p) {
        $p = trim($p);
        [$d, $m, $a] = decomporData($p);
        if ($d === null) {
            return $p; // texto livre, ex.: "Maio/2026"
        }
        return sprintf('%02d/%02d/%04d', $d, $m, $a);
    };
    return [
        'ini' => $fmt($partes[0] ?? ''),
        'fim' => $fmt($partes[1] ?? ($partes[0] ?? '')),
    ];
}

/**
 * Aplica as substituições no XML do documento preservando o texto ao redor
 * de cada placeholder. Como os placeholders no modelo estão fragmentados
 * em vários <w:r> (efeito de revisão/track changes), trocamos cada parte
 * individualmente.
 */
function preencherXmlDoModelo(string $xml, array $d): string
{
    $empresa     = $d['empresa'];
    // O modelo DOCX já tem "R$" literal antes do placeholder
    // ("Auxilio combustível R$ {Valor de combustivel}"). formatarMoeda
    // devolve "R$ X,XX" (formato usado no HTML, que não tem o "R$" literal);
    // para o DOCX removemos o prefixo para não duplicar ("R$ R$ 0,00").
    $combustivel = preg_replace('/^R\$\s*/', '', formatarMoeda($d['combustivel']));
    $alimentacao = preg_replace('/^R\$\s*/', '', formatarMoeda($d['alimentacao']));
    $extenso     = $d['extenso']; // já vem com ponto final
    $nome        = $d['nome'];
    $cpfFmt      = formatarCpf($d['cpf']);

    // Período → duas datas no formato 00/00/0000
    $per = periodoParaPlaceholders($d['periodo']);
    $periodoIni = $per['ini'];
    $periodoFim = $per['fim'] !== '' ? $per['fim'] : $per['ini'];

    // Data do recibo → por extenso: "00 de mês de 0000"
    [$dDia, $dMes, $dAno] = decomporData($d['data']);
    $dataExt = '';
    if ($dDia !== null) {
        $dataExt = sprintf('%02d de %s de %04d', $dDia, nomeMes($dMes), $dAno);
    }
    $mesExt  = $dMes !== null ? nomeMes($dMes) : '';
    $anoExt  = $dAno !== null ? str_pad((string) $dAno, 4, '0', STR_PAD_LEFT) : '';
    $diaExt  = $dDia !== null ? str_pad((string) $dDia, 2, '0', STR_PAD_LEFT) : '';

    // Cidade + "0" usado como prefixo do dia no trecho "0{mes}de 0000"
    // No XML aparece: "0" "0" "de" " {mes}" " de" " " "0000"
    // Vamos tratar dia e mês pelos placeholders {mes} e os 0s soltos.

    /* O modelo original tem:
     *  - "Recebi da FS Administradora de Cartões" → trocar pelo nome da empresa
     *  - "valor por exetenço" → extenso
     *  - primeiro par {00/00/0000} → data inicial do período
     *  - segundo par {00/00/0000} → data final do período
     *  - "{Valor de combustivel}" → valor combustível
     *  - "{Valor de alimentaçao }" → valor alimentação (modelo usa "alimentaçao" sem til)
     *  - "0" "0" "de" " {mes}" " de" " " "0000" → "00 de {mes} de 0000" (data por extenso)
     *  - "{Nome}" → nome
     *  - Linha de CPF → cpf
     */

    // 0) Ajusta o tamanho do desenho da logo conforme o aspect ratio da
    //    imagem da empresa. A logo da FS (nula) preserva o desenho original.
    if (!empty($d['logo_path'])) {
        $xml = ajustarTamanhoLogo($xml, $d['logo_path']);
    }

    // 1) Troca nome da empresa (texto fixo no modelo: "FS Administradora de Cartões")
    $xml = str_replace('FS Administradora de Cartões', $empresa, $xml);

    // 1b) Troca a cidade no fechamento. No modelo, o trecho "...recibo. Campo"
    //     está tudo em um único <w:t>, e "Grande" está em um <w:t> separado.
    //     Substituímos a sequência "recibo. Campo" + run do "Grande" por uma
    //     run com a cidade do usuário.
    $cidade = trim($d['cidade'] ?? '');
    if ($cidade !== '') {
        $padraoCidade = '#recibo\. Campo</w:t></w:r>\s*'
                      . '<w:r><w:rPr><w:spacing w:val="-5"/></w:rPr><w:t xml:space="preserve"> </w:t></w:r>\s*'
                      . '<w:r><w:t>Grande</w:t></w:r>#s';
        $xml = preg_replace(
            $padraoCidade,
            'recibo. ' . xml($cidade) . '</w:t></w:r>',
            $xml,
            1
        );
    }

    // 2) Extenso + número. No modelo a frase é fragmentada em três runs:
//      "a importância de R$ (" + "valor por exetenço " + ") proveniente"
//    O parêntese de abertura está na PRIMEIRA run, junto com o "R$ ".
//    Inserimos o número ("R$ 207,90") antes do "valor por exetenço" e
//    mantemos o "R$ (" original. Resultado:
//      "a importância de R$ (207,90 (Duzentos e sete reais e noventa centavos. )proveniente"
//
//    Pra evitar o "( (" duplicado, removemos o "R$ (" original depois,
//    de modo que o resultado final seja:
//      "a importância de R$ 207,90 (Duzentos e sete reais e noventa centavos. )proveniente"
    $valorNumDocx = preg_replace('/^R\$\s*/', '', formatarMoeda($d['valor_total'] ?? 0));
    $xml = str_replace('valor por exetenço', "{$valorNumDocx} ({$extenso}", $xml);
    // O parêntese de abertura original "R$ (" agora é só "R$ " (sem "(")
    // porque o "(" passa a vir logo antes do número injetado.
    $xml = str_replace('a importância de R$ (', 'a importância de R$ ', $xml);

    // 3) Datas do período. No modelo a 1ª data está fragmentada como
    //    { 0 0 /0 0 }  (dd/mm, sem ano) e a 2ª como { 0 0 /0 0 / 0000 }
    //    (dd/mm/aaaa), cada parte em um <w:t> separado.
    //    Substituímos a sequência EXATA de runs por uma única run com a data.

    // 1ª data: { + 0 + 0 + /0 + 0 }  → dd/mm
    $padrao1 = '#<w:r w:rsidR="00902927"><w:t>\{</w:t></w:r>'
             . '<w:r><w:t>0</w:t></w:r>'
             . '<w:r w:rsidR="00902927"><w:t>0</w:t></w:r>'
             . '<w:r><w:t>/0</w:t></w:r>'
             . '<w:r w:rsidR="00902927"><w:t>0\}</w:t></w:r>#s';
    $xml = preg_replace(
        $padrao1,
        '<w:r><w:t xml:space="preserve">' . xml($periodoIni) . '</w:t></w:r>',
        $xml,
        1
    );

    // 2ª data: { + 0 + 0 + /0 + 0 + / + 0000 }  → dd/mm/aaaa
    $padrao2 = '#<w:r w:rsidR="00902927"><w:t>\{</w:t></w:r>'
             . '<w:r><w:t>0</w:t></w:r>'
             . '<w:r w:rsidR="00902927"><w:t>0</w:t></w:r>'
             . '<w:r><w:t>/0</w:t></w:r>'
             . '<w:r w:rsidR="00902927"><w:t>0</w:t></w:r>'
             . '<w:r><w:t>/</w:t></w:r>'
             . '<w:r w:rsidR="00902927"><w:t>0000\}</w:t></w:r>#s';
    $xml = preg_replace(
        $padrao2,
        '<w:r><w:t xml:space="preserve">' . xml($periodoFim) . '</w:t></w:r>',
        $xml,
        1
    );

    // 4) Valor do combustível
    $xml = str_replace('{Valor de combustivel}', $combustivel, $xml);
    // 5) Valor da alimentação (modelo tem "alimentaçao" com ç, sem til).
    //    Quando há comissão, injetamos um parágrafo extra "Comissão R$ X,XX"
    //    logo após o parágrafo da alimentação, espelhando o mesmo layout
    //    (label + tab + "R$" + valor). A âncora é a run inteira do
    //    placeholder da alimentação, que é única no documento.
    $comissao = (float) ($d['comissao'] ?? 0);
    $alimAnchor = '{Valor de alimentaçao }</w:t></w:r></w:p>';
    $alimReplace = $alimentacao . '</w:t></w:r></w:p>';
    if ($comissao > 0) {
        $comissaoFmt = preg_replace('/^R\$\s*/', '', formatarMoeda($comissao));
        // Estrutura idêntica à linha "Vale alimentação" do modelo:
        // tab à esquerda (pos 5666), "R$" literal, valor sem prefixo.
        $pComissao = '<w:p w:rsidR="00DE4B3A" w:rsidRDefault="00000000">'
            . '<w:pPr><w:tabs><w:tab w:val="left" w:pos="5666"/></w:tabs>'
            . '<w:spacing w:before="41"/><w:ind w:left="1"/><w:jc w:val="both"/>'
            . '<w:rPr><w:sz w:val="24"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:sz w:val="24"/></w:rPr><w:t>Comissão</w:t></w:r>'
            . '<w:r><w:rPr><w:sz w:val="24"/></w:rPr><w:tab/><w:t>R$</w:t></w:r>'
            . '<w:r><w:rPr><w:spacing w:val="-4"/><w:sz w:val="24"/></w:rPr>'
            . '<w:t xml:space="preserve"> </w:t></w:r>'
            . '<w:r w:rsidR="00902927"><w:rPr><w:spacing w:val="-4"/><w:sz w:val="24"/></w:rPr>'
            . '<w:t xml:space="preserve">' . xml($comissaoFmt) . '</w:t></w:r></w:p>';
        $alimReplace .= $pComissao;
    }
    $xml = str_replace($alimAnchor, $alimReplace, $xml);
    // Variante sem espaço (caso o modelo não tenha o espaço final)
    $xml = str_replace('{Valor de alimentaçao}', $alimentacao, $xml);

    // 6) Data por extenso. No modelo o trecho (após o "MS – ") é:
    //    <w:r>...<w:t>0</w:t></w:r>
    //    <w:r w:rsidR="00902927"><w:t>0</w:t></w:r>
    //    <w:r><w:rPr><w:spacing w:val="-2"/></w:rPr><w:t xml:space="preserve"> </w:t></w:r>
    //    <w:r><w:t>de</w:t></w:r>...
    //    O dia é renderizado pelo Word concatenando o conteúdo das duas
    //    primeiras runs ("0" + "0" = "00"). Para colocar o dia real SEM
    //    duplicar, gravamos o valor na PRIMEIRA run e esvaziamos a SEGUNDA
    //    (a que tem rsidR, associada a track-changes).
    if ($dDia !== null) {
        // Âncora antes do "–" (U+2013 EN DASH) para não casar com o "0" das
        // datas do período. Usamos \x{2013} + flag /u porque o arquivo
        // PHP é lido como cp1252 no Windows e o caractere literal "–" vira
        // bytes errados. O /u força interpretação UTF-8 do regex.
        // Usamos preg_replace_callback porque o preg_replace com $1/$2/etc
        // está comendo grupos no PHP 8.4 quando há escapes \x{...}.
        $padraoDia = '#(<w:t>\x{2013}</w:t></w:r>\s*<w:r><w:rPr><w:spacing w:val="-3"/></w:rPr><w:t xml:space="preserve"> </w:t></w:r>\s*<w:r><w:t>)0(</w:t></w:r>\s*<w:r w:rsidR="00902927"><w:t>)0(</w:t></w:r>\s*<w:r><w:rPr><w:spacing w:val="-2"/></w:rPr><w:t xml:space="preserve"> </w:t></w:r>\s*<w:r><w:t>)de(</w:t>)#us';
        $xml = preg_replace_callback(
            $padraoDia,
            function ($m) use ($diaExt) {
                // $m[1] = abertura antes do 1º "0"  (mantém)
                // $m[2] = abertura antes do 2º "0"  (vai vazio)
                // $m[3] = abertura antes do "de"   (mantém)
                // $m[4] = </w:t> do "de"           (mantém)
                return $m[1] . $diaExt . $m[2] . '' . $m[3] . 'de' . $m[4];
            },
            $xml,
            1
        );
    }
    if ($mesExt !== '') {
        $xml = str_replace(' {mes}', ' ' . $mesExt, $xml);
        $xml = str_replace('{mes}', $mesExt, $xml);
    }
    if ($anoExt !== '') {
        // "0000" final antes do "."  (e não seguido de })
        $padraoAno = '#(<w:r w:rsidR="00902927"><w:t>)0000(</w:t></w:r><w:r><w:t>\.</w:t>)#s';
        $xml = preg_replace_callback(
            $padraoAno,
            function ($m) use ($anoExt) {
                return $m[1] . $anoExt . $m[2];
            },
            $xml,
            1
        );
    }

    // 7) Nome na assinatura
    $xml = str_replace('{Nome}', $nome, $xml);

    // 8) CPF na linha de assinatura (substitui o tab/sublinhado por "CPF: 000.000.000-00")
    if ($cpfFmt !== '') {
        // O modelo tem: "CPF:" <w:tab/> (com sublinhado no rPr do tab).
        // Substituímos a run do tab por uma run com texto do CPF.
        $xml = preg_replace(
            '#(<w:r>\s*<w:rPr>\s*<w:rFonts w:ascii="Times New Roman"/>\s*<w:sz w:val="28"/>\s*<w:u w:val="single"/>\s*</w:rPr>\s*<w:tab/>\s*</w:r>)#s',
            '<w:r><w:rPr><w:rFonts w:ascii="Times New Roman"/><w:sz w:val="28"/></w:rPr><w:t xml:space="preserve"> ' . $cpfFmt . '</w:t></w:r>',
            $xml,
            1
        );
    }

    return $xml;
}

/**
 * Gera o binário DOCX final a partir do modelo preenchido.
 * Copia o modelo para um zip temporário, substitui apenas word/document.xml.
 * Se a empresa tiver uma logo customizada, substitui também
 * word/media/image1.jpeg (mantendo o nome/rId para não invalidar relações).
 */
function gerarDocx(array $dados): string
{
    $src = carregarModeloBinario();
    $xmlOriginal = lerEntradaZip($src, 'word/document.xml');
    $xmlNovo = preencherXmlDoModelo($xmlOriginal, $dados);

    // Lê a logo da empresa (se houver) para substituir no DOCX.
    $logoBin = null;
    if (!empty($dados['logo_path']) && is_readable($dados['logo_path'])) {
        $logoBin = file_get_contents($dados['logo_path']);
    }

    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'recibo') . '.docx';
    if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Não foi possível criar o DOCX.');
    }

    // Copia todos os arquivos do modelo. Substitui word/document.xml e
    // word/media/image1.jpeg quando for o caso.
    $orig = new ZipArchive();
    $orig->open($src);
    $logoJaSubstituida = false;
    for ($i = 0; $i < $orig->numFiles; $i++) {
        $entry = $orig->getNameIndex($i);
        if ($entry === 'word/document.xml') {
            continue;
        }
        if ($entry === 'word/media/image1.jpeg' && $logoBin !== null) {
            $zip->addFromString($entry, $logoBin);
            $logoJaSubstituida = true;
            continue;
        }
        $conteudo = $orig->getFromIndex($i);
        $zip->addFromString($entry, $conteudo);
    }
    $orig->close();

    // Se a entrada word/media/image1.jpeg não existir no modelo original
    // (improvável, mas defensivo), criamos a partir da logo.
    if ($logoBin !== null && !$logoJaSubstituida) {
        $zip->addFromString('word/media/image1.jpeg', $logoBin);
    }

    $zip->addFromString('word/document.xml', $xmlNovo);
    $zip->close();

    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Sanitiza um nome para uso em nome de arquivo. */
function nomeArquivo(string $nome, string $ext = 'docx'): string
{
    $nome = trim($nome);
    $nome = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
    $nome = preg_replace('/[^A-Za-z0-9 _-]/', '', $nome);
    $nome = preg_replace('/\s+/', '_', $nome);
    $nome = trim($nome, "_ \t");
    if ($nome === '') {
        $nome = 'recibo';
    }
    return $nome . '.' . $ext;
}

/**
 * Garante um nome de arquivo único dentro do zip: se o base já foi usado,
 * sufixa com _2, _3, ... Evita que homônimos (ex.: "Carmem" na FS e na
 * Clínica) se sobrescrevam no lote.
 */
function nomeUnico(string $base, array &$usados): string
{
    $nome = $base;
    $i = 2;
    while (isset($usados[$nome])) {
        $nome = preg_replace('/\.' . pathinfo($base, PATHINFO_EXTENSION) . '$/', '', $base)
              . '_' . $i . '.' . pathinfo($base, PATHINFO_EXTENSION);
        $i++;
    }
    $usados[$nome] = true;
    return $nome;
}

/** Envia um DOCX para download. */
function downloadDocx(array $dados): void
{
    $bin = gerarDocx($dados);
    $arquivo = nomeArquivo($dados['nome'], 'docx');

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
}

/** Envia o recibo em HTML (tela / impressão para PDF). */
function exibirHtml(array $dados, bool $autoPrint = false): void
{
    echo reciboHTML($dados, $autoPrint);
}

/**
 * Resposta de erro usada quando o recibo individual vem com valor zero.
 * Devolve uma página HTML simples (sem fechar a aba automaticamente) para
 * que o usuário corrija os valores no formulário original.
 */
function responderErroRecibo(string $mensagem): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Recibo não gerado</title>
<style>
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
         background: #f6f7f9; margin: 0; padding: 60px 16px; color: #0f172a; }
  .box { max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #e6e8ec;
         border-radius: 14px; padding: 32px 28px; box-shadow: 0 10px 30px -18px rgba(15,23,42,.22);
         text-align: center; }
  h1 { margin: 0 0 12px; font-size: 22px; color: #991b1b; }
  p { color: #334155; margin: 0 0 18px; line-height: 1.5; }
  a { display: inline-block; background: #2563eb; color: #fff; text-decoration: none;
      padding: 10px 18px; border-radius: 10px; font-weight: 600; }
  a:hover { background: #1d4ed8; }
</style>
</head>
<body>
  <div class="box">
    <h1>⚠ Recibo não gerado</h1>
    <p>{$mensagem}</p>
    <a href="javascript:history.back()">← Voltar e corrigir</a>
  </div>
</body>
</html>
HTML;
    exit;
}

/**
 * Imprime o DOCX gerado usando o Microsoft Word (automação COM).
 *
 * Fluxo: gera o DOCX (fiel ao modelo, com a logo da empresa) num arquivo
 * temporário, abre no Word de forma OCULTA, exibe a janela de impressão
 * nativa do Word (onde o usuário escolhe a impressora/copias) e fecha em
 * seguida. O usuário nunca abre o Word manualmente.
 *
 * Requer a extensão com_dotnet do PHP e o Word instalado.
 *
 * @return string 'ok' (imprimiu), 'cancelado' (usuário cancelou a dialog)
 *                ou 'erro' (Word indisponível — chamador deve usar fallback).
 */
function imprimirDocx(array $dados): string
{
    if (!class_exists('COM')) {
        return 'erro';
    }

    // 1) Gera o DOCX num arquivo temporário (caminho absoluto, .docx).
    $bin = gerarDocx($dados);
    $tmp = tempnam(sys_get_temp_dir(), 'recibo_print') . '.docx';
    file_put_contents($tmp, $bin);

    $word = null;
    try {
        $word = new COM('Word.Application');
        $word->Visible = true;         // Word visível: a janela de impressão aparece em 1º plano
        $word->DisplayAlerts = 0;      // sem alertas/prompts
        // Open(FileName, ConfirmConversions=false, ReadOnly=true)
        $doc = $word->Documents->Open($tmp, false, true);
        try { $word->Activate(); } catch (Throwable $e) {} // traz o Word para frente

        // wdDialogFilePrint = 88. Show() bloqueia até imprimir ou cancelar.
        // Retorna -1 se imprimiu, 0 se cancelou.
        $res = $word->Dialogs(88)->Show();
        $status = ($res == -1) ? 'ok' : 'cancelado';

        $doc->Close(false);
        $word->Quit();
        return $status;
    } catch (Throwable $e) {
        // Registra o motivo da falha para diagnóstico (cai no log do PHP).
        error_log('imprimirDocx FALHOU: ' . $e->getMessage());
        try {
            if ($word !== null) {
                $word->Quit();
            }
        } catch (Throwable $e2) {
        }
        return 'erro';
    } finally {
        @unlink($tmp);
    }
}

/** Página de confirmação exibida após mandar para impressão. */
function paginaConfirmacaoImpressao(string $status): string
{
    if ($status === 'ok') {
        $msg = 'Recibo enviado para impressão ✓';
        $sub = 'O Word abriu a janela de impressão. Esta janela pode fechar.';
    } else {
        $msg = 'Impressão cancelada';
        $sub = 'A janela de impressão foi fechada sem imprimir.';
    }
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
         . '<title>Impressão</title></head><body style="font-family:Segoe UI,'
         . 'Arial,sans-serif;padding:48px;text-align:center;color:#374151">'
         . '<h2 style="margin:0 0 8px">' . htmlspecialchars($msg) . '</h2>'
         . '<p style="color:#6b7280">' . htmlspecialchars($sub) . '</p>'
         . '<script>setTimeout(function(){window.close();},2000);</script>'
         . '</body></html>';
}

/* ---------------------------------------------------------------------------
 * Processamento da requisição
 * ------------------------------------------------------------------------- */

// Só processa a requisição quando gerar.php é o script principal (chamado
// pelo servidor web). Quando incluído por outro script (ex.: testes),
// define apenas as funções, sem executar nada.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'gerar.php') {

$acao = $_POST['acao'] ?? $_GET['acao'] ?? 'html';
$formato = $_POST['formato'] ?? 'docx';

if ($acao === 'csv') {

    /* ------- MODO LOTE (XLSX direto ou CSV) -------
     * XLSX: lê a planilha nativamente (ZipArchive + XML), detecta a empresa
     *       por seção ("Funcionários FS/Clínica/laboratório") e gera um DOCX
     *       por funcionário.
     * CSV:  comportamento legado — uma empresa comum a todos.
     *
     * Tipo: 'recibo' (padrão, Aux+VA) ou 'comprovante' (Aux+VA+AJ. CUSTO).
     *
     * O arquivo chega de duas formas:
     *   - $_FILES['arquivo'] (upload direto do formulário)
     *   - $_POST/$_GET['token'] apontando para um arquivo guardado em
     *     sys_get_temp_dir() pela etapa de preview (reenvio entre páginas).
     */
    $tipoBruto = (string) ($_POST['tipo'] ?? 'comprovante');
    if ($tipoBruto === 'comprovante_pagamento') {
        $tipo = 'comprovante_pagamento';
    } else {
        // Tipo padrão é 'comprovante' (Aux + VA + AJ. CUSTO quando houver).
        // O antigo tipo 'recibo' foi unificado com 'comprovante'.
        $tipo = 'comprovante';
    }
    $caminho = null;
    $nomeArq = '';

    if (isset($_FILES['arquivo']) && is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
        $caminho = $_FILES['arquivo']['tmp_name'];
        $nomeArq = $_FILES['arquivo']['name'] ?? '';
    } else {
        // Recupera o arquivo do temp dir a partir do token da prévia.
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['token'] ?? $_GET['token'] ?? ''));
        if ($token !== '') {
            $candidatos = glob(sys_get_temp_dir() . '/lote_' . $token . '.*') ?: [];
            foreach ($candidatos as $c) {
                if (is_readable($c) && filemtime($c) > time() - 1800) { // 30 min de TTL
                    $caminho = $c;
                    $nomeArq = $c; // usado só para detectar .xlsx vs csv
                    break;
                }
            }
        }
    }

    if ($caminho === null) {
        http_response_code(400);
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
           . '<title>Lote</title></head><body style="font-family:Segoe UI,Arial,sans-serif;'
           . 'padding:48px;text-align:center;color:#374151">'
           . '<h2 style="margin:0 0 8px">Arquivo não encontrado</h2>'
           . '<p style="color:#6b7280">O arquivo da planilha expirou ou não foi enviado. '
           . 'Volte e selecione-o novamente.</p>'
           . '<p><a href="index.php" style="color:#1d4ed8">Voltar</a></p></body></html>';
        exit;
    }

    $compartilhado = [
        'periodo' => $_POST['periodo'] ?? '',
        'data'    => $_POST['data'] ?? '',
        'cidade'  => $_POST['cidade'] ?? '',
    ];

    $zip = new ZipArchive();
    $tmpZip = tempnam(sys_get_temp_dir(), 'lote') . '.zip';
    $zip->open($tmpZip, ZipArchive::OVERWRITE | ZipArchive::CREATE);

    $gerados = 0;
    $erro = '';
    $usados = [];

    if (preg_match('/\.xlsx$/i', $nomeArq)) {
        /* --- XLSX: empresa detectada por seção --- */
        try {
            $funcionarios = extrairFuncionariosXlsx($caminho);
        } catch (Throwable $e) {
            $funcionarios = [];
            $erro = $e->getMessage();
        }
        $rotuloEmp = ['fs' => 'FS', 'clinica' => 'Clinica', 'laboratorio' => 'Laboratorio'];
        foreach ($funcionarios as $f) {
            $dados = montarDados(array_merge($compartilhado, $f, ['tipo' => $tipo]));
            // Pula funcionários cujo total ficou R$ 0,00 — documento vazio
            // não serve. (Ex.: alguém com Aux e VA zerados que sobrou na
            // lista; ou Comprovante de Pagamento sem Salário Líquido.)
            if (($dados['valor_total'] ?? 0) <= 0) {
                continue;
            }
            $bin = gerarDocx($dados);
            $prefixo = $rotuloEmp[$f['empresa']] ?? strtoupper($f['empresa']);
            $rotuloTipo = $tipo === 'comprovante_pagamento' ? 'ComprovantePagto'
                : ($tipo === 'comprovante' ? 'Comprovante' : 'Recibo');
            $arq = nomeUnico($prefixo . '_' . $rotuloTipo . '_' . nomeArquivo($f['nome'], 'docx'), $usados);
            $zip->addFromString($arq, $bin);
            $gerados++;
        }
    } else {
        /* --- CSV: empresa comum a todos --- */
        $compartilhado['empresa'] = $_POST['empresa'] ?? '';

        $conteudo = file_get_contents($caminho);
        $enc = mb_detect_encoding($conteudo, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($enc && $enc !== 'UTF-8') {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', $enc);
        }
        $delim = detectarDelimitador($conteudo);

        $linhasBrutas = preg_split('/\r\n|\r|\n/', trim($conteudo));
        $linhas = array_map(fn($l) => linhaCsv($l, $delim), $linhasBrutas);
        $cabecalho = array_shift($linhas);
        $cabecalho = array_map(fn($c) => strtolower(trim($c)), $cabecalho);

        $idx = [
            'nome'        => buscarColuna($cabecalho, ['nome', 'funcionario', 'funcionário']),
            'cpf'         => buscarColuna($cabecalho, ['cpf', 'cpfcnpj']),
            'combustivel' => buscarColuna($cabecalho, ['combustivel', 'combustível', 'auxilio combustivel', 'auxílio combustivel']),
            'alimentacao' => buscarColuna($cabecalho, ['alimentacao', 'alimentação', 'vale alimentacao', 'vale alimentação', 'alimentacao/vale']),
            'aj_custo'    => buscarColuna($cabecalho, ['aj. custo', 'aj custo', 'aj_custo', 'ajuda de custo', 'ajuda custo']),
            'comissao'    => buscarColuna($cabecalho, ['comissao', 'comissão', 'comissao.', 'comissão.']),
        ];

        foreach ($linhas as $linha) {
            if (count($linha) < 2) {
                continue;
            }
            $nome = trim($linha[$idx['nome']] ?? '');
            if ($nome === '') {
                continue;
            }
            $comb = parseValor($linha[$idx['combustivel']] ?? 0);
            $alim = parseValor($linha[$idx['alimentacao']] ?? 0);
            $aj   = parseValor($linha[$idx['aj_custo']] ?? 0);
            $com  = parseValor($linha[$idx['comissao']] ?? 0);
            if ($comb + $alim + $aj + $com <= 0) {
                continue;
            }
            $dados = montarDados(array_merge($compartilhado, [
                'nome'        => $nome,
                'cpf'         => $linha[$idx['cpf']] ?? '',
                'combustivel' => $comb,
                'alimentacao' => $alim,
                'aj_custo'    => $aj,
                'comissao'    => $com,
                'tipo'        => $tipo,
            ]));
            $bin = gerarDocx($dados);
            $rotuloTipo = $tipo === 'comprovante_pagamento' ? 'ComprovantePagto'
                : ($tipo === 'comprovante' ? 'Comprovante' : 'Recibo');
            $zip->addFromString(nomeUnico($rotuloTipo . '_' . nomeArquivo($nome, 'docx'), $usados), $bin);
            $gerados++;
        }
    }

    $zip->close();

    if ($gerados === 0) {
        @unlink($tmpZip);
        http_response_code(400);
        $motivo = $erro !== '' ? htmlspecialchars($erro) : 'Nenhum funcionário com valor encontrado na planilha.';
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
           . '<title>Lote</title></head><body style="font-family:Segoe UI,Arial,sans-serif;'
           . 'padding:48px;text-align:center;color:#374151">'
           . '<h2 style="margin:0 0 8px">Nenhum recibo gerado</h2>'
           . '<p style="color:#6b7280">' . $motivo . '</p>'
           . '<p><a href="index.php" style="color:#1d4ed8">Voltar</a></p></body></html>';
        exit;
    }

    $binZip = file_get_contents($tmpZip);
    @unlink($tmpZip);

    $arqZip = $tipo === 'comprovante_pagamento' ? 'comprovantes_pagamento_lote.zip'
        : 'comprovantes_lote.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $arqZip . '"');
    header('Content-Length: ' . strlen($binZip));
    echo $binZip;
    // Limpa o arquivo temporário guardado pela prévia (se veio por token).
    if (!empty($token) && isset($caminho) && strpos($caminho, sys_get_temp_dir()) === 0) {
        @unlink($caminho);
    }
    exit;

} elseif ($acao === 'preview_lote' && isset($_FILES['arquivo'])) {

    /* ------- PRÉVIA DO LOTE -------
     * Lê o XLSX/CSV, GUARDA uma cópia em sys_get_temp_dir() com um token
     * aleatório e devolve uma página com o resumo (N funcionários por
     * empresa, totais de Aux / VA / AJ. CUSTO / geral) e dois botões
     * (forms HTML normais) que enviam o token + tipo escolhido para
     * gerar.php?acao=csv. Isso evita a fragilidade de tentar reenviar o
     * arquivo via JavaScript entre páginas.
     */
    $caminho = $_FILES['arquivo']['tmp_name'];
    $nomeArq = $_FILES['arquivo']['name'] ?? '';

    // Salva o arquivo com um token (TTL 30 min) e usa o caminho guardado
    // para a leitura (assim o tmp original do upload pode ser apagado pelo
    // PHP após o término do request sem perder a prévia).
    try {
        $token = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $token = substr(md5(uniqid('', true)), 0, 16);
    }
    $ext = preg_match('/\.xlsx$/i', $nomeArq) ? '.xlsx' : '.csv';
    $caminhoGuardado = sys_get_temp_dir() . '/lote_' . $token . $ext;
    if (!@copy($caminho, $caminhoGuardado)) {
        $caminhoGuardado = $caminho; // fallback: usa o tmp original
        $token = '';
    }

    $erroLeitura = '';
    $porEmpresa = [];   // chave => ['nome','qtd','qtdVales','qtdPagto','aux','va','aj','bruto','liquido','liqCalc']
    $totalGeral = [
        'qtd'      => 0,
        'qtdVales' => 0,
        'qtdPagto' => 0,
        'aux'      => 0.0,
        'va'       => 0.0,
        'aj'       => 0.0,
        'comissao' => 0.0,
        'bruto'    => 0.0,
        'liquido'  => 0.0,
        'liqCalc'  => 0.0,
    ];
    $temSalarios = false;

    try {
        if (preg_match('/\.xlsx$/i', $nomeArq)) {
            $funcionarios = extrairFuncionariosXlsx($caminhoGuardado);
        } else {
            $funcionarios = []; // CSV: prévia simples, sem detalhe por funcionário
        }
    } catch (Throwable $e) {
        $funcionarios = [];
        $erroLeitura = $e->getMessage();
    }

    $empNomes = ['fs' => 'FS', 'clinica' => 'Clínica', 'laboratorio' => 'Laboratório'];
    foreach ($funcionarios as $f) {
        $chave = $f['empresa'];
        if (!isset($porEmpresa[$chave])) {
            $porEmpresa[$chave] = [
                'nome'      => $empNomes[$chave] ?? $chave,
                'qtd'       => 0,
                'qtdVales'  => 0,   // quantos geram Comprovante de Vales (Aux+VA > 0)
                'qtdPagto'  => 0,   // quantos geram Comprovante de Pagamento (salário > 0)
                'aux'       => 0.0,
                'va'        => 0.0,
                'aj'        => 0.0,
                'comissao'  => 0.0,
                'bruto'     => 0.0,
                'liquido'   => 0.0,
                // Para Comprovante de Pagamento, calculamos o líquido que vai
                // entrar no doc (= bruto - aux - va - aj). Usado nos cards.
                'liqCalc'   => 0.0,
            ];
        }
        $aux = (float)$f['combustivel'];
        $va  = (float)$f['alimentacao'];
        $aj  = (float)$f['aj_custo'];
        $com = (float)($f['comissao'] ?? 0);
        $bruto   = (float)($f['salario_bruto']   ?? 0);
        $liquido = (float)($f['salario_liquido'] ?? 0);

        // Líquido que vai aparecer no Comprovante de Pagamento:
        // - usa o salário_liquido da planilha se preenchido
        // - senão calcula bruto - aux - va - aj (limitado em 0)
        $liqCalc = $liquido > 0
            ? $liquido
            : ($bruto > 0 ? max(0, round($bruto - $aux - $va - $aj, 2)) : 0);

        $porEmpresa[$chave]['qtd']++;
        $porEmpresa[$chave]['aux']      += $aux;
        $porEmpresa[$chave]['va']       += $va;
        $porEmpresa[$chave]['aj']       += $aj;
        $porEmpresa[$chave]['comissao'] += $com;
        $porEmpresa[$chave]['bruto']    += $bruto;
        $porEmpresa[$chave]['liquido']  += $liquido;
        $porEmpresa[$chave]['liqCalc']  += $liqCalc;

        // Só conta como "geraria doc" se o total for > 0 no tipo.
        if ($aux > 0 || $va > 0 || $aj > 0 || $com > 0) {
            $porEmpresa[$chave]['qtdVales']++;
            $totalGeral['qtdVales']++;
        }
        if ($liqCalc > 0) {
            $porEmpresa[$chave]['qtdPagto']++;
            $totalGeral['qtdPagto']++;
        }

        $totalGeral['qtd']++;
        $totalGeral['aux']      += $aux;
        $totalGeral['va']       += $va;
        $totalGeral['aj']       += $aj;
        $totalGeral['comissao'] += $com;
        $totalGeral['bruto']    += $bruto;
        $totalGeral['liquido']  += $liquido;
        $totalGeral['liqCalc']  += $liqCalc;
        if ($liquido > 0 || $bruto > 0) {
            $temSalarios = true;
        }
    }
    // Quantos funcionários NÃO gerariam nenhum dos dois tipos de doc
    // (sem Aux/VA/AJ e sem salário). Útil pra mostrar no preview.
    $puladosTotal = 0;
    foreach ($funcionarios as $f) {
        $aux = (float)$f['combustivel'];
        $va  = (float)$f['alimentacao'];
        $aj  = (float)$f['aj_custo'];
        $com = (float)($f['comissao'] ?? 0);
        $bruto   = (float)($f['salario_bruto']   ?? 0);
        $liquido = (float)($f['salario_liquido'] ?? 0);
        $temAdiantamento = $aux > 0 || $va > 0 || $aj > 0 || $com > 0;
        $temSalarioComValor = ($liquido > 0)
            || ($bruto > 0 && $liquido === 0 && $bruto - $aux - $va - $aj > 0);
        if (!$temAdiantamento && !$temSalarioComValor) {
            $puladosTotal++;
        }
    }

    $fmt = fn($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    // Período do lote: vem de dois campos date (inicio/final). Combina em
    // "dd/mm/aaaa a dd/mm/aaaa" para reaproveitar todo o pipeline existente
    // (periodoParaPlaceholders separa ini/fim sozinho).
    $iso2br = function ($iso) {
        $iso = trim($iso ?? '');
        if ($iso === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return '';
        }
        [$a, $m, $d] = explode('-', $iso);
        return sprintf('%02d/%02d/%04d', $d, $m, $a);
    };
    $pIni = $iso2br($_POST['periodo_inicio'] ?? '');
    $pFim = $iso2br($_POST['periodo_final'] ?? '');
    if ($pIni !== '' && $pFim !== '') {
        $periodoTxt = $pIni . ' a ' . $pFim;
    } elseif ($pIni !== '') {
        $periodoTxt = $pIni;
    } elseif ($pFim !== '') {
        $periodoTxt = $pFim;
    } else {
        // Compatibilidade: se mandaram o campo texto legado, usa ele.
        $periodoTxt = trim($_POST['periodo'] ?? '');
    }
    $periodo = htmlspecialchars($periodoTxt);
    $data    = htmlspecialchars($_POST['data'] ?? '');
    $cidade  = htmlspecialchars($_POST['cidade'] ?? '');
    $empCsv  = htmlspecialchars($_POST['empresa'] ?? '');

    // Tabela com colunas de salário (se a planilha tiver) e Qtd separada
    // por tipo de documento que vai ser gerado. Inclui coluna de Comissão
    // quando algum funcionário tiver valor (some ao total do documento).
    $temComissao = $totalGeral['comissao'] > 0;
    $colspan = ($temSalarios ? 10 : 8) + ($temComissao ? 1 : 0);
    $linhas = '';
    foreach ($porEmpresa as $chave => $e) {
        $totalAuxVa  = $e['aux'] + $e['va'];
        $totalAuxVaAj = $totalAuxVa + $e['aj'];
        $totalGeralLinha = $totalAuxVaAj + $e['comissao'];
        $linhas .= '<tr>'
                 . '<td>' . htmlspecialchars($e['nome']) . '</td>'
                 . '<td class="num">' . $e['qtdVales'] . '</td>'
                 . '<td class="num">' . $e['qtdPagto'] . '</td>'
                 . '<td class="num">' . $fmt($e['aux']) . '</td>'
                 . '<td class="num">' . $fmt($e['va']) . '</td>'
                 . '<td class="num">' . $fmt($e['aj']) . '</td>';
        if ($temComissao) {
            $linhas .= '<td class="num">' . $fmt($e['comissao']) . '</td>';
        }
        if ($temSalarios) {
            $linhas .= '<td class="num">' . $fmt($e['bruto']) . '</td>'
                     . '<td class="num">' . $fmt($e['liquido']) . '</td>';
        }
        $linhas .= '<td class="num"><b>' . $fmt($totalAuxVa) . '</b></td>'
                 . '<td class="num"><b>' . $fmt($totalGeralLinha) . '</b></td>'
                 . '</tr>';
    }
    if ($linhas === '') {
        $linhas = '<tr><td colspan="' . $colspan . '" style="text-align:center;color:#6b7280;padding:28px 0;white-space:normal;">'
                . 'Nenhum funcionário com valor encontrado na planilha.'
                . '</td></tr>';
    }
    $gtotalAuxVa  = $totalGeral['aux'] + $totalGeral['va'];
    $gtotalAuxVaAj = $gtotalAuxVa + $totalGeral['aj'];
    $gtotalGeral = $gtotalAuxVaAj + $totalGeral['comissao'];
    $gtotalLiquido = $totalGeral['liquido'];
    $gtotalLiqCalc = $totalGeral['liqCalc'];

    // Cabeçalho/rodapé da tabela de salários (só se a planilha tiver).
    if ($temSalarios) {
        $cabecalhoSalario = '<th class="num">Sal. Bruto</th><th class="num">Sal. Líquido</th>';
        $rodapeSalario    = '<td class="num">' . $fmt($totalGeral['bruto']) . '</td>'
                          . '<td class="num">' . $fmt($totalGeral['liquido']) . '</td>';
    } else {
        $cabecalhoSalario = '';
        $rodapeSalario    = '';
    }
    // Cabeçalho/rodapé da coluna de Comissão (só se algum funcionário tiver).
    if ($temComissao) {
        $cabecalhoComissao = '<th class="num">Comissão</th>';
        $rodapeComissao    = '<td class="num">' . $fmt($totalGeral['comissao']) . '</td>';
    } else {
        $cabecalhoComissao = '';
        $rodapeComissao    = '';
    }

    // Cards de escolha: cada um só fica habilitado se tiver pelo menos 1
    // funcionário que gere documento daquele tipo.
    $qtdVales = $totalGeral['qtdVales'] ?? 0;
    $qtdPagto = $totalGeral['qtdPagto'] ?? 0;

    if ($qtdVales > 0) {
        $estiloVales       = '';
        $disabledVales     = '';
        $tagVales          = 'COMPROVANTE DE VALES';
        $totalValesDisplay = "{$qtdVales} doc(s) &middot; Total: {$fmt($gtotalGeral)}";
    } else {
        $estiloVales       = 'style="opacity:.55;cursor:not-allowed;"';
        $disabledVales     = 'disabled title="Nenhum funcionário com Aux/VA/AJ.CUSTO na planilha"';
        $tagVales          = 'INDISPONÍVEL';
        $totalValesDisplay = 'Nenhum funcionário com Auxílio/VA/AJ. CUSTO';
    }

    if ($temSalarios && $qtdPagto > 0) {
        $estiloPagamento      = '';
        $disabledPagamento    = '';
        $legendaPagamento     = '<i>Calcula <b>Bruto − Aux − VA − AJ. CUSTO</b> para cada funcionário.</i>';
        $rotuloTotalPagamento = "{$qtdPagto} doc(s) &middot; Líquido total: {$fmt($gtotalLiqCalc)}";
    } else {
        $estiloPagamento      = 'style="opacity:.55;cursor:not-allowed;"';
        $disabledPagamento    = 'disabled title="Disponível apenas em planilhas de folha de pagamento (com coluna Salário)"';
        $legendaPagamento     = '<i style="color:#9aa4b2">Indisponível: a planilha não tem coluna de Salário (Bruto ou Líquido).</i>';
        $rotuloTotalPagamento = 'Indisponível';
    }

    $htmlErro = $erroLeitura !== ''
        ? '<div class="aviso" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;">'
          . '⚠ ' . htmlspecialchars($erroLeitura) . '</div>'
        : '';

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prévia do lote</title>
<style>
  :root {
    --bg:#f6f7f9; --card:#fff; --line:#e6e8ec; --ink:#0f172a; --mut:#64748b;
    --label:#334155; --prim:#2563eb; --prim-d:#1d4ed8; --prim-soft:#eef4ff;
    --ok:#059669; --ok-soft:#ecfdf5; --ok-line:#a7f3d0;
    --warn:#d97706; --warn-soft:#fffbeb; --warn-line:#fde68a;
  }
  *{box-sizing:border-box;}
  body{font-family:"Inter",system-ui,"Segoe UI",Roboto,Arial,sans-serif;
       background:radial-gradient(1100px 560px at 100% -12%, #eef2ff 0%, rgba(238,242,255,0) 58%), var(--bg);
       color:var(--ink); margin:0; padding:32px 16px 56px; -webkit-font-smoothing:antialiased;}
  .wrap{max-width:960px;margin:0 auto;}
  header h1{margin:0;font-size:26px;font-weight:700;letter-spacing:-.025em;}
  header p{margin:6px 0 24px;color:var(--mut);font-size:14px;}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;
        padding:26px;margin-bottom:20px;box-shadow:0 1px 2px rgba(15,23,42,.04), 0 10px 30px -18px rgba(15,23,42,.22);}
  .card h2{font-size:16px;font-weight:700;margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid var(--line);
           display:flex;align-items:center;gap:10px;}
  .badge{font-size:11px;background:var(--prim-soft);color:var(--prim);padding:3px 10px;border-radius:999px;font-weight:600;}
  /* Container com scroll horizontal pra não espremer a tabela em telas estreitas */
  .tabela-wrap{overflow-x:auto;margin-top:6px;border:1px solid var(--line);border-radius:10px;background:#fff;}
  table{width:100%;border-collapse:collapse;font-size:13px;margin-top:0;}
  th,td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap;}
  th:last-child, td:last-child{border-right:0;}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--mut);font-weight:600;background:#fafbfc;line-height:1.3;}
  td.num, th.num{text-align:right;font-variant-numeric:tabular-nums;}
  tbody tr:last-child td{border-bottom:0;}
  tfoot td{font-weight:700;border-top:2px solid var(--line);border-bottom:none;background:#fafbfc;}
  .meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 18px;margin:0 0 18px;font-size:13.5px;}
  .meta div{color:var(--mut);}
  .meta b{color:var(--ink);font-weight:600;}
  .escolha{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:10px;}
  .opcao{border:2px solid var(--line);border-radius:12px;padding:20px 22px;cursor:pointer;
         background:#fff;transition:border-color .15s, box-shadow .15s, transform .04s;
         display:flex;flex-direction:column;gap:10px;}
  .opcao:hover{border-color:var(--prim);box-shadow:0 4px 14px -8px rgba(37,99,235,.35);}
  .opcao h3{margin:0;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px;}
  .opcao p{margin:0;color:var(--mut);font-size:13px;line-height:1.5;}
  .opcao .tag{font-size:11px;font-weight:700;letter-spacing:.04em;padding:3px 9px;border-radius:999px;align-self:flex-start;}
  .opcao.comprovante .tag{background:var(--warn-soft);color:var(--warn);border:1px solid var(--warn-line);}
  .opcao.pagamento .tag{background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok-line);}
  .opcao .total{font-size:17px;font-weight:800;color:var(--ink);letter-spacing:-.01em;margin-top:6px;}
  .opcao.comprovante .total{color:var(--warn);}
  .opcao.pagamento .total{color:var(--ok);}
  .acoes{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;}
  .btn{border:1px solid transparent;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:600;
       cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px;
       transition:background .15s, border-color .15s, box-shadow .15s, transform .04s;}
  .btn-prim{background:var(--prim);color:#fff;box-shadow:0 1px 2px rgba(37,99,235,.22);}
  .btn-prim:hover{background:var(--prim-d);}
  .btn-sec{background:#fff;color:var(--ink);border:1px solid var(--line);}
  .btn-sec:hover{background:#f8fafc;border-color:#cbd5e1;}
  .aviso{font-size:13.5px;color:var(--mut);margin-top:16px;padding:14px 16px;line-height:1.65;
         background:#f8fafc;border:1px solid var(--line);border-radius:10px;
         text-align:justify;hyphens:auto;word-spacing:.5px;}
  .aviso b{color:var(--ink);font-weight:600;}
  .aviso i{color:#475569;font-style:normal;}
  @media (max-width:920px){
    .escolha{grid-template-columns:1fr;}
  }
  @media (max-width:720px){
    .meta{grid-template-columns:1fr;}
  }
</style>
</head>
<body>
<div class="wrap">

  <header>
    <h1>Prévia do lote</h1>
    <p>Confira os valores lidos da planilha e escolha o tipo de documento a gerar.</p>
  </header>

  {$htmlErro}

  <section class="card">
    <h2>Dados compartilhados</h2>
    <div class="meta">
      <div>Período: <b>{$periodo}</b></div>
      <div>Data: <b>{$data}</b></div>
      <div>Local: <b>{$cidade}</b></div>
    </div>
  </section>

  <section class="card">
    <h2>Resumo por empresa <span class="badge">{$totalGeral['qtd']} funcionário(s) na planilha</span></h2>
    <div class="tabela-wrap">
    <table>
      <thead>
        <tr>
          <th>Empresa</th>
          <th class="num" title="Quantos geram Comprovante de Vales">Qtd Vales</th>
          <th class="num" title="Quantos geram Comprovante de Pagamento">Qtd Pagto</th>
          <th class="num">Auxílio</th>
          <th class="num">Vale Alim.</th>
          <th class="num">AJ. Custo</th>
          {$cabecalhoComissao}
          {$cabecalhoSalario}
          <th class="num">Total (Vales)</th>
          <th class="num">Total (geral)</th>
        </tr>
      </thead>
      <tbody>
        {$linhas}
      </tbody>
      <tfoot>
        <tr>
          <td>TOTAL GERAL</td>
          <td class="num">{$totalGeral['qtdVales']}</td>
          <td class="num">{$totalGeral['qtdPagto']}</td>
          <td class="num">{$fmt($totalGeral['aux'])}</td>
          <td class="num">{$fmt($totalGeral['va'])}</td>
          <td class="num">{$fmt($totalGeral['aj'])}</td>
          {$rodapeComissao}
          {$rodapeSalario}
          <td class="num">{$fmt($gtotalAuxVa)}</td>
          <td class="num">{$fmt($gtotalGeral)}</td>
        </tr>
      </tfoot>
    </table>
    </div>
    <div class="aviso">Funcionários sem Auxílio/VA/AJ. CUSTO/Comissão <b>não geram Comprovante de Vales</b> (ficaria R$ 0,00). Já o <b>Comprovante de Pagamento</b> usa Salário Líquido quando a planilha tem, ou calcula <i>Bruto − Aux − VA − AJ. CUSTO</i> na folha de pagamento — funcionários só com salário base também geram esse. <b>Comissão</b> (quando há na planilha) é exibida como linha e somada ao total nos dois documentos.</div>
  </section>

  <form action="gerar.php" method="post">
    <input type="hidden" name="acao" value="csv">
    <input type="hidden" name="token" value="{$token}">
    <input type="hidden" name="periodo" value="{$periodo}">
    <input type="hidden" name="data" value="{$data}">
    <input type="hidden" name="cidade" value="{$cidade}">
    <input type="hidden" name="empresa" value="{$empCsv}">

    <section class="card">
      <h2>Tipo de documento <span class="badge">escolha um</span></h2>
      <div class="escolha">
        <div class="opcao comprovante" data-tipo="comprovante" onclick="marcarTipo(this, 'comprovante')" {$estiloVales}>
          <span class="tag">{$tagVales}</span>
          <h3>🧾 Comprovante de Vales</h3>
          <p>Cada funcionário recebe um comprovante com Auxílio + Vale Alimentação <b>(+ AJ. CUSTO e Comissão, se houver)</b> somados no total.</p>
          <div class="total">{$totalValesDisplay}</div>
        </div>
        <div class="opcao pagamento" data-tipo="comprovante_pagamento" onclick="marcarTipo(this, 'comprovante_pagamento')" {$estiloPagamento}>
          <span class="tag">COMPROVANTE DE PAGAMENTO</span>
          <h3>💰 Comprovante de Pagamento</h3>
          <p>{$legendaPagamento}</p>
          <div class="total">{$rotuloTotalPagamento}</div>
        </div>
      </div>

      <div class="acoes">
        <button type="submit" name="tipo" value="comprovante" class="btn btn-prim" style="background:#d97706" {$disabledVales}>⬇ Gerar Comprovantes de Vales (.zip)</button>
        <button type="submit" name="tipo" value="comprovante_pagamento" class="btn btn-prim" style="background:#059669" {$disabledPagamento}>⬇ Gerar Comprovantes de Pagamento (.zip)</button>
        <a href="index.php" class="btn btn-sec">← Voltar</a>
      </div>
    </section>
  </form>

</div>
<script>
  function marcarTipo(el, tipo) {
    document.querySelectorAll('.opcao').forEach(o => {
      o.style.borderColor = 'var(--line)';
      o.style.boxShadow = 'none';
    });
    const cores = {
      comprovante:            { borda: '#d97706', sombra: 'rgba(217,119,6,.18)' },
      comprovante_pagamento:  { borda: '#059669', sombra: 'rgba(5,150,105,.18)' },
    };
    el.style.borderColor = cores[tipo].borda;
    el.style.boxShadow   = '0 0 0 3px ' + cores[tipo].sombra;
  }
</script>
</body>
</html>
HTML;
    exit;

} elseif ($acao === 'imprimir') {

    /* ------- IMPRESSÃO DIRETA DO DOCX (via Word) -------
     * Gera o DOCX real (fiel ao modelo) e manda para o Word, que abre
     * oculto e exibe a janela de impressão nativa. Se o Word não estiver
     * disponível, cai no fallback HTML (window.print()).
     */
    $dados = montarDados($_POST);
    $status = imprimirDocx($dados);
    if ($status === 'erro') {
        exibirHtml($dados, true); // fallback: impressão via HTML
    } else {
        echo paginaConfirmacaoImpressao($status);
    }
    exit;

} elseif ($acao === 'html' || $acao === 'preview') {

    /* ------- PRÉ-VISUALIZAÇÃO HTML -------
     * Aceita dados via POST (formulário) ou via GET (link "Imprimir"
     * disparado por JavaScript, que monta a query string).
     */
    $entrada = array_merge($_GET ?? [], $_POST ?? []);
    $dados = montarDados($entrada);
    if (($dados['valor_total'] ?? 0) <= 0) {
        responderErroRecibo('Informe ao menos um valor (Auxílio Combustível ou Vale Alimentação) maior que zero.');
    }
    // O JS passa autoPrint=1 quando o botão Imprimir foi clicado.
    $autoPrint = isset($entrada['autoPrint']) && $entrada['autoPrint'] == '1';
    exibirHtml($dados, $autoPrint);
    exit;

} else {

    /* ------- RECIBO ÚNICO ------- */
    $dados = montarDados($_POST);

    if (($dados['valor_total'] ?? 0) <= 0) {
        responderErroRecibo('Informe ao menos um valor (Auxílio Combustível ou Vale Alimentação) maior que zero.');
    }

    if ($formato === 'html') {
        exibirHtml($dados);
    } else {
        downloadDocx($dados);
    }
    exit;
}

} // fim do processamento da requisição

/* ---------------------------------------------------------------------------
 * Funções auxiliares de CSV
 * ------------------------------------------------------------------------- */

function detectarDelimitador(string $texto): string
{
    $amostra = substr($texto, 0, 4096);
    $candidatos = [';', ',', "\t", '|'];
    $melhor = ';';
    $max = 0;
    foreach ($candidatos as $c) {
        $n = substr_count($amostra, $c);
        if ($n > $max) {
            $max = $n;
            $melhor = $c;
        }
    }
    return $melhor;
}

function linhaCsv(string $linha, string $delim): array
{
    return str_getcsv($linha, $delim);
}

function buscarColuna(array $cabecalho, array $sinonimos): int
{
    foreach ($cabecalho as $i => $col) {
        $c = strtolower(trim($col));
        foreach ($sinonimos as $s) {
            if ($c === strtolower($s) || strpos($c, strtolower($s)) !== false) {
                return $i;
            }
        }
    }
    return -1;
}

/* ---------------------------------------------------------------------------
 * Leitura de XLSX (planilha nativa, sem Composer / sem bibliotecas externas)
 * ------------------------------------------------------------------------- */

/** Remove acentos para casar palavras independentemente de diacríticos. */
function semAcento(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t === false || $t === '') {
        return $s;
    }
    // iconv //TRANSLIT no Windows insere aspas em vez de remover acentos
    // (ex.: "Clínica" -> "Cl'inica"); limpamos para não quebrar a busca.
    $t = str_replace(["'", '"', '`', '^', '~'], '', $t);
    return $t;
}

/** Converte letras de coluna (A, B, ..., AA) em índice 0-based. */
function colunaParaIndice(string $col): int
{
    $n = 0;
    foreach (str_split(strtoupper($col)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** Lê a lista de strings compartilhadas (xl/sharedStrings.xml). */
function lerSharedStringsXlsx(string $xml): array
{
    $ss = [];
    if ($xml === '' || !preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $sis)) {
        return $ss;
    }
    foreach ($sis[1] as $si) {
        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $ts)) {
            $ss[] = html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } else {
            $ss[] = '';
        }
    }
    return $ss;
}

/**
 * Lê a primeira planilha (xl/worksheets/sheetN.xml) e devolve uma lista de
 * linhas, cada uma com 'row' (número) e 'cells' (índice de coluna => valor
 * textual já resolvido contra as strings compartilhadas).
 */
function lerPlanilhaXlsx(string $xml, array $ss): array
{
    $rows = [];
    if ($xml === '' || !preg_match_all('/<row\b[^>]*\br="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm)) {
        return $rows;
    }
    foreach ($rm[2] as $k => $rowbody) {
        $cells = [];
        // Descarta células vazias self-closing (<c r="B3" s="5"/>): elas não
        // têm </c>, e sem isso o regex abaixo "engoliria" as células
        // seguintes e deslocaria os valores de coluna.
        $rowbody = preg_replace('/<c\b[^>]*\/>/', '', $rowbody);
        if (preg_match_all('/<c\b([^>]*?)>(.*?)<\/c>/s', $rowbody, $cm)) {
            foreach ($cm[1] as $i => $attrs) {
                if (!preg_match('/\br="([A-Z]+)\d+"/', $attrs, $rm2)) {
                    continue;
                }
                $colLetters = $rm2[1];
                $inner = $cm[2][$i];
                $t = '';
                if (preg_match('/\bt="([^"]+)"/', $attrs, $tm)) {
                    $t = $tm[1];
                }
                $val = '';
                if ($t === 'inlineStr') {
                    if (preg_match('/<is\b[^>]*>(.*?)<\/is>/s', $inner, $im)
                        && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $im[1], $tsm)) {
                        $val = html_entity_decode(implode('', $tsm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                } elseif (preg_match('/<v[^>]*>(.*?)<\/v>/s', $inner, $vm)) {
                    $val = $vm[1];
                    if ($t === 's' && isset($ss[(int) $val])) {
                        $val = $ss[(int) $val];
                    }
                }
                $cells[colunaParaIndice($colLetters)] = $val;
            }
        }
        $rows[] = ['row' => (int) $rm[1][$k], 'cells' => $cells];
    }
    return $rows;
}

/**
 * Identifica a empresa de uma linha de cabeçalho de seção da planilha.
 * Devolve a chave em EMPRESAS, '__skip__' para seções a ignorar (ex.:
 * Fidelidade) ou null se a linha não for um cabeçalho de seção.
 */
function detectarEmpresaSecao(string $texto): ?string
{
    $t = strtolower(semAcento(trim($texto)));
    if (strpos($t, 'funcion') === false) {
        return null;
    }
    if (strpos($t, 'fidelidade') !== false) {
        return '__skip__';
    }
    if (strpos($t, 'clinica') !== false) {
        return 'clinica';
    }
    if (strpos($t, 'laborat') !== false) {
        return 'laboratorio';
    }
    if (preg_match('/\bfs\b/', $t)) {
        return 'fs';
    }
    return null;
}

/**
 * Extrai a lista de funcionários de um arquivo XLSX, detectando a empresa
 * por seção. Colunas esperadas: A=Nome, C=Aux. Combustível, D=VA. Ignora
 * VT (B) e AJ. CUSTO (E). Pula linhas sem nome, sem valor (ou com 'x') e
 * as seções não suportadas (Fidelidade).
 *
 * @return list<array{empresa:string,nome:string,combustivel:float,alimentacao:float,aj_custo:float}>
 */
function extrairFuncionariosXlsx(string $path): array
{
    $za = new ZipArchive();
    if ($za->open($path) !== true) {
        throw new RuntimeException('Não foi possível abrir o arquivo XLSX.');
    }
    $ssXml = $za->getFromName('xl/sharedStrings.xml') ?: '';
    $za->close();

    // Descobre o nome de cada aba (Clinica, FS, Laboratório, Fidelidade...)
    // e a lista de arquivos sheetN.xml correspondentes. Itera TODAS as
    // abas — a folha de Agosto tem 4 abas (uma por empresa), e a planilha
    // antiga usa uma aba única com seções.
    $abas = listarAbasXlsx($path);

    if (count($abas) === 0) {
        throw new RuntimeException('Planilha não encontrada dentro do XLSX.');
    }

    // Auto-detecta o formato olhando a primeira aba. Se for folha de
    // pagamento, todas as abas vão ser; se for seções, só a primeira.
    $primeira = $abas[0];
    $ss = lerSharedStringsXlsx($ssXml);
    $rowsPrimeira = lerPlanilhaXlsx($primeira['xml'], $ss);
    $formato = detectarFormatoPlanilha($rowsPrimeira);

    if ($formato === 'folha_pagamento') {
        // Cada aba é uma empresa. Lê todas (exceto Fidelidade).
        $todos = [];
        foreach ($abas as $aba) {
            if ($aba['empresa_chave'] === null) {
                continue; // Fidelidade: não emite recibos desta folha.
            }
            $rows = lerPlanilhaXlsx($aba['xml'], $ss);
            $funcs = extrairFuncionariosFolhaPagamento($rows, $aba['empresa_chave']);
            foreach ($funcs as $f) {
                $todos[] = $f;
            }
        }
        return $todos;
    }
    return extrairFuncionariosXlsxSecoes($rowsPrimeira);
}

/**
 * Abre o XLSX e devolve a lista de abas com: nome, arquivo sheetN.xml e
 * chave da empresa inferida pelo nome da aba (Clinica, FS, Laboratório,
 * Fidelidade). O XML de cada aba vem embutido pra evitar reabrir o zip.
 *
 * @return list<array{nome:string, arquivo:string, xml:string, empresa_chave:?string}>
 */
function listarAbasXlsx(string $path): array
{
    $za = new ZipArchive();
    if ($za->open($path) !== true) {
        return [];
    }
    $wb = $za->getFromName('xl/workbook.xml') ?: '';
    $rels = $za->getFromName('xl/_rels/workbook.xml.rels') ?: '';

    // rId -> Target
    $ridToTarget = [];
    if (preg_match_all('/<Relationship\b[^>]*\bId="(rId\d+)"[^>]*\bTarget="([^"]+)"/', $rels, $rm)) {
        foreach ($rm[1] as $k => $rid) {
            $ridToTarget[$rid] = $rm[2][$k];
        }
    }

    // sheetId -> { nome, rId }
    $sheets = [];
    if (preg_match_all('/<sheet\b[^>]*\bname="([^"]+)"[^>]*\bsheetId="(\d+)"[^>]*\br:id="(rId\d+)"/', $wb, $sm)) {
        foreach ($sm[1] as $k => $nome) {
            $sheets[(int) $sm[2][$k]] = [
                'nome' => $nome,
                'rId'  => $sm[3][$k],
            ];
        }
    }
    ksort($sheets);

    $abas = [];
    foreach ($sheets as $s) {
        $target = $ridToTarget[$s['rId']] ?? '';
        // target geralmente é "worksheets/sheet1.xml" — sem prefixo xl/
        $arquivo = 'xl/' . ltrim($target, '/');
        $xml = $za->getFromName($arquivo) ?: '';
        if ($xml === '') continue;
        $abas[] = [
            'nome'           => $s['nome'],
            'arquivo'        => $arquivo,
            'xml'            => $xml,
            'empresa_chave'  => chaveEmpresaPorNomeAba($s['nome']),
        ];
    }
    $za->close();
    return $abas;
}

/**
 * Infere a chave da empresa (fs / clinica / laboratorio) a partir do nome
 * da aba. Usado pra folha de pagamento, onde cada aba = uma empresa.
 * Retorna null se for "Fidelidade" ou nome não reconhecido.
 */
function chaveEmpresaPorNomeAba(string $nome): ?string
{
    $n = strtolower(semAcento(trim($nome)));
    if ($n === '' || strpos($n, 'fidelidade') !== false) {
        return null; // pulado
    }
    if (strpos($n, 'clinica') !== false) return 'clinica';
    if (strpos($n, 'laborat') !== false) return 'laboratorio';
    if (strpos($n, 'fs') !== false) return 'fs';
    return null;
}

/**
 * Detecta o formato da planilha:
 *   - 'secoes'           → tem cabeçalho "Funcionários FS / Clínica / laboratório"
 *                          (planilha antiga, várias seções/empresas numa aba só)
 *   - 'folha_pagamento'  → tem cabeçalho "Salário Bruto / Aux. Combustível / VA"
 *                          (planilha nova, folha de pagamento de uma empresa só)
 *   - 'secoes' (default) → fallback conservador
 */
function detectarFormatoPlanilha(array $rows): string
{
    foreach ($rows as $r) {
        $cells = $r['cells'];
        $texto = '';
        foreach ($cells as $v) {
            $texto .= ' ' . (string) $v;
        }
        $t = strtolower(semAcento($texto));
        // Formato folha de pagamento: cabeçalho com "salario bruto", "aux. combust", "va"
        if (strpos($t, 'salario bruto') !== false
            || (strpos($t, 'aux. combust') !== false && strpos($t, 'salario') !== false)) {
            return 'folha_pagamento';
        }
    }
    return 'secoes';
}

/**
 * Lê uma planilha no formato "folha de pagamento" (ex.: FOLHA AGOSTO 2026).
 *
 * Layout típico (todas as colunas são opcionais — busca-se pelo cabeçalho):
 *   - Funcionários / Nome           → coluna do nome (busca no cabeçalho)
 *   - Aux. Combustível             → K
 *   - VA                           → L
 *   - Salário Bruto, Salário Líquido, Chave PIX, etc. → ignorados
 *
 * Observações:
 *   - Células com texto ("cartão", "Dispensada 30/07", "x") viram 0.
 *   - Funcionários com Aux + VA = 0 são pulados.
 *   - Linhas TOTAL, "PAGAMENTO", observações, etc. são puladas.
 *   - Como a folha é de UMA empresa só, deduzimos pela folha: se o título
 *     menciona "Clínica", marca como 'clinica'; se "Laboratório", como
 *     'laboratorio'; senão 'clinica' (a folha atual é da Clínica).
 */
function extrairFuncionariosFolhaPagamento(array $rows, ?string $overrideEmpresa = null): array
{
    // 1) Descobre o nome da empresa pelo título (R1, geralmente na coluna B).
    //    Se a chamada veio de uma aba com nome conhecido (Clinica, FS,
    //    Laboratório), $overrideEmpresa tem prioridade sobre o título — o
    //    título de "FIDELIDADE SAÚDE" por exemplo batia "clinica" e
    //    enganaria a detecção.
    $titulo = '';
    foreach ($rows as $r) {
        if ($r['row'] <= 3) {
            foreach ($r['cells'] as $v) {
                $titulo .= ' ' . (string) $v;
            }
        }
    }
    $tituloNorm = strtolower(semAcento($titulo));
    if ($overrideEmpresa !== null) {
        $empresa = $overrideEmpresa;
    } elseif (strpos($tituloNorm, 'laborat') !== false) {
        $empresa = 'laboratorio';
    } elseif (strpos($tituloNorm, 'clinica') !== false || strpos($tituloNorm, 'fidelidade') !== false) {
        $empresa = 'clinica';
    } elseif (strpos($tituloNorm, 'fs') !== false) {
        $empresa = 'fs';
    } else {
        $empresa = 'clinica'; // padrão conservador
    }

    // 2) Descobre a linha do cabeçalho (geralmente R2) e mapeia coluna → chave.
    //    Cabeçalho esperado (case-insensitive, sem acentos):
    //      "Funcionários" → nome
    //      "Aux. Combustível" → combustivel
    //      "VA" → alimentacao
    $cabecalho = null;
    $cabecalhoLinha = 0;
    foreach ($rows as $r) {
        $txts = [];
        foreach ($r['cells'] as $v) {
            $txts[] = strtolower(semAcento(trim((string) $v)));
        }
        if (in_array('aux. combustivel', $txts, true) || in_array('aux. combust', $txts, true)
            || in_array('auxilio combustivel', $txts, true)
            || (in_array('combustivel', $txts, true) && (in_array('salario bruto', $txts, true)
                || in_array('salario', $txts, true) || in_array('salario liquido', $txts, true)))
            // No Laboratório o cabeçalho é "Aux. Combustível" + "Salário" — casa aqui.
            || (in_array('aux. combustivel', $txts, true) && in_array('salario', $txts, true))) {
            $cabecalho = $r['cells'];
            $cabecalhoLinha = $r['row'];
            break;
        }
    }
    if ($cabecalho === null) {
        // Sem cabeçalho reconhecível → não tem como extrair
        return [];
    }

    $colNome = -1;
    $colAux  = -1;
    $colVa   = -1;
    $colComissao       = -1;
    $colSalarioBruto   = -1;
    $colSalarioLiquido = -1;
    foreach ($cabecalho as $idx => $rotulo) {
        $n = strtolower(semAcento(trim((string) $rotulo)));
        if ($colNome === -1 && (strpos($n, 'funcion') !== false || strpos($n, 'nome') !== false || $n === 'funcionario')) {
            $colNome = $idx;
        }
        if ($colAux === -1 && (strpos($n, 'aux. combust') !== false || strpos($n, 'auxilio combust') !== false
            || strpos($n, 'combust') !== false)) {
            $colAux = $idx;
        }
        // Comissão: aceita "Comissão", "Comissao". Só aparece na aba FS,
        // mas a detecção é genérica — nas abas sem a coluna fica -1 (zerada).
        if ($colComissao === -1 && strpos($n, 'comiss') !== false) {
            $colComissao = $idx;
        }
        // VA: aceita "VA", "V.A." (com pontos) e "Vale Alimentação/ Alim.".
        // NÃO aceita "Vale" sozinho — no Lab, "Vale" é um ajuste (-500 etc).
        if ($colVa === -1 && (trim($n) === 'va' || trim($n) === 'v.a.' || trim($n) === 'v.a'
            || strpos($n, 'vale aliment') !== false || strpos($n, 'vale alim') !== false)) {
            $colVa = $idx;
        }
        // Salário Bruto: aceita "Salário Bruto", "Sálario Bruto" (sem acento
        // — variação que aparece na aba Laboratório), ou "Salário"/"Sálario"
        // quando o rótulo contém "bruto" OU quando ainda não há coluna Bruto
        // e a coluna é a ÚNICA de salário (caso comum).
        if ($colSalarioBruto === -1 && (strpos($n, 'salario bruto') !== false
            || strpos($n, 'salario') !== false && strpos($n, 'bruto') !== false)) {
            $colSalarioBruto = $idx;
        }
        if ($colSalarioLiquido === -1 && (strpos($n, 'salario liquido') !== false || strpos($n, 'liquido') !== false)) {
            $colSalarioLiquido = $idx;
        }
    }
    // Se nem "VA" nem "V.A." foram achados, procura por nome idêntico
    if ($colVa === -1) {
        foreach ($cabecalho as $idx => $rotulo) {
            $n = strtolower(trim((string) $rotulo));
            if ($n === 'va' || $n === 'v.a.') { $colVa = $idx; break; }
        }
    }
    // Se "Sálario" foi marcado mas tem "Salário" + "Sálario Bruto" (caso do
    // Lab), o primeiro detectado pelo contains "salario" pode ser o errado.
    // O Lab tem "Sálario Bruto" (col 2) e "Salário" (col 3) — queremos o 2.
    // A lógica atual com contains já pega "Sálario Bruto" primeiro se a
    // iteração for na ordem (e como o cabeçalho vem na ordem das colunas,
    // sim). Nada a fazer aqui.
    if ($colNome === -1 || $colAux === -1 || $colVa === -1) {
        // Sem mapeamento, não dá pra continuar
        return [];
    }

    // 3) Itera as linhas DEPOIS do cabeçalho, ATÉ o primeiro "PAGAMENTO" / "Obs".
    //    A folha de Agosto tem dois blocos (FOLHA + PAGAMENTO) e várias seções
    //    de observações. Só nos interessa o primeiro bloco.
    $funcionarios = [];
    $parou = false;
    foreach ($rows as $r) {
        if ($r['row'] <= $cabecalhoLinha) {
            continue;
        }
        $cells = $r['cells'];
        $nomeBruto = trim((string) ($cells[$colNome] ?? ''));
        $nomeNorm  = strtolower(semAcento($nomeBruto));

        // Marcadores de fim do bloco de funcionários. Tudo depois disso é
        // ignorado (segundo bloco "PAGAMENTO", observações, etc.).
        if ($nomeNorm === 'pagamento' || $nomeNorm === 'obs' || $nomeNorm === 'observacoes'
            || $nomeNorm === 'observações' || strpos($nomeNorm, 'funcionario') === 0
            || strpos($nomeNorm, 'folha de pagamento') === 0) {
            $parou = true;
        }
        if ($parou) {
            continue;
        }
        if ($nomeBruto === '') {
            continue;
        }
        // Pula linhas de cabeçalho repetido e totais
        if ($nomeNorm === 'total' || $nomeNorm === 'funcionarios' || $nomeNorm === 'funcionário') {
            continue;
        }

        $comb = parseValorFolha($cells[$colAux] ?? '');
        $va   = parseValorFolha($cells[$colVa] ?? '');
        $comissaoFolha = $colComissao !== -1 ? parseValorFolha($cells[$colComissao] ?? '') : 0.0;
        $brutoFolha   = $colSalarioBruto   !== -1 ? parseValorFolha($cells[$colSalarioBruto]   ?? '') : 0.0;
        $liquidoFolha = $colSalarioLiquido !== -1 ? parseValorFolha($cells[$colSalarioLiquido] ?? '') : 0.0;
        // Mantém o funcionário SE tiver algum ADIANTAMENTO: Aux, VA ou AJ.CUSTO.
        // Quem só tem salário (sem Aux/VA) é descartado — esses funcionários
        // não pegaram adiantamento e não geram Recibo de Vales nem
        // Comprovante de Pagamento no nosso sistema. Os dois documentos são
        // sobre adiantamentos; o salário puro vai no contracheque normal.
        if ($comb <= 0 && $va <= 0) {
            continue;
        }

        // Limpa anotações no nome (mesma heurística do formato antigo).
        $nomeLimpo = preg_replace('/\s*\([^)]*\)\s*$/', '', $nomeBruto);
        $nomeLimpo = preg_replace('/\s+(?:AC|AJ)\b.*$/i', '', $nomeLimpo);
        $nomeLimpo = trim($nomeLimpo);
        if ($nomeLimpo === '') {
            $nomeLimpo = $nomeBruto;
        }

        $funcionarios[] = [
            'empresa'         => $empresa,
            'nome'            => $nomeLimpo,
            'combustivel'     => $comb,
            'alimentacao'     => $va,
            'aj_custo'        => 0.0,
            'comissao'        => $comissaoFolha,
            'salario_bruto'   => $brutoFolha,
            'salario_liquido' => $liquidoFolha,
        ];
    }
    return $funcionarios;
}

/**
 * Lê uma planilha no formato antigo (seções por empresa, com cabeçalho
 * "Funcionários FS / Clínica / laboratório"). Esta é a função que existia
 * antes, mantida para retrocompatibilidade.
 */
function extrairFuncionariosXlsxSecoes(array $rows): array
{
    $funcionarios = [];
    $empresaAtual = null;   // chave em EMPRESAS ou null (fora de seção válida)
    $pularSecao = false;

    foreach ($rows as $r) {
        $cells = $r['cells'];
        $a = isset($cells[0]) ? trim($cells[0]) : '';

        // Cabeçalho de seção ("Funcionários FS", etc.)
        if ($a !== '') {
            $det = detectarEmpresaSecao($a);
            if ($det !== null) {
                if ($det === '__skip__') {
                    $pularSecao = true;
                    $empresaAtual = null;
                } else {
                    $pularSecao = false;
                    $empresaAtual = $det;
                }
                continue;
            }
        }

        if ($pularSecao || $empresaAtual === null) {
            continue;
        }
        if ($a === '' || strcasecmp($a, 'TOTAL') === 0) {
            continue;
        }
        // Linhas de resumo/rodapé ("39 funcionário pegam VT e AL", "Total em ...")
        if (preg_match('/^\d+\s+funcion/i', $a) || stripos($a, 'Total em') === 0) {
            continue;
        }

        $comb = parseValor($cells[2] ?? '');   // coluna C — Aux. Combustível
        $alim = parseValor($cells[3] ?? '');   // coluna D — VA (Vale Alimentação)
        $aj   = parseValor($cells[4] ?? '');   // coluna E — AJ. CUSTO (Ajuda de Custo)
        if ($comb + $alim + $aj <= 0) {
            continue; // sem valor a lançar no recibo
        }

        // Limpa anotações operacionais finais que vêm da planilha e não fazem
        // parte do nome do funcionário (ex.: "(inicio 19/01)", "(29/01)",
        // "(R$ 3.000,00 + ...)" ou "AC 200,00)" sem parêntese de abertura).
        // Tais anotações não devem aparecer na linha de assinatura do recibo.
        $nomeLimpo = preg_replace('/\s*\([^)]*\)\s*$/', '', $a);
        $nomeLimpo = preg_replace('/\s+(?:AC|AJ)\b.*$/i', '', $nomeLimpo);
        $nomeLimpo = trim($nomeLimpo);
        if ($nomeLimpo === '') {
            $nomeLimpo = $a;
        }

        $funcionarios[] = [
            'empresa'     => $empresaAtual,
            'nome'        => $nomeLimpo,
            'combustivel' => $comb,
            'alimentacao' => $alim,
            'aj_custo'    => $aj,
            'comissao'    => 0.0,
        ];
    }

    return $funcionarios;
}
