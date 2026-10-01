<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gerador de Recibos</title>
<style>
  :root {
    --bg: #f6f7f9;
    --card: #ffffff;
    --prim: #2563eb;
    --prim-d: #1d4ed8;
    --prim-soft: #eef4ff;
    --ink: #0f172a;
    --label: #334155;
    --mut: #64748b;
    --line: #e6e8ec;
    --ok: #059669;
    --ok-soft: #ecfdf5;
    --ok-line: #a7f3d0;
    --radius: 14px;
    --shadow: 0 1px 2px rgba(15,23,42,.04), 0 10px 30px -18px rgba(15,23,42,.22);
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    font-family: "Inter", system-ui, "Segoe UI", Roboto, Arial, sans-serif;
    background:
      radial-gradient(1100px 560px at 100% -12%, #eef2ff 0%, rgba(238,242,255,0) 58%),
      var(--bg);
    color: var(--ink);
    margin: 0;
    padding: 44px 16px 56px;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
    line-height: 1.5;
  }
  .wrap { max-width: 1280px; margin: 0 auto; }

  /* Cabeçalho */
  header.topo { margin-bottom: 30px; }

  /* Layout lado a lado */
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; align-items: start; }
  header.topo h1 {
    margin: 0;
    font-size: 30px;
    font-weight: 700;
    letter-spacing: -.025em;
    color: var(--ink);
  }
  header.topo p { margin: 7px 0 0; color: var(--mut); font-size: 15px; }

  /* Card */
  .card {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 30px;
    margin-bottom: 22px;
    box-shadow: var(--shadow);
  }
  .card h2 {
    font-size: 17px;
    font-weight: 700;
    letter-spacing: -.012em;
    margin: 0 0 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--line);
    display: flex; align-items: center; gap: 10px;
  }
  .badge {
    font-size: 11px;
    background: var(--prim-soft);
    color: var(--prim);
    padding: 3px 10px;
    border-radius: 999px;
    font-weight: 600;
    letter-spacing: .02em;
  }

  /* Grade de campos */
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 20px; }
  .full { grid-column: 1 / -1; }
  label {
    display: block;
    font-size: 12.5px;
    font-weight: 600;
    margin-bottom: 7px;
    color: var(--label);
    letter-spacing: .01em;
  }
  .hint { font-weight: 400; color: var(--mut); font-size: 11.5px; }

  /* Inputs / select */
  input[type=text], input[type=date], input[type=number], input[type=file], select {
    width: 100%;
    padding: 11px 13px;
    border: 1px solid var(--line);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    background: #fff;
    color: var(--ink);
    transition: border-color .15s, box-shadow .15s;
  }
  input::placeholder { color: #9aa4b2; }
  input[type=file] { padding: 8px 11px; background: #fbfcfd; cursor: pointer; }
  input[type=file]::file-selector-button {
    border: 1px solid var(--line);
    background: #fff;
    color: var(--label);
    padding: 6px 12px;
    border-radius: 8px;
    font: inherit;
    font-size: 13px;
    margin-right: 12px;
    cursor: pointer;
    transition: background .15s;
  }
  input[type=file]::file-selector-button:hover { background: #f1f5f9; }
  input:focus, select:focus {
    outline: none;
    border-color: var(--prim);
    box-shadow: 0 0 0 3px rgba(37,99,235,.16);
  }
  select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 13px center;
    padding-right: 38px;
  }

  /* Dropdown customizado de Empresa (substitui <select> pra mostrar logos) */
  .emp-dd {
    position: relative;
    width: 100%;
  }
  .emp-dd-btn {
    width: 100%;
    display: flex; align-items: center; gap: 12px;
    padding: 9px 14px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    color: var(--ink);
    text-align: left;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
  }
  .emp-dd-btn:hover { border-color: #cbd5e1; }
  .emp-dd-btn:focus {
    outline: none;
    border-color: var(--prim);
    box-shadow: 0 0 0 3px rgba(37,99,235,.16);
  }
  .emp-dd[data-open="true"] .emp-dd-btn {
    border-color: var(--prim);
    box-shadow: 0 0 0 3px rgba(37,99,235,.16);
  }
  .emp-dd-caret {
    margin-left: auto;
    color: var(--mut);
    transition: transform .2s;
  }
  .emp-dd[data-open="true"] .emp-dd-caret { transform: rotate(180deg); }

  .emp-dd-logo {
    width: 38px; height: 38px; flex: none;
    border-radius: 8px;
    background-color: #f1f5f9;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    border: 1px solid var(--line);
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13px; color: var(--label);
  }
  .emp-dd-logo-vazio {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #94a3b8;
    font-size: 18px;
  }
  .emp-dd-logo-fs {
    background: #2563eb;
    color: #fff;
    font-size: 15px;
    letter-spacing: -1px;
    border-color: #1d4ed8;
  }
  .emp-dd-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .emp-dd-list {
    position: absolute;
    top: calc(100% + 6px);
    left: 0; right: 0;
    margin: 0;
    padding: 6px;
    list-style: none;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    box-shadow: 0 10px 30px -12px rgba(15,23,42,.22), 0 4px 12px -6px rgba(15,23,42,.12);
    z-index: 50;
    max-height: 320px;
    overflow-y: auto;
    display: none;
  }
  .emp-dd[data-open="true"] .emp-dd-list { display: block; }

  .emp-dd-item {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: background .12s;
    font-size: 14px;
    color: var(--ink);
  }
  .emp-dd-item:hover,
  .emp-dd-item:focus { background: var(--prim-soft); outline: none; }
  .emp-dd-item[aria-selected="true"] {
    background: var(--prim-soft);
    color: var(--prim);
    font-weight: 600;
  }
  .emp-dd-item .emp-dd-nome { flex: 1; }
  .emp-dd-item .emp-dd-check {
    width: 16px; height: 16px; flex: none;
    color: var(--prim);
    opacity: 0;
  }
  .emp-dd-item[aria-selected="true"] .emp-dd-check { opacity: 1; }

  /* Caixa de valor total */
  .total {
    background: var(--ok-soft);
    border: 1px solid var(--ok-line);
    border-left: 3px solid var(--ok);
    border-radius: 12px;
    padding: 16px 18px;
  }
  .total .valor { font-size: 24px; font-weight: 800; color: var(--ok); letter-spacing: -.02em; margin-top: 6px; }
  .total .ext { font-size: 13px; color: var(--mut); font-style: italic; margin-top: 6px; }
  .total input { background: #fff; color: var(--ok); font-weight: 800; border-color: var(--ok-line); }

  /* Botões */
  .acoes { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 26px; }
  button, .btn {
    border: 1px solid transparent;
    border-radius: 10px;
    padding: 11px 18px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: background .15s, border-color .15s, box-shadow .15s, transform .04s;
  }
  .btn-prim { background: var(--prim); color: #fff; box-shadow: 0 1px 2px rgba(37,99,235,.22); }
  .btn-prim:hover { background: var(--prim-d); }
  .btn-prim:active { transform: translateY(1px); }
  .btn-sec { background: #fff; color: var(--ink); border: 1px solid var(--line); }
  .btn-sec:hover { background: #f8fafc; border-color: #cbd5e1; }
  .btn-sec:active { transform: translateY(1px); }

  /* Aviso / nota de ajuda */
  .aviso {
    font-size: 12px; color: var(--mut); margin-top: 12px;
    display: flex; gap: 8px; align-items: flex-start; line-height: 1.45;
  }
  .aviso::before {
    content: "i"; width: 16px; height: 16px; flex: none; margin-top: 1px;
    background: var(--prim-soft); color: var(--prim); border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-style: italic; font-size: 11px;
  }

  /* Detalhes / exemplo */
  details { margin-top: 18px; }
  details summary { cursor: pointer; font-weight: 600; font-size: 13.5px; color: var(--label); }
  details summary:hover { color: var(--prim); }
  details p { font-size: 13px; color: var(--mut); margin: 10px 0 8px; }
  code { background: #f1f5f9; border: 1px solid var(--line); border-radius: 5px; padding: 1px 5px; font-size: 12px; font-family: ui-monospace, Consolas, monospace; }
  .exemplo {
    background: #0f172a; color: #e2e8f0;
    border-radius: 10px; padding: 14px 16px;
    font-family: ui-monospace, Consolas, monospace;
    font-size: 12px; white-space: pre; overflow-x: auto; margin-top: 10px;
  }

  footer { text-align: center; color: var(--mut); font-size: 12px; margin-top: 20px; }

  /* Responsivo */
  @media (max-width: 920px) {
    .row { grid-template-columns: 1fr; }
  }
  @media (max-width: 620px) {
    body { padding: 26px 12px 40px; }
    header.topo h1 { font-size: 25px; }
    .card { padding: 20px; }
    .grid { grid-template-columns: 1fr; gap: 16px; }
  }
</style>
</head>
<body>
<div class="wrap">

  <header class="topo">
    <h1>Gerador de Recibos</h1>
    <p>Preencha os campos — o sistema sobrescreve o DOCX modelo automaticamente.</p>
  </header>

  <!-- ================= RECIBO ÚNICO ================= -->
  <div class="row">
  <section class="card">
    <h2>Recibo individual <span class="badge">automático</span></h2>
    <form id="formRecibo" action="gerar.php" method="post" target="_blank">
      <input type="hidden" name="acao" value="gerar">

      <div class="grid">
        <div class="full">
          <label for="empresa">Empresa</label>
          <!-- Dropdown customizado (substitui <select>): permite mostrar
               logo + nome lado a lado em cada opção. O input hidden
               embaixo mantém o nome "empresa" pra não quebrar o backend. -->
          <div class="emp-dd" id="empDd" data-open="false">
            <button type="button" class="emp-dd-btn" id="empDdBtn"
                    aria-haspopup="listbox" aria-expanded="false">
              <span class="emp-dd-logo" id="empDdLogo"></span>
              <span class="emp-dd-label" id="empDdLabel">— Selecione a empresa —</span>
              <svg class="emp-dd-caret" width="14" height="14" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2.2"
                   stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <ul class="emp-dd-list" id="empDdList" role="listbox">
                <li class="emp-dd-item" role="option" data-value="">
                  <span class="emp-dd-logo emp-dd-logo-vazio"></span>
                  <span class="emp-dd-nome">— Selecione —</span>
                </li>
                <li class="emp-dd-item" role="option" data-value="fs">
                  <span class="emp-dd-logo emp-dd-logo-fs">FS</span>
                  <span class="emp-dd-nome">FS Administradora de Cartões</span>
                </li>
                <li class="emp-dd-item" role="option" data-value="clinica">
                  <span class="emp-dd-logo" style="background-image:url('WhatsApp Image 2026-08-06 at 8.52.03 AM.jpeg')"></span>
                  <span class="emp-dd-nome">Clínica Saúde</span>
                </li>
                <li class="emp-dd-item" role="option" data-value="laboratorio">
                  <span class="emp-dd-logo" style="background-image:url('WhatsApp Image 2026-08-06 at 8.51.33 AM.jpeg')"></span>
                  <span class="emp-dd-nome">Laboratório</span>
                </li>
              </ul>
          </div>
          <input type="hidden" name="empresa" id="empresa" value="">
        </div>

        <div>
          <label for="periodo">Período <span class="hint">(ex.: 01/05/2026 a 31/05/2026 ou Maio/2026)</span></label>
          <input type="text" id="periodo" name="periodo" placeholder="Ex.: 01/05/2026 a 31/05/2026">
        </div>

        <div>
          <label for="cidade">Local <span class="hint">(cidade, ex.: Campo Grande)</span></label>
          <input type="text" id="cidade" name="cidade" placeholder="Ex.: Campo Grande">
        </div>

        <div>
          <label for="data">Data do recibo</label>
          <input type="date" id="data" name="data">
        </div>

        <div>
          <label for="nome">Nome do funcionário</label>
          <input type="text" id="nome" name="nome" placeholder="Ex.: João da Silva" required>
        </div>

        <div>
          <label for="cpf">CPF</label>
          <input type="text" id="cpf" name="cpf" placeholder="000.000.000-00" maxlength="14">
        </div>

        <div>
          <label for="combustivel">Auxílio Combustível (R$)</label>
          <input type="text" id="combustivel" name="combustivel" inputmode="decimal"
                 placeholder="0,00" oninput="recalcular()">
        </div>

        <div>
          <label for="alimentacao">Vale Alimentação (R$)</label>
          <input type="text" id="alimentacao" name="alimentacao" inputmode="decimal"
                 placeholder="0,00" oninput="recalcular()">
        </div>

        <div class="full total">
          <label for="valor_total">Valor Total <span class="hint">(calculado automaticamente — não editar)</span></label>
          <input type="text" id="valor_total" name="valor_total" readonly>
          <div class="valor" id="valor_total_display">R$ 0,00</div>
          <div class="ext" id="extenso_display">&nbsp;</div>
        </div>
      </div>

      <div class="acoes">
        <button type="submit" name="formato" value="docx" class="btn-prim">⬇ Salvar DOCX</button>
        <button type="submit" name="formato" value="html" class="btn-sec" formaction="gerar.php?acao=preview" target="_blank">👁 Pré-visualizar</button>
        <button type="button" class="btn-prim" onclick="imprimirDireto()">🖨 Imprimir / Salvar PDF</button>
      </div>
      <div class="aviso">Imprimir abre direto a janela do navegador com o recibo pronto — escolha a impressora ou "Salvar como PDF" no diálogo. Pré-visualizar só abre o recibo sem disparar a impressão.</div>
    </form>
  </section>

  <!-- ================= LOTE (CSV) ================= -->
  <section class="card">
    <h2>Gerar em lote <span class="badge">XLSX / CSV</span></h2>
    <p style="margin-top:0;color:var(--mut);font-size:13px;">
      Importe a planilha (XLSX direto ou CSV) com vários funcionários. O sistema gera um
      DOCX para cada um e entrega tudo em um único <strong>.zip</strong>.
    </p>

    <form action="gerar.php?acao=preview_lote" method="post" enctype="multipart/form-data">
      <input type="hidden" name="acao" value="preview_lote">

      <div class="grid">
        <div class="full">
          <label for="empresa_l">Empresa <span class="hint">(somente para CSV — no XLSX é automática por seção)</span></label>
          <select id="empresa_l" name="empresa">
            <option value="">— Selecione (CSV) —</option>
            <option value="fs">FS Administradora de Cartões</option>
            <option value="clinica">Clínica Saúde</option>
            <option value="laboratorio">Laboratório</option>
          </select>
        </div>
        <div>
          <label for="periodo_inicio">Período inicial</label>
          <input type="date" id="periodo_inicio" name="periodo_inicio">
        </div>
        <div>
          <label for="periodo_final">Período final</label>
          <input type="date" id="periodo_final" name="periodo_final">
        </div>
        <div>
          <label for="data_l">Data (comum)</label>
          <input type="date" id="data_l" name="data">
        </div>
        <div class="full">
          <label for="arquivo">Arquivo XLSX ou CSV</label>
          <input type="file" id="arquivo" name="arquivo"
                 accept=".xlsx,.csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          <div class="hint" style="margin-top:6px;">XLSX: a empresa é detectada por seção (FS, Clínica, laboratório). CSV: informe a empresa acima. Coluna E (AJ. CUSTO) é lida e usada apenas no Comprovante.</div>
        </div>
      </div>

      <div class="acoes">
        <button type="submit" class="btn-prim">📋 Ver prévia e gerar (.zip)</button>
      </div>

      <details style="margin-top:16px;">
        <summary>Formato esperado da planilha</summary>
        <p style="font-size:13px;color:var(--mut);"><strong>XLSX:</strong> coluna A=Nome, C=Aux. Combustível, D=Vale Alimentação, E=AJ. CUSTO. Seções por empresa iniciadas por "Funcionários FS / Clínica / laboratório". VT (B) e a coluna TOTAL (F) são ignorados. A seção Fidelidade é pulada. Linhas sem valor ficam de fora.</p>
        <p style="font-size:13px;color:var(--mut);"><strong>CSV:</strong> a primeira linha é o cabeçalho. Colunas reconhecidas pelo nome (Nome, CPF, Combustível, Alimentação, AJ. Custo). Delimitadores: <code>;</code>, <code>,</code>, <code>tab</code> e <code>|</code>.</p>
        <div class="exemplo">Nome;CPF;Combustível;Alimentação
João;111.222.333-44;257,40;207,90
Maria;222.333.444-55;300,00;150,00
José;333.444.555-66;280,00;180,00</div>
      </details>
    </form>
  </section>

  </div><!-- /.row -->

  <footer>
    Layout fiel ao DOCX modelo · Valor total e extenso calculados automaticamente · Sem edição manual.
    · <a href="configuracoes.php" style="color:var(--prim);text-decoration:none;">⚙ Editar textos do modelo</a>
  </footer>
</div>

<script>
/* ---- Máscara de CPF ---- */
const cpf = document.getElementById('cpf');
cpf.addEventListener('input', () => {
  let v = cpf.value.replace(/\D/g, '').slice(0, 11);
  v = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2')
       .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  cpf.value = v;
});

/* ---- Parse de valor BR ---- */
function parseBR(s) {
  if (!s) return 0;
  s = String(s).replace(/[^\d,.\-]/g, '');
  if (s.indexOf(',') >= 0 && s.indexOf('.') >= 0) {
    s = s.replace(/\./g, '').replace(',', '.');
  } else if (s.indexOf(',') >= 0) {
    s = s.replace(',', '.');
  }
  return parseFloat(s) || 0;
}

function fmtBR(n) {
  return 'R$ ' + n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ---- Extenso (client-side, só para prévia; o servidor é autoritativo) ---- */
const UN = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove',
  'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
const DEZ = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
const CEN = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

function tres(n) {
  let s = '';
  const c = Math.floor(n / 100), d = Math.floor((n % 100) / 10), u = n % 10, du = n % 100;
  if (n === 100) return 'cem';
  if (c) s = CEN[c];
  if (du > 0 && du < 20) { s += (s ? ' e ' : '') + UN[du]; }
  else { if (d) s += (s ? ' e ' : '') + DEZ[d]; if (u) s += (s ? ' e ' : '') + UN[u]; }
  return s;
}

function numExtenso(n) {
  if (n === 0) return 'zero';
  const nomesP = ['', 'mil', 'milhões', 'bilhões', 'trilhões'];
  const nomesS = ['', 'mil', 'milhão', 'bilhão', 'trilhão'];
  const grupos = [];
  let r = n;
  do { grupos.push(r % 1000); r = Math.floor(r / 1000); } while (r > 0);
  const partes = [];
  for (let i = grupos.length - 1; i >= 0; i--) {
    const g = grupos[i];
    if (!g) continue;
    let t = tres(g);
    if (i > 0) t += ' ' + (g === 1 ? nomesS[i] : nomesP[i]);
    partes.push(t);
  }
  return partes.join(' e ');
}

function extensoReais(v) {
  v = Math.round(v * 100) / 100;
  const reais = Math.floor(v);
  let cent = Math.round((v - reais) * 100);
  if (cent >= 100) { cent -= 100; }
  let s = '';
  if (reais > 0) s = numExtenso(reais) + (reais === 1 ? ' real' : ' reais');
  if (cent > 0) s += (reais > 0 ? ' e ' : '') + numExtenso(cent) + (cent === 1 ? ' centavo' : ' centavos');
  if (!s) s = 'zero reais';
  return s.charAt(0).toUpperCase() + s.slice(1) + '.';
}

/* ---- Recálculo automático ---- */
function recalcular() {
  const c = parseBR(document.getElementById('combustivel').value);
  const a = parseBR(document.getElementById('alimentacao').value);
  const total = c + a;
  document.getElementById('valor_total').value = fmtBR(total);
  document.getElementById('valor_total_display').textContent = fmtBR(total);
  document.getElementById('extenso_display').textContent = extensoReais(total);
}

/* Inicializa com a data de hoje no campo data */
document.addEventListener('DOMContentLoaded', () => {
  const hoje = new Date();
  const iso = hoje.toISOString().slice(0, 10);
  document.getElementById('data').value = iso;
  document.getElementById('data_l').value = iso;
  // Pré-preenche o período do lote: início = primeiro dia do mês atual,
  // final = hoje. O usuário pode alterar livremente.
  const primeiroMes = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
  document.getElementById('periodo_inicio').value = primeiroMes.toISOString().slice(0, 10);
  document.getElementById('periodo_final').value = iso;
  recalcular();
});

/* ---- Dropdown customizado de Empresa ----
   Substitui o <select> pra permitir mostrar logo + nome em cada opção.
   Mantém um input hidden "empresa" com o valor escolhido. */
(function () {
  // Bloqueia o submit se nenhuma empresa foi escolhida (o <select required>
  // não funciona porque virou input hidden).
  const form = document.getElementById('formRecibo');
  if (form) {
    form.addEventListener('submit', e => {
      const emp = document.getElementById('empresa').value;
      if (!emp) {
        e.preventDefault();
        document.getElementById('empDdBtn').focus();
        alert('Selecione a empresa antes de gerar o recibo.');
      }
    });
  }

  const dd      = document.getElementById('empDd');
  const btn     = document.getElementById('empDdBtn');
  const list    = document.getElementById('empDdList');
  const hidden  = document.getElementById('empresa');
  const lbl     = document.getElementById('empDdLabel');
  const preview = document.getElementById('empDdLogo');
  if (!dd) return;

  function abrir() {
    dd.dataset.open = 'true';
    btn.setAttribute('aria-expanded', 'true');
    const atual = list.querySelector('[aria-selected="true"]');
    if (atual) atual.focus();
  }
  function fechar() {
    dd.dataset.open = 'false';
    btn.setAttribute('aria-expanded', 'false');
  }
  function toggle() { dd.dataset.open === 'true' ? fechar() : abrir(); }

  function selecionar(item) {
    const valor = item.dataset.value || '';
    const texto = item.querySelector('.emp-dd-nome').textContent;
    // Pega o estilo da logo do item pra mostrar no botão
    const logo  = item.querySelector('.emp-dd-logo');
    const bg    = logo.style.backgroundImage;
    const isFs  = logo.classList.contains('emp-dd-logo-fs');
    const isVazio = logo.classList.contains('emp-dd-logo-vazio');

    hidden.value = valor;
    lbl.textContent = valor === '' ? '— Selecione a empresa —' : texto;

    // Atualiza a logo exibida no botão
    preview.className = 'emp-dd-logo' + (isFs ? ' emp-dd-logo-fs' : '') + (isVazio ? ' emp-dd-logo-vazio' : '');
    preview.style.backgroundImage = bg || '';
    preview.textContent = isFs ? 'FS' : (isVazio ? '' : '');

    // Marca o item selecionado
    list.querySelectorAll('.emp-dd-item').forEach(it => {
      it.setAttribute('aria-selected', it === item ? 'true' : 'false');
    });

    fechar();
    btn.focus();
  }

  btn.addEventListener('click', toggle);
  list.addEventListener('click', e => {
    const item = e.target.closest('.emp-dd-item');
    if (item) selecionar(item);
  });

  // Teclado: setas pra navegar, Enter pra selecionar, Esc pra fechar
  btn.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      abrir();
    }
  });
  list.addEventListener('keydown', e => {
    const items = [...list.querySelectorAll('.emp-dd-item')];
    const idx = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      items[(idx + 1) % items.length].focus();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      items[(idx - 1 + items.length) % items.length].focus();
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      if (idx >= 0) selecionar(items[idx]);
    } else if (e.key === 'Escape') {
      fechar();
      btn.focus();
    } else if (e.key === 'Tab') {
      fechar();
    }
  });

  // Fecha ao clicar fora
  document.addEventListener('click', e => {
    if (!dd.contains(e.target)) fechar();
  });
})();

/* ---- Imprimir direto: monta um form temporário com target=_blank,
   aciona=preview, e dispara o submit. A página de destino abre com
   window.print() automático (janela de seleção de impressora do navegador) ---- */
function imprimirDireto() {
  const formOrig = document.getElementById('formRecibo');
  // Validação rápida: exige nome e pelo menos um valor > 0
  const nome = document.getElementById('nome').value.trim();
  const c = parseBR(document.getElementById('combustivel').value);
  const a = parseBR(document.getElementById('alimentacao').value);
  if (!nome) {
    alert('Preencha o nome do funcionário antes de imprimir.');
    document.getElementById('nome').focus();
    return;
  }
  if (c + a <= 0) {
    alert('Informe o valor do Auxílio Combustível ou do Vale Alimentação.');
    return;
  }

  // Cria um form novo (não usamos o form original diretamente para evitar
  // que o required do navegador bloqueie o submit programático).
  const f = document.createElement('form');
  f.method = 'POST';
  f.action = 'gerar.php?acao=preview&autoPrint=1';
  f.target = '_blank';
  // Copia todos os campos do form original lendo o .value atual
  const campos = formOrig.querySelectorAll('input, select, textarea');
  for (const c of campos) {
    if (!c.name || c.type === 'submit' || c.type === 'button' || c.type === 'file') continue;
    if (c.name === 'acao') continue;
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = c.name;
    inp.value = c.value || '';
    f.appendChild(inp);
  }
  // Acao real que o gerar.php deve processar
  const acao = document.createElement('input');
  acao.type = 'hidden'; acao.name = 'acao'; acao.value = 'html';
  f.appendChild(acao);
  // Formato html (gera o HTML para impressão)
  const fmt = document.createElement('input');
  fmt.type = 'hidden'; fmt.name = 'formato'; fmt.value = 'html';
  f.appendChild(fmt);
  document.body.appendChild(f);
  f.submit();
  // Remove o form depois
  setTimeout(() => f.remove(), 1000);
}
</script>
</body>
</html>