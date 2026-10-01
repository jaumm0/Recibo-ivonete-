<?php
/**
 * configuracoes.php
 * Página onde o usuário pode editar os textos do modelo de recibo
 * (título, rótulos, frases de abertura/fechamento). Os valores ficam
 * salvos em _config_recibo.json e são lidos por gerar.php + modelo.php
 * na hora de montar o DOCX/HTML.
 */

declare(strict_types=1);

const CONFIG_FILE  = __DIR__ . '/_config_recibo.json';
const DEFAULTS     = [
    // Títulos
    'titulo_comprovante_vales'      => 'COMPROVANTE DE VALES',
    'titulo_comprovante_pagamento'  => 'COMPROVANTE DE PAGAMENTO',

    // Rótulos das linhas de valores
    'rotulo_auxilio'                => 'Auxílio combustível',
    'rotulo_vale'                   => 'Vale alimentação',
    'rotulo_aj'                     => 'AJ. CUSTO',

    // Frases
    'frase_recebi'                  => 'Recebi da',
    'frase_referente_aux_va'        => 'referente a Auxílio Combustível e Vale Alimentação',
    'frase_referente_aux_va_aj'     => 'referente a Auxílio Combustível, Vale Alimentação e AJ. CUSTO',
    'frase_pagamento'               => 'a título de pagamento de salário líquido',
    'fechamento'                    => 'E por ser verdade assino o presente recibo.',
    'conforme_descrito'             => 'conforme descrito abaixo.',
];

function carregarConfigRecibo(): array {
    if (!is_readable(CONFIG_FILE)) {
        return DEFAULTS;
    }
    $raw = file_get_contents(CONFIG_FILE);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) return DEFAULTS;
    // Mescla com defaults — se uma chave sumir do JSON, usa o default.
    return array_merge(DEFAULTS, $data);
}

function salvarConfigRecibo(array $data): bool {
    // Mantém só chaves conhecidas e remove entradas vazias.
    $limpo = [];
    foreach (DEFAULTS as $k => $_) {
        $v = trim((string) ($data[$k] ?? ''));
        $limpo[$k] = $v !== '' ? $v : DEFAULTS[$k];
    }
    return file_put_contents(CONFIG_FILE, json_encode($limpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function restaurarConfigRecibo(): void {
    if (is_file(CONFIG_FILE)) @unlink(CONFIG_FILE);
}

// ============ Tratamento das requisições ============

$mensagem = '';
$erro     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar') {
        if (salvarConfigRecibo($_POST)) {
            $mensagem = 'Configurações salvas. Os próximos documentos vão usar os textos novos.';
        } else {
            $erro = 'Não foi possível salvar o arquivo de configuração.';
        }
    } elseif ($acao === 'restaurar') {
        restaurarConfigRecibo();
        $mensagem = 'Configurações restauradas para o padrão.';
    }
}

$cfg = carregarConfigRecibo();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurações do Recibo</title>
<style>
  :root {
    --bg:#f6f7f9; --card:#fff; --line:#e6e8ec; --ink:#0f172a; --mut:#64748b;
    --label:#334155; --prim:#2563eb; --prim-d:#1d4ed8; --prim-soft:#eef4ff;
    --ok:#059669; --ok-soft:#ecfdf5; --ok-line:#a7f3d0;
    --warn:#d97706; --warn-soft:#fffbeb; --warn-line:#fde68a;
    --radius:14px;
  }
  *{box-sizing:border-box;}
  html,body{height:100%;}
  body{
    font-family:"Inter",system-ui,"Segoe UI",Roboto,Arial,sans-serif;
    background:radial-gradient(1100px 560px at 100% -12%, #eef2ff 0%, rgba(238,242,255,0) 58%), var(--bg);
    color:var(--ink); margin:0; padding:36px 16px 56px; line-height:1.5;
  }
  .wrap{max-width:880px;margin:0 auto;}
  header.topo{margin-bottom:24px;}
  header.topo h1{margin:0;font-size:28px;font-weight:700;letter-spacing:-.025em;}
  header.topo p{margin:6px 0 0;color:var(--mut);font-size:14px;}
  .card{
    background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
    padding:28px; margin-bottom:18px;
    box-shadow:0 1px 2px rgba(15,23,42,.04), 0 10px 30px -18px rgba(15,23,42,.22);
  }
  .card h2{
    font-size:16px; font-weight:700; margin:0 0 18px; padding-bottom:14px;
    border-bottom:1px solid var(--line); display:flex; align-items:center; gap:10px;
  }
  .badge{
    font-size:11px; background:var(--prim-soft); color:var(--prim);
    padding:3px 10px; border-radius:999px; font-weight:600;
  }
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 20px;}
  .full{grid-column:1 / -1;}
  label{
    display:block; font-size:12.5px; font-weight:600; margin-bottom:6px;
    color:var(--label); letter-spacing:.01em;
  }
  .hint{font-weight:400;color:var(--mut);font-size:11.5px;}
  input[type=text]{
    width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:10px;
    font-size:14px; font-family:inherit; background:#fff; color:var(--ink);
    transition:border-color .15s, box-shadow .15s;
  }
  input[type=text]:focus{
    outline:none; border-color:var(--prim);
    box-shadow:0 0 0 3px rgba(37,99,235,.16);
  }
  .acoes{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px;}
  button{
    border:1px solid transparent; border-radius:10px; padding:11px 18px;
    font-size:14px; font-weight:600; cursor:pointer; font-family:inherit;
    display:inline-flex; align-items:center; gap:8px;
  }
  .btn-prim{background:var(--prim);color:#fff;}
  .btn-prim:hover{background:var(--prim-d);}
  .btn-sec{background:#fff;color:var(--ink);border:1px solid var(--line);}
  .btn-sec:hover{background:#f8fafc;border-color:#cbd5e1;}
  .btn-warn{background:#fff;color:var(--warn);border:1px solid var(--warn-line);}
  .btn-warn:hover{background:var(--warn-soft);}
  .aviso{
    font-size:13px; color:var(--mut); margin-top:14px;
    padding:10px 14px; border-radius:10px;
    display:flex; gap:10px; align-items:flex-start; line-height:1.5;
  }
  .aviso.ok{background:var(--ok-soft);border:1px solid var(--ok-line);color:#065f46;}
  .aviso.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
  .preview-box{
    background:#fafbfc; border:1px solid var(--line); border-radius:10px;
    padding:14px 16px; font-family:Georgia,serif; font-size:13.5px; color:var(--label);
    line-height:1.6; margin-top:8px;
  }
  @media (max-width:620px){
    body{padding:24px 12px 40px;}
    .grid{grid-template-columns:1fr;}
    .card{padding:22px;}
  }
</style>
</head>
<body>
<div class="wrap">

  <header class="topo">
    <h1>Configurações do Recibo</h1>
    <p>Edite os textos do modelo. Os valores salvos passam a ser usados em todos os recibos gerados.</p>
  </header>

  <?php if ($mensagem): ?>
    <div class="aviso ok">✓ <?= htmlspecialchars($mensagem) ?></div>
  <?php elseif ($erro): ?>
    <div class="aviso err">⚠ <?= htmlspecialchars($erro) ?></div>
  <?php endif; ?>

  <form method="post" class="card">
    <input type="hidden" name="acao" value="salvar">

    <h2>Títulos <span class="badge">cabeçalho</span></h2>
    <div class="grid">
      <div class="full">
        <label for="titulo_comprovante_vales">Comprovante de Vales</label>
        <input type="text" id="titulo_comprovante_vales" name="titulo_comprovante_vales"
               value="<?= htmlspecialchars($cfg['titulo_comprovante_vales']) ?>">
        <div class="preview-box"><strong><?= htmlspecialchars($cfg['titulo_comprovante_vales']) ?></strong></div>
      </div>
      <div class="full">
        <label for="titulo_comprovante_pagamento">Comprovante de Pagamento</label>
        <input type="text" id="titulo_comprovante_pagamento" name="titulo_comprovante_pagamento"
               value="<?= htmlspecialchars($cfg['titulo_comprovante_pagamento']) ?>">
        <div class="preview-box"><strong><?= htmlspecialchars($cfg['titulo_comprovante_pagamento']) ?></strong></div>
      </div>
    </div>

    <h2 style="margin-top:24px;">Rótulos das linhas <span class="badge">valores</span></h2>
    <div class="grid">
      <div>
        <label for="rotulo_auxilio">Auxílio combustível</label>
        <input type="text" id="rotulo_auxilio" name="rotulo_auxilio"
               value="<?= htmlspecialchars($cfg['rotulo_auxilio']) ?>">
      </div>
      <div>
        <label for="rotulo_vale">Vale alimentação</label>
        <input type="text" id="rotulo_vale" name="rotulo_vale"
               value="<?= htmlspecialchars($cfg['rotulo_vale']) ?>">
      </div>
      <div class="full">
        <label for="rotulo_aj">AJ. CUSTO <span class="hint">(só aparece se o valor for maior que zero)</span></label>
        <input type="text" id="rotulo_aj" name="rotulo_aj"
               value="<?= htmlspecialchars($cfg['rotulo_aj']) ?>">
      </div>
    </div>

    <h2 style="margin-top:24px;">Frases <span class="badge">corpo do recibo</span></h2>
    <div class="grid">
      <div class="full">
        <label for="frase_recebi">Abertura <span class="hint">(antes do nome da empresa)</span></label>
        <input type="text" id="frase_recebi" name="frase_recebi"
               value="<?= htmlspecialchars($cfg['frase_recebi']) ?>">
      </div>
      <div class="full">
        <label for="frase_referente_aux_va">Frase para Comprovante de Vales <span class="hint">(sem AJ. CUSTO)</span></label>
        <input type="text" id="frase_referente_aux_va" name="frase_referente_aux_va"
               value="<?= htmlspecialchars($cfg['frase_referente_aux_va']) ?>">
      </div>
      <div class="full">
        <label for="frase_referente_aux_va_aj">Frase para Comprovante de Vales <span class="hint">(com AJ. CUSTO)</span></label>
        <input type="text" id="frase_referente_aux_va_aj" name="frase_referente_aux_va_aj"
               value="<?= htmlspecialchars($cfg['frase_referente_aux_va_aj']) ?>">
      </div>
      <div class="full">
        <label for="frase_pagamento">Frase para Comprovante de Pagamento</label>
        <input type="text" id="frase_pagamento" name="frase_pagamento"
               value="<?= htmlspecialchars($cfg['frase_pagamento']) ?>">
      </div>
      <div class="full">
        <label for="conforme_descrito">Encerramento da frase de abertura</label>
        <input type="text" id="conforme_descrito" name="conforme_descrito"
               value="<?= htmlspecialchars($cfg['conforme_descrito']) ?>">
      </div>
      <div class="full">
        <label for="fechamento">Fechamento do recibo</label>
        <input type="text" id="fechamento" name="fechamento"
               value="<?= htmlspecialchars($cfg['fechamento']) ?>">
      </div>
    </div>

    <div class="acoes">
      <button type="submit" class="btn-prim">💾 Salvar configurações</button>
      <a href="index.php" class="btn-sec" style="text-decoration:none;">← Voltar</a>
    </div>
  </form>

  <form method="post" class="card" style="text-align:center;">
    <input type="hidden" name="acao" value="restaurar">
    <p style="margin:0 0 12px;color:var(--mut);">Apaga as configurações personalizadas e volta aos textos padrão.</p>
    <button type="submit" class="btn-warn"
            onclick="return confirm('Restaurar todos os textos para o padrão? Isso não pode ser desfeito.')">
      ↺ Restaurar textos padrão
    </button>
  </form>

</div>
</body>
</html>