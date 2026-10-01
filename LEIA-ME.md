# Gerador de Recibos

Sistema que monta recibos a partir de um formulário, **sobrescrevendo o
`DOCX modelo.docx`** automaticamente. O usuário preenche os campos, o
sistema **calcula o valor total** (Combustível + Alimentação), **gera o valor
por extenso** e produz o recibo pronto em **DOCX** (ou HTML para imprimir /
salvar como PDF) preservando 100% do layout do modelo (imagem do cabeçalho,
fontes, linhas de assinatura, espaçamentos). Não há edição manual de
documentos.

## Arquivos

| Arquivo            | Função                                                                  |
|--------------------|-------------------------------------------------------------------------|
| `index.php`        | Formulário (recibo único + importação em lote)                          |
| `gerar.php`        | Calcula o total, gera o extenso e produz o DOCX/HTML/ZIP a partir do modelo |
| `modelo.php`       | Layout fixo do recibo (HTML) e funções de formatação                    |
| `extenso.php`      | Conversão de números para texto (português do Brasil)                  |
| `testar.php`       | Página de diagnóstico do valor por extenso                              |
| `DOCX modelo.docx` | **Modelo-base do recibo** — `gerar.php` abre, substitui os placeholders e devolve |

## Como rodar (Windows)

É necessário ter o **PHP 7.4+** (com a extensão `zip` habilitada — já vem ativa
no PHP padrão do Windows e no XAMPP).

### Opção A — PHP embutido (mais simples)

1. Instale o PHP (ex.: baixe em <https://windows.php.net/download/> e adicione
   a pasta à variável de ambiente `PATH`; ou instale o XAMPP).
2. Na pasta do projeto, abra um terminal e rode:

   ```powershell
   php -S localhost:8000
   ```

3. Acesse <http://localhost:8000/> no navegador.

### Opção B — XAMPP

Copie a pasta do projeto para `C:\xampp\htdocs\recibos` e acesse
<http://localhost/recibos/>.

## Uso

### Recibo único
Preencha **Empresa** (lista), Período, Data, Nome, CPF, Auxílio Combustível e
Vale Alimentação. O **Valor Total** e o **valor por extenso** são calculados
automaticamente enquanto você digita.

#### Empresas suportadas
O sistema usa o mesmo `DOCX modelo.docx` como base e adapta o cabeçalho
conforme a empresa selecionada. **Cada empresa tem sua própria logo**
embutida no DOCX gerado (substituindo o `word/media/image1.jpeg`
internamente, sem alterar as relações do arquivo).

| Chave        | Nome exibido                  | Logo                |
|--------------|-------------------------------|---------------------|
| `fs`         | FS Administradora de Cartões  | Logo azul do modelo |
| `clinica`    | Clínica Saúde                 | `WhatsApp Image ... 8.52.03 AM.jpeg` (Clínica Fidelidade Saúde) |
| `laboratorio`| Laboratório                   | `WhatsApp Image ... 8.51.33 AM.jpeg` (Laboratório El Kadri) |

O **Local (cidade)** continua único — o mesmo campo vale para todas as
empresas. A proporção de cada logo é preservada dentro de uma faixa baixa
(0,9 cm de altura), limitada a 16 cm de largura.

Para acrescentar uma empresa nova: copie o JPEG na pasta do projeto e
adicione a entrada em `EMPRESAS` no topo de `gerar.php` (com a chave, o
rótulo e o caminho do arquivo). A logo será embarcada automaticamente.

- **Gerar e baixar DOCX** → baixa o recibo em Word (abre no Word; pode ser
  impresso/salvo como PDF de lá).
- **Pré-visualizar** → mostra o recibo na tela; use `Ctrl+P` → "Salvar como PDF"
  para gerar o PDF pelo navegador.

### Lote (XLSX / CSV)

**XLSX** é o caminho recomendado — mantém as seções por empresa
("Funcionários FS", "Funcionários Clínica", "Funcionários laboratório").
A seção "Fidelidade" é automaticamente ignorada. Colunas reconhecidas:

| Coluna | Conteúdo                        | Usado em            |
|--------|---------------------------------|---------------------|
| A      | Nome do funcionário             | sempre              |
| B (VT) | Vale Transporte                 | **ignorado**        |
| C      | Auxílio Combustível (R$)        | Recibo e Comprovante|
| D      | Vale Alimentação (R$)           | Recibo e Comprovante|
| E      | AJ. CUSTO (R$)                  | só no Comprovante   |
| F      | TOTAL (calculado pela planilha) | **ignorado**        |

**CSV** também é aceito (legado). A primeira linha é o cabeçalho. As colunas
são reconhecidas pelo nome (`Nome`, `CPF`, `Combustível`, `Alimentação`,
`AJ. Custo`):

```
Nome;CPF;Combustível;Alimentação;AJ. Custo
João;111.222.333-44;257,40;207,90;0
Maria;222.333.444-55;300,00;150,00;500,00
José;333.444.555-66;280,00;180,00;0
```

Delimitadores aceitos: `;` `,` `tab` `|`.

**Fluxo de prévia**: ao enviar a planilha, o sistema mostra uma tela com o
resumo por empresa (Qtd, Auxílio, VA, AJ. CUSTO, total Recibo, total
Comprovante) e dois botões grandes:

- **Gerar Recibos (.zip)** — gera um DOCX por funcionário com Auxílio + VA
  (ignora AJ. CUSTO). Arquivo: `recibos_lote.zip`.
- **Gerar Comprovantes (.zip)** — gera um DOCX por funcionário com
  Auxílio + VA + AJ. CUSTO somados no total. Layout idêntico ao recibo,
  apenas com o título "COMPROVANTE DE PAGAMENTO" e a 3ª linha na tabela.
  Arquivo: `comprovantes_lote.zip`.

Linhas com Auxílio + VA + AJ. CUSTO = 0 (ex.: "contrato congelado") são
puladas automaticamente. Empresa, Período, Data e Local são compartilhados
em todos os documentos do lote.

## Validação do extenso
Acesse `testar.php` para ver uma tabela com vários valores e seus respectivos
extensos, permitindo conferir a corretude da conversão.

## Observações
- O **Valor Total nunca é digitado**: é sempre calculado como
  `Combustível + Alimentação`, tanto no navegador (prévia) quanto no servidor
  (valor autoritativo).
- O valor por extenso é gerado pelo servidor, garantindo coerência total com o
  valor numérico.
- A geração de DOCX é **nativa** (via `ZipArchive`), sem Composer e sem
  bibliotecas externas.